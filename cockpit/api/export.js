const { getShops } = require('../lib/shops.js');
const { resolveUser } = require('../lib/auth.js');
const { ensureSchema, allVouchers } = require('../lib/db.js');
const { toCSV } = require('../lib/csv.js');
const { exportHeader, recordToRow } = require('../lib/voucher_csv.js');

// Utalványok exportja a kanonikus CSV-formátumban (a felhasználó egységeire szűrve).
module.exports = async (req, res) => {
  const user = await resolveUser(req);
  if (!user) { res.status(401).json({ error: 'auth' }); return; }

  try {
    await ensureSchema();
    let rows = await allVouchers();

    const n = u => String(u == null ? '' : u).trim().toLowerCase();
    if (user.role !== 'superadmin') {
      const set = new Set((user.units || []).map(n));
      rows = rows.filter(v => set.has(n(v.unit)));
    }
    // Opcionális egység-szűrő (?unit=casa) — csak a jogosultak közül.
    const only = n(req.query && req.query.unit);
    if (only) rows = rows.filter(v => n(v.unit) === only);

    // Opcionális időszak-szűrő a vásárlás dátumára (?from=YYYY-MM-DD&to=YYYY-MM-DD).
    const cd = v => {
      const c = v.created_at;
      return c instanceof Date ? c.toISOString().slice(0, 10) : String(c || '').slice(0, 10);
    };
    const from = req.query && req.query.from ? String(req.query.from).slice(0, 10) : '';
    const to = req.query && req.query.to ? String(req.query.to).slice(0, 10) : '';
    if (from) rows = rows.filter(v => { const d = cd(v); return d && d >= from; });
    if (to) rows = rows.filter(v => { const d = cd(v); return d && d <= to; });

    const names = {};
    getShops().forEach(s => { names[n(s.slug)] = String(s.name || s.slug).replace(" Pomo d'Oro", ''); });
    const unitName = slug => names[n(slug)] || slug;

    const out = [exportHeader()];
    for (const v of rows) out.push(recordToRow(v, unitName(v.unit)));

    const stamp = new Date().toISOString().slice(0, 10);
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader('Content-Disposition', `attachment; filename="pomodoro-utalvanyok-${stamp}.csv"`);
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).send(toCSV(out, ';', true));
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
