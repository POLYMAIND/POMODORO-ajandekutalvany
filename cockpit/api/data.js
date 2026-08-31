const { resolveUser, authFail } = require('../lib/auth.js');
const { ensureSchema, listVouchers, recentVoucherLog, dataVersion } = require('../lib/db.js');
const { effectiveLabel } = require('../lib/voucher_csv.js');

// Dátum-segédek és a hiányzó dátumok levezetése — közösen az exporttal.
const { ymd, ymdhms, effectiveValidFrom, effectiveValidUntil, effectivePurchaseDay } = require('../lib/dates.js');
function normalizeVoucher(v) {
  const o = Object.assign({}, v);
  o.created_at = ymdhms(v.created_at);
  o.redeemed_at = ymdhms(v.redeemed_at);
  o.reminder_sent_at = ymdhms(v.reminder_sent_at);
  // Az érvényesség kezdete definíció szerint a vásárlás napja; a vége a
  // vásárlástól számított időszak vége. Amit az import nem hozott, azt a
  // meglévő dátumokból vezetjük le.
  o.valid_from = effectiveValidFrom(v);
  o.valid_until = effectiveValidUntil(v);
  // A vásárlás napja a listákhoz és a szűrőkhöz: ahol az import nem hozta a
  // pontos időpontot, ott az érvényességből visszaszámolt nap. A felület
  // megjelöli, hogy ez levezetett érték, a created_at pedig üres marad.
  o.label = effectiveLabel(v);
  o.purchase_day = effectivePurchaseDay(v);
  o.purchase_day_derived = !ymd(v.created_at) && !!o.purchase_day;
  return o;
}

// A vezérlőpult adata a Neon DB-ből jön (a boltok pluginja push-olja ide).
// A nem-superadmin felhasználó csak a saját egysége(i) utalványait kapja meg.
// Rövid életű memória-gyorsítótár: a Vercel egy futó példánya több fül és
// több felhasználó kérését is kiszolgálja, így nem kérdezzük meg mindegyikért
// külön az adatbázist.
let CACHE = null; // { v, at, rows, log }
const CACHE_MS = 5000;

module.exports = async (req, res) => {
  const s = await resolveUser(req);
  if (!s) { authFail(req, res); return; }
  try {
    await ensureSchema();

    // Ha a kliens ismert állapotot küld és semmi nem változott, nem küldjük
    // újra az egész listát — ez a napi adatforgalom nagy részét megtakarítja.
    const known = String((req.query && req.query.v) || '');
    let dbv = '';
    try { dbv = await dataVersion(); } catch (e) { dbv = ''; }
    // A verzióba a felhasználó jogosultsága is beleszámít: ha az admin átállítja
    // valakinek az egységeit, a kliens ne ragadjon a régi, szűkebb listán.
    const version = dbv ? dbv + '#' + s.role + ':' + (s.units || []).join(',') : '';
    if (version && known && known === version) {
      res.setHeader('Cache-Control', 'no-store');
      res.status(200).json({ unchanged: true, v: version, ts: Date.now() });
      return;
    }

    if (CACHE && CACHE.v && CACHE.v === dbv && Date.now() - CACHE.at < CACHE_MS) {
      res.setHeader('Cache-Control', 'no-store');
      res.status(200).json({ vouchers: filterUnits(CACHE.rows, s), log: filterUnits(CACHE.log, s),
        errors: [], v: version, ts: Date.now(), source: 'db' });
      return;
    }

    let rows = await listVouchers();
    rows = rows.map(normalizeVoucher);

    // Visszaállítás- és törlés-napló — hogy a beváltásból visszavett és a
    // véglegesen törölt tételek is követhetők maradjanak. Mindkettő ritka, így
    // a 8 másodperces poll könnyű marad.
    let log = [];
    try {
      log = await recentVoucherLog(365, ['unredeem', 'delete', 'undelete', 'retime']);
      log = log.map(r => Object.assign({}, r, {
        created_at: ymdhms(r.created_at),
        prev_redeemed_at: ymdhms(r.prev_redeemed_at),
      }));
    } catch (e) { log = []; }

    CACHE = { v: dbv, at: Date.now(), rows, log };
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).json({ vouchers: filterUnits(rows, s), log: filterUnits(log, s),
      errors: [], v: version, ts: Date.now(), source: 'db' });
  } catch (e) {
    res.status(200).json({ vouchers: [], log: [], errors: ['DB: ' + String(e && e.message || e)], ts: Date.now() });
  }
};

// A nem-superadmin felhasználó csak a saját egysége(i) tételeit kapja meg.
// A szűrés a gyorsítótár UTÁN fut, hogy a tárolt adat felhasználó-független legyen.
function filterUnits(list, user) {
  if (!list || !list.length) return [];
  if (user.role === 'superadmin') return list;
  const norm = u => String(u == null ? '' : u).trim().toLowerCase();
  const set = new Set((user.units || []).map(norm));
  return list.filter(r => set.has(norm(r.unit)));
}
