const { readSession } = require('../lib/auth.js');
const { ensureSchema, allVouchers } = require('../lib/db.js');

// A vezérlőpult adata a Neon DB-ből jön (a boltok pluginja push-olja ide).
// A nem-superadmin felhasználó csak a saját egysége(i) utalványait kapja meg.
module.exports = async (req, res) => {
  const s = readSession(req);
  if (!s) { res.status(401).json({ error: 'auth' }); return; }
  try {
    await ensureSchema();
    let rows = await allVouchers();
    if (s.role !== 'superadmin') {
      const set = new Set(s.units || []);
      rows = rows.filter(v => set.has(v.unit));
    }
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).json({ vouchers: rows, errors: [], ts: Date.now(), source: 'db' });
  } catch (e) {
    res.status(200).json({ vouchers: [], errors: ['DB: ' + String(e && e.message || e)], ts: Date.now() });
  }
};
