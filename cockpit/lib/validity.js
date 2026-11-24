// Az érvényesség meghosszabbítása — közös segédek (api/validity.js + api/ingest.js).
//
// Az út ugyanaz, mint a névmódosításé (lib/recipient.js): adatlap → /api/validity
// (aláírt jegy, op = 'validity') → a kolléga böngészője megnyitja a bolt
// /pgv/v1/validity-change címét → a bolt AZONNAL átírja az utalványt (lejárt → aktív),
// jegyzetet tesz a WooCommerce-rendelésre, újragenerálja a PDF-et, és felküldi ide
// (/api/ingest) a `change` mezővel (type = 'validity') → itt naplózunk, és lezárjuk a
// folyamatban lévő műveletet.
const crypto = require('crypto');
const { getConfig, setConfig, logVoucherAction, pendingValidityOps } = require('./db.js');

const MAX_REASON = 300;

function opKey(unit, serial) {
  return 'validity_op:' + String(unit).toLowerCase() + ':' + String(serial);
}

// ÉÉÉÉ-HH-NN, valódi naptári nap.
function cleanDate(s) {
  const t = String(s == null ? '' : s).trim();
  if (!/^\d{4}-\d{2}-\d{2}$/.test(t)) return '';
  const d = new Date(t + 'T00:00:00Z');
  return !isNaN(d) && d.toISOString().slice(0, 10) === t ? t : '';
}

function cleanReason(s) {
  return String(s == null ? '' : s)
    .replace(/[\u0000-\u001f\u007f<>]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, MAX_REASON);
}

// A mai nap Budapesten (a rendszer a helyi faliórát tárolja).
function todayBudapest() {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Budapest', year: 'numeric', month: '2-digit', day: '2-digit' })
    .format(new Date());
}

function ymd(v) {
  if (!v) return '';
  if (v instanceof Date) return isNaN(v) ? '' : v.toISOString().slice(0, 10);
  return String(v).slice(0, 10);
}

// Hiba szövege, ha a hosszabbítás nem végezhető el; egyébként ''.
function validate(v, until) {
  if (!until) return 'Adj meg egy érvényes dátumot.';
  if (!['active', 'expired'].includes(String(v.status))) return 'Csak aktív vagy lejárt utalvány hosszabbítható.';
  if (until <= todayBudapest()) return 'Az új lejáratnak a mai napnál későbbinek kell lennie.';
  const cur = ymd(v.valid_until);
  if (cur && until <= cur) return 'Az új lejárat legyen későbbi a jelenleginél (' + cur + ').';
  return '';
}

async function startOp({ unit, serial, oldUntil, until, reason, user, mode }) {
  const nonce = crypto.randomBytes(16).toString('hex');
  const op = {
    nonce, unit: String(unit).toLowerCase(), serial: String(serial),
    old: oldUntil || '', new: until, reason: reason || '', mode,
    user: { id: user && user.id != null ? user.id : null, name: (user && (user.name || user.email)) || '', email: (user && user.email) || '' },
    state: 'pending', created_at: new Date().toISOString(),
  };
  await setConfig(opKey(unit, serial), op);
  return op;
}

async function getOp(unit, serial) {
  return await getConfig(opKey(unit, serial));
}

async function finishOp(op, patch) {
  const next = Object.assign({}, op, patch, { finished_at: new Date().toISOString() });
  await setConfig(opKey(op.unit, op.serial), next);
  return next;
}

function noteFor(oldUntil, until, reason, change) {
  const c = change || {};
  const parts = [(oldUntil || '—') + ' → ' + (until || '—')];
  if (reason) parts.push('indoklás: ' + reason);
  if (c.reactivated) parts.push('lejárt → aktív');
  if (c.order === 'no_order') parts.push('WooCommerce: nincs rendelés');
  else if (c.order === 'noted') parts.push('WooCommerce-rendelés: jegyzet');
  else if (c.order === 'local') parts.push('csak a vezérlőpulton (nincs bolti utalvány)');
  if (c.pdf === true) parts.push('PDF újragenerálva');
  return parts.join(' · ');
}

// A bolt felküldése után: a hosszabbítások naplózása és a függő műveletek lezárása.
// Ha a felküldés elsőre elakadt, és a bolt később a `change` nélkül küldi újra,
// az új dátum egyezése is lezárja a műveletet.
async function applyIngestChanges(vouchers) {
  let logged = 0;
  let ops = {};
  try { ops = await pendingValidityOps(); } catch (e) { ops = {}; }
  for (const v of (vouchers || [])) {
    if (!v || !v.unit || !v.serial) continue;
    const change = (v.change && typeof v.change === 'object' && v.change.type === 'validity') ? v.change : null;
    const op = ops[opKey(v.unit, v.serial)] || null;
    if (!op) continue;
    const until = cleanDate(change ? change.new : v.valid_until);
    const matches = (change && change.nonce && change.nonce === op.nonce) || (!change && until && until === op.new);
    if (!matches) continue;
    try {
      const pdf = typeof v.pdf_base64 === 'string' && v.pdf_base64.length > 0;
      const c = Object.assign({}, change || {}, { pdf });
      await logVoucherAction({
        unit: v.unit, serial: v.serial, action: 'validity_extend', amount: v.amount,
        user: op.user, note: noteFor(op.old, op.new, op.reason, c),
      });
      await finishOp(op, { state: 'done', order: c.order || null, pdf, reactivated: !!c.reactivated });
      logged++;
    } catch (e) { /* a napló hibája ne buktassa el a felküldést */ }
  }
  return logged;
}

module.exports = { cleanDate, cleanReason, validate, ymd, startOp, getOp, finishOp, noteFor, applyIngestChanges };
