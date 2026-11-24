// A megajándékozott nevének módosítása — közös segédek (api/recipient.js + api/ingest.js).
//
// Az út: adatlap → /api/recipient (aláírt jegy) → a kolléga böngészője megnyitja a
// bolt /pgv/v1/recipient-change címét → a bolt AZONNAL átírja a WooCommerce-rendelést
// és az utalványt, újragenerálja a PDF-et, és felküldi ide (/api/ingest) a
// `change` mezővel → itt naplózunk, és lezárjuk a folyamatban lévő műveletet.
//
// Miért a böngészőn át? A tárhely bot-védelme a vezérlőpult szerverének bejövő
// kérését elfogja (mérésileg igazolt), a böngészőt viszont kiszolgálja.
const crypto = require('crypto');
const { getConfig, setConfig, logVoucherAction, pendingRecipientOps } = require('./db.js');

const TICKET_TTL_SEC = 15 * 60;

// Egy sor, vezérlőkarakter nélkül, legfeljebb 120 karakter (a termékoldali mező korlátja).
function cleanName(s) {
  return String(s == null ? '' : s)
    .replace(/[\u0000-\u001f\u007f<>]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 120);
}

function b64url(buf) {
  return Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// Aláírás a bolttal közös kulccsal. Kétféle kulcs lehet, és nem tudjuk, a bolt
// melyiket használja, ezért mindkettővel aláírunk, ami be van állítva:
//   s = HMAC(INGEST_SECRET)            — a bolt „Ingest titok” mezője
//   l = HMAC(VOUCHER_LICENCE_SHA256)   — a bolt a licencéből számolja ki ugyanezt
function signTicket(t) {
  const p = b64url(JSON.stringify(t));
  const out = { p };
  const secret = String(process.env.INGEST_SECRET || '');
  const lic = String(process.env.VOUCHER_LICENCE_SHA256 || '').trim().toLowerCase();
  if (secret) out.s = crypto.createHmac('sha256', secret).update(p).digest('hex');
  if (/^[a-f0-9]{64}$/.test(lic)) out.l = crypto.createHmac('sha256', lic).update(p).digest('hex');
  return (out.s || out.l) ? out : null;
}

function opKey(unit, serial) {
  return 'recipient_op:' + String(unit).toLowerCase() + ':' + String(serial);
}

function who(user) {
  const u = user || {};
  const name = String(u.name || '').trim();
  const email = String(u.email || '').trim();
  // Zárójel, nem <…>: a bolt szöveg-tisztítója a kacsacsőrös részt HTML-címkének nézné és kidobná.
  return name && email && name !== email ? name + ' (' + email + ')' : (name || email || 'ismeretlen');
}

// Új művelet indítása: utalványonként egy függő művelet (az újabb felülírja a régit).
async function startOp({ unit, serial, oldName, newName, user, mode }) {
  const nonce = crypto.randomBytes(16).toString('hex');
  const op = {
    nonce, unit: String(unit).toLowerCase(), serial: String(serial),
    old: oldName || '', new: newName, mode,
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

function noteFor(oldName, newName, change) {
  const c = change || {};
  const parts = ['„' + (oldName || '—') + '” → „' + (newName || '—') + '”'];
  if (c.order === 'no_order') parts.push('WooCommerce: nincs rendelés');
  else if (c.order === 'updated' || c.order === 'updated_per_serial') parts.push('WooCommerce-rendelés frissítve');
  else if (c.order === 'local') parts.push('csak a vezérlőpulton (nincs bolti utalvány)');
  if (c.pdf === true) parts.push('PDF újragenerálva');
  if (c.via === 'admin' && c.by) parts.push('a boltban módosította: ' + c.by);
  return parts.join(' · ');
}

// A bolt felküldése után: a névváltozások naplózása és a függő műveletek lezárása.
//
// Két forrás:
//   1) a vezérlőpultról indított módosítás (van függő művelet, a nonce egyezik,
//      vagy — ha a felküldés elsőre elakadt és a bolt később, a `change` nélkül
//      küldte újra — legalább az új név egyezik);
//   2) a bolt WP-adminjában végzett szerkesztés (`change.via = 'admin'`, nincs művelet).
async function applyIngestChanges(vouchers) {
  let logged = 0;
  let ops = {};
  try { ops = await pendingRecipientOps(); } catch (e) { ops = {}; }
  for (const v of (vouchers || [])) {
    if (!v || !v.unit || !v.serial) continue;
    const change = (v.change && typeof v.change === 'object' && v.change.type === 'recipient') ? v.change : null;
    const op = ops[opKey(v.unit, v.serial)] || null;
    if (!change && !op) continue;
    const pending = !!op;
    const newName = cleanName(change ? change.new : v.recipient_name);

    const matchesOp = pending && (
      (change && change.nonce && change.nonce === op.nonce) ||
      (!change && newName && newName === op.new)
    );
    try {
      if (matchesOp) {
        const pdf = typeof v.pdf_base64 === 'string' && v.pdf_base64.length > 0;
        const c = Object.assign({}, change || {}, { pdf });
        await logVoucherAction({
          unit: v.unit, serial: v.serial, action: 'recipient_change', amount: v.amount,
          user: op.user, note: noteFor(op.old, op.new, c),
        });
        await finishOp(op, { state: 'done', order: c.order || null, pdf });
        logged++;
      } else if (change && change.via === 'admin') {
        const pdf = typeof v.pdf_base64 === 'string' && v.pdf_base64.length > 0;
        await logVoucherAction({
          unit: v.unit, serial: v.serial, action: 'recipient_change', amount: v.amount,
          user: { name: 'Bolt (WP admin): ' + String(change.by || '').slice(0, 120) },
          note: noteFor(change.old, change.new, Object.assign({}, change, { pdf })),
        });
        logged++;
      }
    } catch (e) { /* a napló hibája ne buktassa el a felküldést */ }
  }
  return logged;
}

module.exports = { TICKET_TTL_SEC, cleanName, signTicket, startOp, getOp, finishOp, noteFor, who, applyIngestChanges };
