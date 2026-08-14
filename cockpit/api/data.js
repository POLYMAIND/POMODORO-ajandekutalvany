const { getShops, isAuthed, shopFetch } = require('../lib/shops.js');

// Összegyűjti minden bolt rendeléseit és utalványait (közel valós idejű pollinghoz).
module.exports = async (req, res) => {
  if (!isAuthed(req)) { res.status(401).json({ error: 'auth' }); return; }
  const shops = getShops();
  const orders = [];
  const vouchers = [];
  const errors = [];

  await Promise.all(shops.map(async (s) => {
    try {
      const o = await shopFetch(s, '/orders?limit=300');
      if (o.ok && o.json && Array.isArray(o.json.data)) {
        o.json.data.forEach(x => orders.push(Object.assign({}, x, { unit: s.slug })));
      } else {
        errors.push(`${s.slug}: rendelések (${o.status})`);
      }
      const v = await shopFetch(s, '/vouchers?limit=500');
      if (v.ok && v.json && Array.isArray(v.json.data)) {
        v.json.data.forEach(x => vouchers.push(Object.assign({}, x, { unit: s.slug })));
      } else {
        errors.push(`${s.slug}: utalványok (${v.status})`);
      }
    } catch (e) {
      errors.push(`${s.slug}: ${e.message || 'hiba'}`);
    }
  }));

  res.setHeader('Cache-Control', 'no-store');
  res.status(200).json({ orders, vouchers, errors, ts: Date.now() });
};
