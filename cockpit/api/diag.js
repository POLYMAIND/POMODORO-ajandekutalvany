const { getShops, isAuthed } = require('../lib/shops.js');

// Diagnosztika: megmutatja, mit válaszol a bolt a szerver-kérésre (403 forrása).
module.exports = async (req, res) => {
  if (!isAuthed(req)) { res.status(401).json({ error: 'auth' }); return; }
  const slug = (req.query && req.query.shop) || (getShops()[0] && getShops()[0].slug);
  const shop = getShops().find(s => s.slug === slug) || getShops()[0];
  if (!shop) { res.status(404).json({ error: 'Nincs bolt a SHOPS-ban.' }); return; }

  const base = shop.url.replace(/\/+$/, '') + '/wp-json/pgv/v1/vouchers?limit=1';
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
  res.setHeader('Cache-Control', 'no-store');
  res.status(200).json(out);
};
