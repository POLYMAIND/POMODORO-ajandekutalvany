const { resolveUser } = require('../lib/auth.js');
const { ensureSchema, allVouchers, recentVoucherLog } = require('../lib/db.js');

// Dátum-segédek és a hiányzó dátumok levezetése — közösen az exporttal.
const { ymd, ymdhms, effectiveValidFrom, effectiveValidUntil } = require('../lib/dates.js');
function normalizeVoucher(v) {
  const o = Object.assign({}, v);
  o.created_at = ymdhms(v.created_at);
  o.updated_at = ymdhms(v.updated_at);
  o.paid_at = ymdhms(v.paid_at);
  o.redeemed_at = ymdhms(v.redeemed_at);
  o.ingested_at = ymdhms(v.ingested_at);
  o.reminder_sent_at = ymdhms(v.reminder_sent_at);
  // Az érvényesség kezdete definíció szerint a vásárlás napja; a vége a
  // vásárlástól számított időszak vége. Amit az import nem hozott, azt a
  // meglévő dátumokból vezetjük le.
  o.valid_from = effectiveValidFrom(v);
  o.valid_until = effectiveValidUntil(v);
  return o;
}

// A vezérlőpult adata a Neon DB-ből jön (a boltok pluginja push-olja ide).
// A nem-superadmin felhasználó csak a saját egysége(i) utalványait kapja meg.
module.exports = async (req, res) => {
  const s = await resolveUser(req);
  if (!s) { res.status(401).json({ error: 'auth' }); return; }
  try {
    await ensureSchema();
    let rows = await allVouchers();
    const norm = u => String(u == null ? '' : u).trim().toLowerCase();
    if (s.role !== 'superadmin') {
      const set = new Set((s.units || []).map(norm));
      rows = rows.filter(v => set.has(norm(v.unit)));
    }
    rows = rows.map(normalizeVoucher);

    // Visszaállítás-napló — hogy a beváltásból visszavett tételek követhetők legyenek.
    // Csak az 'unredeem' sorok jönnek le (ritkák), így a 8 másodperces poll marad könnyű.
    let log = [];
    try {
      log = await recentVoucherLog(365, 'unredeem');
      if (s.role !== 'superadmin') {
        const set = new Set((s.units || []).map(norm));
        log = log.filter(r => set.has(norm(r.unit)));
      }
      log = log.map(r => Object.assign({}, r, {
        created_at: ymdhms(r.created_at),
        prev_redeemed_at: ymdhms(r.prev_redeemed_at),
      }));
    } catch (e) { log = []; }

    res.setHeader('Cache-Control', 'no-store');
    res.status(200).json({ vouchers: rows, log, errors: [], ts: Date.now(), source: 'db' });
  } catch (e) {
    res.status(200).json({ vouchers: [], log: [], errors: ['DB: ' + String(e && e.message || e)], ts: Date.now() });
  }
};
