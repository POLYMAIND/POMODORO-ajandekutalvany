const { getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf } = require('../lib/db.js');
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

// Nyomtatásra kész utalvány. A méretek az éles PDF-generátorból jönnek
// (class-pgv-pdf.php:61-141): A5 fekvő, bal fél kép/halvány mező, jobb oldalon
// a szövegoszlop. Képernyőn 1em = a 720 px-es kártya 1%-a, nyomtatásban
// 1em = 2,1 mm — így mindkettő ugyanazt az elrendezést adja.
function printPage(v, unitName) {
  const lines = wrapMessage(v.message);
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
    '.pgv-card-img{flex:0 0 50%;background:#f0efed}' +
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
    '<div class="pgv-card">' +
      '<div class="pgv-card-img"></div>' +
      '<div class="pgv-card-body">' +
        '<div class="pgv-card-unit">' + esc(unitName) + '</div>' +
        '<div class="pgv-card-heading">AJÁNDÉKUTALVÁNY</div>' +
        // Nulla összegnél nem írunk „0 Ft”-ot: az hivatalosnak látszó, de hamis
        // utalvány lenne. Ilyenkor inkább nincs összeg-sor.
        (amount > 0 ? '<div class="pgv-card-amount">' + esc(money(amount)) + '</div>' : '') +
        (v.recipient_name ? '<div class="pgv-card-greeting">Kedves ' + esc(v.recipient_name) + '!</div>' : '') +
        (lines.length ? '<div class="pgv-card-message">' + esc(lines.join('\n')) + '</div>' : '') +
        '<div class="pgv-card-foot">' +
          '<span class="pgv-card-serial">Sorszám: ' + esc(v.serial) + '</span>' +
          (validUntil ? '<span class="pgv-card-valid">Érvényes: ' + esc(validUntil) + '</span>' : '') +
        '</div>' +
        '<div class="pgv-card-note"><span>A nyilvántartás alapján kiállított példány</span></div>' +
      '</div>' +
    '</div></div></body></html>';
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
    res.status(200).send(printPage(v, String(shop.name || shop.slug)));
  } catch (e) {
    fail(req, res, 500, 'Nem sikerült megnyitni a PDF-et', String((e && e.message) || e), serial);
  }
};
