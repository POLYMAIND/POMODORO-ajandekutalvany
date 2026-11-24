const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, setValidUntil, logVoucherAction } = require('../lib/db.js');
const R = require('../lib/recipient.js');
const V = require('../lib/validity.js');

// „Érvényesség meghosszabbítása” az utalvány adatlapjáról (pl. vendég kérésére).
//
// POST {unit, serial, until: 'ÉÉÉÉ-HH-NN', reason}
//   • Boltból jött utalvány (van site_url): aláírt, egyszer használható jegyet adunk
//     (op = 'validity'); a böngésző ezzel megnyitja a bolt /pgv/v1/validity-change
//     címét, és a bolt azonnal átírja az utalványt, jegyzetet tesz a rendelésre,
//     újragenerálja a PDF-et és felküldi ide. → { mode:'shop', applyUrl, nonce }
//   • Bolt nélküli (CSV-ből importált) utalvány: itt írjuk át. → { mode:'local', done:true }
//
// GET ?unit=&serial=&nonce= — a folyamatban lévő művelet állapota: pending | done.
module.exports = async (req, res) => {
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }

  const q = req.method === 'GET' ? (req.query || {}) : await readBody(req);
  const unit = String((q && q.unit) || '').trim().toLowerCase();
  const serial = String((q && q.serial) || '').trim();
  if (!unit || !serial) { res.status(400).json({ error: 'Hiányzó egység vagy sorszám.' }); return; }

  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'Ismeretlen egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  try {
    await ensureSchema();

    if (req.method === 'GET') {
      const op = await V.getOp(shop.slug, serial);
      const nonce = String(q.nonce || '');
      if (!op || !nonce || op.nonce !== nonce) { res.status(404).json({ error: 'Nincs ilyen folyamatban lévő hosszabbítás.' }); return; }
      res.setHeader('Cache-Control', 'no-store');
      res.status(200).json({ state: op.state, until: op.new, old: op.old, order: op.order || null,
        pdf: !!op.pdf, reactivated: !!op.reactivated });
      return;
    }
    if (req.method !== 'POST') { res.status(405).json({ error: 'GET vagy POST' }); return; }

    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány.' }); return; }

    const until = V.cleanDate(q.until);
    const reason = V.cleanReason(q.reason);
    const bad = V.validate(v, until);
    if (bad) { res.status(400).json({ error: bad }); return; }
    if (!reason) { res.status(400).json({ error: 'Írd be az indoklást (a naplóba kerül).' }); return; }
    const oldUntil = V.ymd(v.valid_until);

    const site = String(v.site_url || '').trim().replace(/\/+$/, '');
    if (!/^https?:\/\//i.test(site)) {
      // Csak a vezérlőpulton létező utalvány — nincs bolt, nincs rendelés.
      const done = await setValidUntil(shop.slug, serial, until);
      if (!done) { res.status(409).json({ error: 'Nem sikerült menteni (időközben megváltozott).' }); return; }
      const reactivated = String(v.status) === 'expired';
      try {
        await logVoucherAction({ unit: shop.slug, serial, action: 'validity_extend', amount: v.amount, user,
          note: V.noteFor(oldUntil, until, reason, { order: 'local', reactivated }) });
      } catch (e) { /* a napló hibája ne buktassa el a mentést */ }
      res.status(200).json({ ok: true, mode: 'local', done: true, until, old: oldUntil, reactivated });
      return;
    }

    // A böngésző innen jött — a bolt eredmény-oldala ide szól vissza (postMessage).
    const origin = String((req.headers && req.headers.origin) || '');
    const host = String((req.headers && (req.headers['x-forwarded-host'] || req.headers.host)) || '');
    const ret = /^https?:\/\/[A-Za-z0-9.-]+(:\d+)?$/.test(origin) ? origin
      : (/^[A-Za-z0-9.-]+(:\d+)?$/.test(host) ? 'https://' + host : '');

    const op = await V.startOp({ unit: shop.slug, serial, oldUntil, until, reason, user, mode: 'shop' });
    const ticket = R.signTicket({
      op: 'validity', v: 1, unit: shop.slug, serial, old: oldUntil, until, reason,
      by: R.who(user), nonce: op.nonce, ret,
      exp: Math.floor(Date.now() / 1000) + R.TICKET_TTL_SEC,
    });
    if (!ticket) {
      res.status(400).json({ error: 'A bolttal közös kulcs nincs beállítva (INGEST_SECRET vagy VOUCHER_LICENCE_SHA256) — így a bolt nem tudná ellenőrizni a kérést.' });
      return;
    }
    const params = new URLSearchParams({ rest_route: '/pgv/v1/validity-change', p: ticket.p });
    if (ticket.s) params.set('s', ticket.s);
    if (ticket.l) params.set('l', ticket.l);

    res.setHeader('Cache-Control', 'no-store');
    res.status(200).json({ ok: true, mode: 'shop', nonce: op.nonce, old: oldUntil, until,
      applyUrl: site + '/?' + params.toString(), shopOrigin: new URL(site).origin });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
