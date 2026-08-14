const { isAuthed } = require('../lib/shops.js');
const { ensureSchema, allVouchers } = require('../lib/db.js');

// A vezérlőpult adata a Neon DB-ből jön (a boltok pluginja push-olja ide).
module.exports = async (req, res) => {
  if (!isAuthed(req)) { res.status(401).json({ error: 'auth' }); return; }
  try {
    await ensureSchema();
    const rows = await allVouchers();
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).json({ vouchers: rows, errors: [], ts: Date.now(), source: 'db' });
  } catch (e) {
    res.status(200).json({ vouchers: [], errors: ['DB: ' + String(e && e.message || e)], ts: Date.now() });
  }
};
