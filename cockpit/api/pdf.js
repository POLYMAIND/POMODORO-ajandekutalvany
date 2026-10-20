const { getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf, getConfig } = require('../lib/db.js');
const { effectiveValidUntil } = require('../lib/dates.js');

// Az utalvány PDF-je.
//
// Ha a bolt feltöltötte a kiküldött PDF-et, azt adjuk vissza — ez az „igazi”
// utalvány, bitre az, amit a vásárló kapott.
//
// Ha nincs feltöltve (jellemzően a korábbi rendszerből importált utalványok:
// ezeket nem ezen a rendszeren vették, a bolt nem is tud róluk), akkor egy
// nyomtatásra kész oldalt szolgálunk ki a nyilvántartásban lévő adatokból.
// A böngésző „Nyomtatás → Mentés PDF-be” funkciója adja a fájlt; így nem kell
// második PDF-generátort fenntartani, és a magyar ékezetek meg az emoji is
// biztosan jól jelennek meg, mert a böngésző rajzolja őket.
//
// Csak bejelentkezve és csak a saját egység utalványára.

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function money(n) {
  return String(Math.round(Number(n) || 0)).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
}

// A gomb egy sima hivatkozás, tehát a böngésző IDE navigál: ilyenkor nyers
// JSON helyett olvasható oldalt adunk. Gépi hívónak marad a JSON.
function wantsHtml(req) {
  return String((req.headers && req.headers.accept) || '').includes('text/html');
}

const PAGE_HEAD = '<!doctype html><html lang="hu"><head><meta charset="utf-8">' +
  '<meta name="viewport" content="width=device-width, initial-scale=1">' +
  '<meta name="robots" content="noindex, nofollow">';

function fail(req, res, status, title, detail, serial) {
  if (!wantsHtml(req)) {
    res.status(status).json({ error: title + (detail ? ' ' + detail : '') });
    return;
  }
  const html = PAGE_HEAD +
    '<title>Nincs elérhető PDF</title><style>' +
    'body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;' +
    'background:#efe9df;color:#221d18;' +
    'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;' +
    'font-size:15px;line-height:1.55}' +
    '.c{background:#fff;border:1px solid #e4dccd;border-radius:14px;max-width:460px;padding:26px 28px;' +
    'box-shadow:0 1px 2px rgba(40,30,18,.05),0 8px 24px -12px rgba(40,30,18,.18)}' +
    'h1{margin:0 0 10px;font-size:17px;font-weight:650;letter-spacing:-.01em}' +
    'p{margin:0 0 12px;color:#6d6357}p:last-child{margin-bottom:0}' +
    '.s{font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace;font-size:13px;color:#9a9084}' +
    '</style></head><body><div class="c">' +
    '<h1>' + esc(title) + '</h1>' +
    (detail ? '<p>' + esc(detail) + '</p>' : '') +
    (serial ? '<p class="s">' + esc(serial) + '</p>' : '') +
    '<p>Ezt a lapot bezárhatod — a vezérlőpult a másik lapon nyitva maradt.</p>' +
    '</div></body></html>';
  res.setHeader('Content-Type', 'text/html; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store');
  res.status(status).send(html);
}

// Ékezet- és kisbetű-érzéketlen összevetés a nevekhez.
function fold(s) {
  return String(s || '').normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
}

// A korábbi rendszerben a vásárló gyakran az üzenetbe is beleírta a
// megszólítást, így a kártyán kétszer jelenne meg. Ha ugyanannak szól, mint
// akinek az utalvány, leválasztjuk az üzenet elejéről.
// Ha a megajándékozott mező üres, de az üzenet megszólítással kezdődik, azt
// léptetjük elő külön sorrá — így a lap akkor is rendezett.
function splitGreeting(message, recipientName) {
  const msg = String(message || '').replace(/\s+/g, ' ').trim();
  const rec = String(recipientName || '').trim();
  // Legfeljebb 60 karakternyi név, hogy egy egész mondatot ne nyeljen le.
  const m = msg.match(/^kedves\s+([^!?.]{1,60})[!?]\s*/i);
  if (!m) return { name: rec, rest: msg };

  const inMsg = m[1].trim();
  const rest = msg.slice(m[0].length).trim();
  if (!rec) return { name: inMsg, rest: rest };

  const a = fold(rec), b = fold(inMsg);
  // Részleges egyezés is elég: a mezőben „Zsolt”, az üzenetben „Zsolt és Judit”.
  if (a && b && (b.includes(a) || a.includes(b))) return { name: rec, rest: rest };
  // Más névnek szól — ez nem ismétlés, maradjon mindkettő.
  return { name: rec, rest: msg };
}

