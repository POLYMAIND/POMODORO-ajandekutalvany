const { getShops } = require('../lib/shops.js');
const { resolveUser, authFail } = require('../lib/auth.js');

// Diagnosztika: megmutatja, mit válaszol a bolt a szerver felől érkező kérésre
// (a 403 forrása). Három próbát futtat ugyanarra a boltra:
//   headerMode / queryMode — az olvasó API api-kulccsal, kétféleképpen,
//   pdfMode                — a PDF-végpont az ingest titokkal; ezt használja
//                            az /api/pdf, amikor régi utalványhoz kér PDF-et.
// A titkot és az api-kulcsot soha nem írjuk bele a válaszba.
module.exports = async (req, res) => {
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }
  if (user.role !== 'superadmin') {
    res.status(403).json({ error: 'A diagnosztikát csak központi admin futtathatja.' });
    return;
  }

  const slug = (req.query && req.query.shop) || (getShops()[0] && getShops()[0].slug);
  const shop = getShops().find(s => s.slug === slug) || getShops()[0];
  if (!shop) { res.status(404).json({ error: 'Nincs bolt a SHOPS-ban.' }); return; }

  const root = shop.url.replace(/\/+$/, '') + '/wp-json/pgv/v1';
  const base = root + '/vouchers?limit=1';
  const ua = 'Mozilla/5.0 (compatible; PomodoroCockpit/1.0; +https://polymaind.hu)';
  const probe = async (url, headers) => {
    try {
      const r = await fetch(url, { headers });
      const body = (await r.text()).slice(0, 700);
      return {
        status: r.status,
        server: r.headers.get('server'),
        cfRay: r.headers.get('cf-ray'),
        cfMitigated: r.headers.get('cf-mitigated'),
        contentType: r.headers.get('content-type'),
        body,
      };
    } catch (e) { return { error: String(e && e.message || e) }; }
  };

  const out = { shop: shop.slug, url: shop.url };
  out.headerMode = await probe(base, { 'x-api-key': shop.apiKey, 'accept': 'application/json', 'user-agent': ua });
  out.queryMode = await probe(base + '&api_key=' + encodeURIComponent(shop.apiKey), { 'accept': 'application/json', 'user-agent': ua });

  // A PDF-út próbája — pontosan azzal a hitelesítéssel, amit az /api/pdf használ.
  const secret = process.env.INGEST_SECRET || '';
  out.ingestSecretSet = !!secret;
  if (!secret) {
    out.pdfMode = { skipped: 'A vezérlőpulton nincs beállítva az INGEST_SECRET.' };
  } else {
    const serial = String((req.query && req.query.serial) || 'PROBE');
    const pdfUrl = root + '/voucher-pdf?serial=' + encodeURIComponent(serial) +
      '&secret=' + encodeURIComponent(secret);
    out.pdfMode = await probe(pdfUrl, {
      'x-ingest-secret': secret, 'accept': 'application/json', 'user-agent': ua,
    });
    // A válaszban ne maradjon benne a titok, ha a bolt visszhangozná.
    if (out.pdfMode && typeof out.pdfMode.body === 'string') {
      out.pdfMode.body = out.pdfMode.body.split(secret).join('«titok»');
    }
  }

  res.setHeader('Cache-Control', 'no-store');
  res.status(200).json(out);
};
