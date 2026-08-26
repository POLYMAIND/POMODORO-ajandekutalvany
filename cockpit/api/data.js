const { resolveUser } = require('../lib/auth.js');
const { ensureSchema, allVouchers, recentVoucherLog } = require('../lib/db.js');

// Dátum-normalizálás: a DB Date-objektumait a frontend által várt sztringekre.
function ymd(v) {
  if (!v) return null;
  if (v instanceof Date) return isNaN(v) ? null : v.toISOString().slice(0, 10);
  return String(v).slice(0, 10);
}
function ymdhms(v) {
  if (!v) return null;
  if (v instanceof Date) return isNaN(v) ? null : v.toISOString().slice(0, 19).replace('T', ' ');
  return String(v).replace('T', ' ').slice(0, 19);
}
// +1 év (a vásárlástól számított egy éves érvényesség fallbackje).
function plusOneYear(v) {
  const d = v instanceof Date ? new Date(v) : new Date(String(v).replace(' ', 'T'));
  if (isNaN(d)) return null;
  d.setFullYear(d.getFullYear() + 1);
  return d.toISOString().slice(0, 10);
}
function normalizeVoucher(v) {
  const o = Object.assign({}, v);
  o.created_at = ymdhms(v.created_at);
  o.updated_at = ymdhms(v.updated_at);
  o.paid_at = ymdhms(v.paid_at);
  o.redeemed_at = ymdhms(v.redeemed_at);
  o.ingested_at = ymdhms(v.ingested_at);
  o.reminder_sent_at = ymdhms(v.reminder_sent_at);
  o.valid_from = ymd(v.valid_from);
  // Egy éves érvényesség: ha nincs lejárat, a vásárlástól (vagy valid_fromtól) +1 év.
  o.valid_until = ymd(v.valid_until) || plusOneYear(v.created_at || v.valid_from);
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