// Az üzenet tördelése az éles PDF szabálya szerint: 34 karakter, legfeljebb
// 5 sor (class-pgv-pdf.php MSG_WRAP_CHARS / MSG_MAX_LINES).
function wrapMessage(txt) {
  const s = String(txt || '').replace(/\s+/g, ' ').trim();
  if (!s) return [];
  const words = s.split(' ');
  const lines = [];
  let cur = '';
  for (const w of words) {
    if (!cur) cur = w;
    else if ((cur + ' ' + w).length <= 34) cur += ' ' + w;
    else { lines.push(cur); cur = w; if (lines.length === 5) break; }
  }
  if (cur && lines.length < 5) lines.push(cur);
  return lines.slice(0, 5);
}

// Az egység beállított utalvány-képei. A bolt pluginja minden felküldéssel
// elküldi a média-URL-eket (lásd /api/ingest), mert a vezérlőpult kiszolgálója
// nem tudja lekérdezni a boltot — a tárhely bot-védelme a bejövő kérést
// elfogja. A képet nem a kiszolgáló tölti le, hanem a kolléga böngészője,
// annak pedig a bolt rendesen kiszolgálja.
async function unitImages(slug) {
  try {
    const m = await getConfig('unit:' + slug);
    const arr = (m && Array.isArray(m.images)) ? m.images : [];
    return arr
      .filter(x => x && /^https?:\/\//i.test(String(x.url || '')))
      .map(x => ({ title: String(x.title || ''), url: String(x.url) }));
  } catch (e) {
    return []; // a kép sosem állhat a nyomtatás útjába
  }
}

// Tartalék kép, ha az egységhez (még) nem ismerünk egyet sem: beágyazott SVG,
// mert az TARTALOM — a böngésző akkor is kinyomtatja, ha a „háttérgrafika” ki
// van kapcsolva, míg egy CSS-háttér ilyenkor lemaradna a papírról.
function cardArt() {
  return '<svg class="pgv-card-art" viewBox="0 0 105 148" preserveAspectRatio="xMidYMid slice" ' +
    'xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">' +
    '<defs><linearGradient id="pgvArt" x1="0" y1="0" x2="0.35" y2="1">' +
    '<stop offset="0" stop-color="#b94e2b"/><stop offset="1" stop-color="#e7a373"/>' +
    '</linearGradient></defs>' +
    '<rect width="105" height="148" fill="url(#pgvArt)"/>' +
    '<circle cx="86" cy="24" r="17" fill="#fff" opacity=".10"/>' +
    '<circle cx="14" cy="128" r="24" fill="#fff" opacity=".07"/>' +
    '<g fill="none" stroke="#fff" stroke-opacity=".85" stroke-width="1.7" ' +
    'stroke-linecap="round" stroke-linejoin="round">' +
      '<circle cx="52.5" cy="74" r="21"/>' +
      '<circle cx="52.5" cy="74" r="13.5"/>' +
      // villa
      '<path d="M16.5 52v8M20.5 52v8M24.5 52v8"/>' +
      '<path d="M15 60h11v2.5a5.5 5.5 0 0 1-5.5 5.5A5.5 5.5 0 0 1 15 62.5z"/>' +
      '<path d="M20.5 68v28"/>' +
      // kés
      '<path d="M85 52c3.2 5.2 3.2 13.4 0 18.4-3.2-5-3.2-13.2 0-18.4z"/>' +
      '<path d="M85 70.4V96"/>' +
    '</g></svg>';
}

// Nyomtatásra kész utalvány. A méretek az éles PDF-generátorból jönnek
// (class-pgv-pdf.php:61-141): A5 fekvő, bal fél kép/halvány mező, jobb oldalon
// a szövegoszlop. Képernyőn 1em = a 720 px-es kártya 1%-a, nyomtatásban
// 1em = 2,1 mm — így mindkettő ugyanazt az elrendezést adja.
function printPage(v, unitName, images) {
  const imgs = Array.isArray(images) ? images : [];
  const greet = splitGreeting(v.message, v.recipient_name);
  const lines = wrapMessage(greet.rest);
  const validUntil = effectiveValidUntil(v);
  const amount = Math.round(Number(v.amount) || 0);

  return PAGE_HEAD +
    '<title>Ajándékutalvány · ' + esc(v.serial) + '</title><style>' +
    ':root{--ink:#221d18}' +
    'body{margin:0;padding:28px 20px 60px;background:#efe9df;color:var(--ink);' +
    'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}' +
    '.wrap{max-width:720px;margin:0 auto}' +
    '.bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:0 0 16px}' +
    '.btn{background:#e2552b;color:#fff;border:0;border-radius:10px;padding:11px 18px;' +
    'font:inherit;font-size:15px;font-weight:650;cursor:pointer}' +
    '.btn:hover{filter:brightness(1.06)}' +
    '.hint{color:#6d6357;font-size:13.5px;margin:0}' +
    /* --- a kártya: az éles PDF méretei --- */
    '.pgv-card{position:relative;display:flex;width:720px;max-width:100%;aspect-ratio:210/148;' +
    'overflow:hidden;border-radius:8px;background:#fff;font-size:7.2px;' +
    'box-shadow:0 1px 3px rgba(0,0,0,.12),0 6px 18px rgba(0,0,0,.08);' +
    'font-family:"Liberation Sans",Helvetica,Arial,sans-serif;line-height:1.2;color:#212121}' +
    '.pgv-card-img{position:relative;flex:0 0 50%;background:#f0efed;line-height:0;overflow:hidden}' +
    '.pgv-card-art,.pgv-card-photo{position:absolute;inset:0;width:100%;height:100%;display:block}' +
    // A kép kitölti a bal felet, a széleit levágva — ahogy az éles PDF is teszi.
    // A tartalék rajz alatta van: ha a kép nem töltődik be, az marad látható.
    '.pgv-card-photo{object-fit:cover;-webkit-print-color-adjust:exact;print-color-adjust:exact}' +
    '.pick{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:14px 0 0}' +
    '.pick b{font-weight:600;font-size:13.5px;color:#6d6357}' +
    '.pick button{width:54px;height:38px;padding:0;border:2px solid transparent;border-radius:8px;' +
    'background:#fff center/cover no-repeat;cursor:pointer;box-shadow:0 0 0 1px #d9d0c0}' +
    '.pick button[aria-pressed="true"]{border-color:#e2552b}' +
    '.pgv-card-body{flex:1 1 50%;display:flex;flex-direction:column;align-items:flex-start;' +
    'padding:7.62em 4.76em 6.2em;min-width:0}' +
    '.pgv-card-unit{color:#e65744;font-weight:700;font-size:2.02em;margin-bottom:2.1em}' +
    '.pgv-card-heading{font-weight:700;font-size:3.19em;letter-spacing:.01em;white-space:nowrap}' +
    '.pgv-card-amount{font-weight:700;font-size:4.7em;margin-top:.5em;white-space:nowrap}' +
    '.pgv-card-greeting{font-size:2.02em;color:#333;margin-top:2.4em}' +
    '.pgv-card-message{font-size:1.76em;line-height:1.49;color:#595959;margin-top:1.1em;white-space:pre-line}' +
    '.pgv-card-foot{position:absolute;left:54.76em;bottom:6.84em}' +
    '.pgv-card-serial{display:block;font-weight:700;font-size:2.02em;color:#212121}' +
    '.pgv-card-valid{display:block;font-size:1.51em;color:#737373;margin-top:1.43em}' +
    // A left/bottom em-je az elem SAJÁT betűméretéhez igazodna, ezért a
    // méretet belső elemre tesszük — így a sor a lábléccel egy vonalban marad.
    '.pgv-card-note{position:absolute;left:54.76em;bottom:2.9em}' +
    '.pgv-card-note span{font-size:1.2em;color:#a9a9a9}' +
    /* --- nyomtatás: pontosan egy A5 fekvő oldal --- */
    '@media print{@page{size:210mm 148mm;margin:0}' +
    'html,body{margin:0;padding:0;background:#fff}' +
    '.wrap{max-width:none;margin:0;padding:0}' +
    '.noprint{display:none!important}' +
    '.pgv-card{width:210mm;height:148mm;max-width:none;aspect-ratio:auto;font-size:2.1mm;' +
    'border-radius:0;box-shadow:none}}' +
    '</style></head><body><div class="wrap">' +
    '<div class="bar noprint">' +
      '<button class="btn" onclick="window.print()">Nyomtatás / Mentés PDF-be</button>' +
      '<p class="hint">Ehhez az utalványhoz nincs kiküldött PDF — ez a példány a ' +
      'nyilvántartás adataiból készült. A nyomtatás párbeszédében válaszd a ' +
      '„Mentés PDF-be” lehetőséget, ha fájlként kell.</p>' +
    '</div>' +
    // Ha az egységnél több kép is be van állítva, a kolléga válthat köztük.
    (imgs.length > 1
      ? '<div class="pick noprint"><b>Kép:</b>' +
        imgs.map((im, i) => '<button type="button" title="' + esc(im.title || ('Kép ' + (i + 1))) +
          '" aria-pressed="' + (i === 0 ? 'true' : 'false') +
          '" style="background-image:url(' + esc(JSON.stringify(im.url)) + ')" ' +
          'data-src="' + esc(im.url) + '"></button>').join('') +
        '</div>'
      : '') +
    '<div class="pgv-card">' +
      '<div class="pgv-card-img">' + cardArt() +
        (imgs.length
          // A böngésző tölti le a bolt médiatárából. Ha nem sikerül (törölt kép,
          // elérhetetlen bolt), a kép eltűnik és alatta marad a tartalék rajz —
          // üres folt így sosem kerül a papírra.
          ? '<img class="pgv-card-photo" id="pgvPhoto" src="' + esc(imgs[0].url) +
            '" alt="" onerror="this.remove()">'
          : '') +
      '</div>' +
      '<div class="pgv-card-body">' +
        '<div class="pgv-card-unit">' + esc(unitName) + '</div>' +
        '<div class="pgv-card-heading">AJÁNDÉKUTALVÁNY</div>' +
        // Nulla összegnél nem írunk „0 Ft”-ot: az hivatalosnak látszó, de hamis
        // utalvány lenne. Ilyenkor inkább nincs összeg-sor.
        (amount > 0 ? '<div class="pgv-card-amount">' + esc(money(amount)) + '</div>' : '') +
        (greet.name ? '<div class="pgv-card-greeting">Kedves ' + esc(greet.name) + '!</div>' : '') +
        (lines.length ? '<div class="pgv-card-message">' + esc(lines.join('\n')) + '</div>' : '') +
        '<div class="pgv-card-foot">' +
          '<span class="pgv-card-serial">Sorszám: ' + esc(v.serial) + '</span>' +
          (validUntil ? '<span class="pgv-card-valid">Érvényes: ' + esc(validUntil) + '</span>' : '') +
        '</div>' +
        '<div class="pgv-card-note"><span>A nyilvántartás alapján kiállított példány</span></div>' +
      '</div>' +
    '</div></div>' +
    (imgs.length > 1
      ? '<script>document.querySelectorAll(".pick button").forEach(function(b){' +
        'b.onclick=function(){var p=document.getElementById("pgvPhoto");' +
        'if(p){p.src=b.dataset.src;}' +
        'document.querySelectorAll(".pick button").forEach(function(x){' +
        'x.setAttribute("aria-pressed",x===b?"true":"false");});};});<\/script>'
      : '') +
    '</body></html>';
}

module.exports = async (req, res) => {
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }

  const q = req.query || {};
  const unit = String(q.unit || '').trim().toLowerCase();
  const serial = String(q.serial || '').trim();
  if (!unit || !serial) { fail(req, res, 400, 'Hiányzó egység vagy sorszám', '', ''); return; }

  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { fail(req, res, 404, 'Ismeretlen egység', '', unit); return; }
  if (!canSeeUnit(user, shop.slug)) {
    fail(req, res, 403, 'Nincs jogosultságod ehhez az egységhez', 'Ez az utalvány egy másik egységhez tartozik.', serial);
    return;
  }

  try {
    await ensureSchema();
    const v = await getVoucher(shop.slug, serial);
    if (!v) {
      fail(req, res, 404, 'Nincs ilyen utalvány', 'Elképzelhető, hogy időközben törölték, vagy új kódot kapott.', serial);
      return;
    }

    const b64 = await getVoucherPdf(shop.slug, serial);
    if (b64) {
      const buf = Buffer.from(b64, 'base64');
      res.setHeader('Content-Type', 'application/pdf');
      res.setHeader('Content-Disposition', 'inline; filename="ajandekutalvany-' + serial.replace(/[^A-Za-z0-9\-_]/g, '') + '.pdf"');
      res.setHeader('Cache-Control', 'no-store');
      res.status(200).send(buf);
      return;
    }

    // Nincs feltöltött PDF. Gépi hívónak ez továbbra is hiba (az emlékeztető
    // és az újraküldés így tudja, hogy nincs mit csatolnia); a böngészőnek
    // viszont a nyomtatható példányt adjuk.
    if (!wantsHtml(req)) {
      res.status(404).json({ error: 'Ehhez az utalványhoz nincs feltöltött PDF.' });
      return;
    }
    res.setHeader('Content-Type', 'text/html; charset=utf-8');
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).send(printPage(v, String(shop.name || shop.slug), await unitImages(shop.slug)));
  } catch (e) {
    fail(req, res, 500, 'Nem sikerült megnyitni a PDF-et', String((e && e.message) || e), serial);
  }
};
