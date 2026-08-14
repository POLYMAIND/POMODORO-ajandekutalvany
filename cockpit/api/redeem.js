const { getShops, isAuthed, readBody, shopFetch } = require('../lib/shops.js');

// Egységes kassza: sorszám -> a megfelelő bolt beváltó végpontja.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  if (!isAuthed(req)) { res.status(401).json({ error: 'auth' }); return; }

  const body = await readBody(req);
  const serial = String((body && body.serial) || '').trim();
  if (!serial) { res.status(400).json({ error: 'Hiányzó sorszám.' }); return; }

  const shop = getShops().find(s =>
    serial.toUpperCase().startsWith(String(s.prefix || '').toUpperCase() + '-')
  );
  if (!shop) { res.status(404).json({ error: 'A sorszám alapján nem azonosítható az egység.' }); return; }

  try {
    const r = await shopFetch(shop, '/redeem', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ serial }),
    });
    res.status(r.status).json(r.json || { error: 'Ismeretlen válasz a bolttól.' });
  } catch (e) {
    res.status(500).json({ error: e.message || 'Hálózati hiba.' });
  }
};
