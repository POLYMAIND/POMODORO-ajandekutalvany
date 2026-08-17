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
    const norm = u => String(u == null ? '' : u).trim().toLowerCase();

    if (s.role !== 'superadmin') {
      const set = new Set((s.units || []).map(norm));
      rows = rows.filter(v => set.has(norm(v.unit)));
    }

    res.setHeader('Cache-Control', 'no-store');
    const out = { vouchers: rows, errors: [], ts: Date.now(), source: 'db' };
    if (req.query && req.query.debug) {
      const all = await allVouchers();
      out.debug = {
        role: s.role,
        sessionUnits: s.units || [],
        distinctVoucherUnits: [...new Set(all.map(v => v.unit))],
        totalInDb: all.length,
        visibleToYou: rows.length,
      };
    }
    res.status(200).json(out);
  } catch (e) {
    res.status(200).json({ vouchers: [], errors: ['DB: ' + String(e && e.message || e)], ts: Date.now() });
  }
};
