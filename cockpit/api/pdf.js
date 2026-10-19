const { getShops, shopFetch } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf, upsertVoucherPdfs } = require('../lib/db.js');

// Az utalvány PDF-je. Elsődlegesen abból, amit a bolt pluginja feltöltött;
// ha az nincs meg (régi, importált utalványok), menet közben lekérjük a bolttól
// és el is tesszük — így minden utalvány PDF-je legfeljebb egyszer utazik.
//
// Csak bejelentkezve és csak a saját egység utalványára.

function esc(s) {
  return String(s == null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// A gomb egy sima hivatkozás, tehát a böngésző IDE navigál: ilyenkor nyers
// JSON helyett olvasható oldalt adunk. Gépi hívónak marad a JSON.
function wantsHtml(req) {
  return String((req.headers && req.headers.accept) || '').includes('text/html');
}

function fail(req, res, status, title, detail, serial) {
  if (!wantsHtml(req)) {
    res.status(status).json({ error: title + (detail ? ' ' + detail : '') });
    return;
  }
  const html = '<!doctype html><html lang="hu"><head><meta charset="utf-8">' +
    '<meta name="viewport" content="width=device-width, initial-scale=1">' +
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

// A bolt PDF-végpontja (class-pgv-rest.php) a push-titokkal hitelesít, nem az
// api-kulccsal. A shopFetch két menetben próbálkozik (fejléc, majd query) a
// tárhelyek bot-védelme miatt — ezért a titkot mindkét helyen átadjuk.
async function fetchFromShop(shop, serial) {
  const secret = process.env.INGEST_SECRET;
  if (!secret) return { reason: 'config', detail: 'A vezérlőpulton nincs beállítva az ingest titok.' };
  if (!shop.url) return { reason: 'config', detail: 'Ehhez az egységhez nincs bolt-cím beállítva.' };

  const path = '/voucher-pdf?serial=' + encodeURIComponent(serial) +
    '&secret=' + encodeURIComponent(secret);
  let r;
  try {
    r = await shopFetch(shop, path, { headers: { 'x-ingest-secret': secret } });
  } catch (e) {
    // A technikai ok a naplóba való, nem a pult mögé.
    return { reason: 'unreachable', log: String((e && e.message) || e) };
  }
  if (r && r.ok && r.json && r.json.pdf_base64) return { b64: String(r.json.pdf_base64) };

  const status = r && r.status;
  if (status === 404) return { reason: 'not_in_shop' };
  if (status === 409) return { reason: 'pdf_off' };
  if (status === 401 || status === 403) return { reason: 'auth' };
  return { reason: 'unreachable', detail: status ? ('A bolt ' + status + ' választ adott.') : '', log: 'status=' + status };
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

    let b64 = await getVoucherPdf(shop.slug, serial);

    if (!b64) {
      // Nincs feltöltve — megkérdezzük a boltot. A régi, importált utalványoknál
      // a bolt PDF-végpontja (a felküldéssel ellentétben) nem hagyja ki őket.
      const got = await fetchFromShop(shop, serial);
      if (got.b64) {
        b64 = got.b64;
        // Eltesszük, hogy legközelebb azonnal jöjjön — és akkor is, ha a bolt
        // épp nem elérhető. A tárolás hibája ne akadályozza a kiszolgálást.
        try {
          await upsertVoucherPdfs([{ unit: shop.slug, serial, pdf_base64: b64 }]);
        } catch (e) {
          console.log('[pdf cache]', JSON.stringify({ unit: shop.slug, serial, error: String((e && e.message) || e) }));
        }
      } else {
        console.log('[pdf miss]', JSON.stringify({ unit: shop.slug, serial, reason: got.reason, log: got.log || null }));
        const msgs = {
          not_in_shop: [
            'Ehhez az utalványhoz nem készíthető PDF',
            'Ez az utalvány a korábbi rendszerből került a vezérlőpultba, a boltban nincs meg — ezért a bolt sem tud PDF-et készíteni hozzá. A vendégnél a régi rendszerben kiállított utalvány van.',
          ],
          pdf_off: [
            'A boltban ki van kapcsolva a PDF',
            'A bolt beállításainál kapcsold be a beépített utalvány-PDF-et, utána ez a gomb működni fog.',
          ],
          auth: [
            'A bolt elutasította a kérést',
            'Nem egyezik az ingest titok a bolt beállításaiban és a vezérlőpulton. A bolt Beállítások → Központi vezérlőpult résznél ellenőrizhető.',
          ],
          config: ['Hiányzó beállítás', got.detail || ''],
          unreachable: [
            'A bolt most nem válaszol',
            ((got.detail ? got.detail + ' ' : '') +
              'A PDF a boltból jönne, de a kérés nem jutott át. Próbáld újra később; ha tartósan így marad, a bolt Beállítások → „Kapcsolat tesztelése" gombja megmutatja, hol akad el.').trim(),
          ],
        };
        const m = msgs[got.reason] || msgs.unreachable;
        fail(req, res, 404, m[0], m[1], serial);
        return;
      }
    }

    const buf = Buffer.from(b64, 'base64');
    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', 'inline; filename="ajandekutalvany-' + serial.replace(/[^A-Za-z0-9\-_]/g, '') + '.pdf"');
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).send(buf);
  } catch (e) {
    fail(req, res, 500, 'Nem sikerült megnyitni a PDF-et', String((e && e.message) || e), serial);
  }
};
