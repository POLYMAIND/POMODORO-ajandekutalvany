const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, setRecipientName, logVoucherAction } = require('../lib/db.js');
const R = require('../lib/recipient.js');

// „Megajándékozott módosítása” az utalvány adatlapjáról.
//
// POST {unit, serial, name}
//   • Boltból jött utalvány (van site_url): NEM itt írjuk át a nevet. Aláírt,
//     egyszer használható jegyet adunk; a böngésző ezzel megnyitja a bolt
//     /pgv/v1/recipient-change címét, és a bolt azonnal átírja a WooCommerce-
//     rendelést + az utalványt, újragenerálja a PDF-et, és felküldi ide. Így a két
//     rendszerben sosem lehet eltérő a név: itt csak az jelenik meg, amit a bolt
//     ténylegesen elmentett. → { mode:'shop', applyUrl, nonce }
//   • Bolt nélküli (a vezérlőpultba CSV-ből importált) utalvány: nincs WooCommerce-
//     rendelés, itt írjuk át, és a nyomtatható példány már az új nevet mutatja.
//     → { mode:'local', done:true }
//
// GET ?unit=&serial=&nonce=  — a folyamatban lévő művelet állapota (a felület kérdezi
//   le, amíg a bolt visszajelzése meg nem jön): pending | done.
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
      const op = await R.getOp(shop.slug, serial);
      const nonce = String(q.nonce || '');
      if (!op || !nonce || op.nonce !== nonce) { res.status(404).json({ error: 'Nincs ilyen folyamatban lévő módosítás.' }); return; }
      const v = op.state === 'done' ? await getVoucher(shop.slug, serial) : null;
      res.setHeader('Cache-Control', 'no-store');
      res.status(200).json({ state: op.state, name: op.new, old: op.old, order: op.order || null, pdf: !!op.pdf,
        recipient_name: v ? v.recipient_name : undefined });
      return;
    }
    if (req.method !== 'POST') { res.status(405).json({ error: 'GET vagy POST' }); return; }

    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány.' }); return; }

    const name = R.cleanName(q.name);
    if (!name) { res.status(400).json({ error: 'Add meg a megajándékozott új nevét.' }); return; }
    const oldName = String(v.recipient_name || '');
    if (name === oldName) { res.status(400).json({ error: 'Ez a név már így szerepel az utalványon.' }); return; }

    const site = String(v.site_url || '').trim().replace(/\/+$/, '');
    if (!/^https?:\/\//i.test(site)) {
      // Csak a vezérlőpulton létező utalvány — nincs bolt, nincs rendelés.
      const done = await setRecipientName(shop.slug, serial, name);
      if (!done) { res.status(409).json({ error: 'Nem sikerült menteni (időközben megváltozott).' }); return; }
      try {
        await logVoucherAction({ unit: shop.slug, serial, action: 'recipient_change', amount: v.amount, user,
          note: R.noteFor(oldName, name, { order: 'local' }) });
      } catch (e) { /* a napló hibája ne buktassa el a mentést */ }
      res.status(200).json({ ok: true, mode: 'local', done: true, name, old: oldName });
      return;
    }

    // A böngésző innen jött — a bolt eredmény-oldala ide szól vissza (postMessage).
    const origin = String((req.headers && req.headers.origin) || '');
    const host = String((req.headers && (req.headers['x-forwarded-host'] || req.headers.host)) || '');
    const ret = /^https?:\/\/[A-Za-z0-9.-]+(:\d+)?$/.test(origin) ? origin
      : (/^[A-Za-z0-9.-]+(:\d+)?$/.test(host) ? 'https://' + host : '');

    const op = await R.startOp({ unit: shop.slug, serial, oldName, newName: name, user, mode: 'shop' });
    const ticket = R.signTicket({
      op: 'recipient', v: 1, unit: shop.slug, serial, old: oldName, name,
      by: R.who(user), nonce: op.nonce, ret,
      exp: Math.floor(Date.now() / 1000) + R.TICKET_TTL_SEC,
    });
    if (!ticket) {
      res.status(400).json({ error: 'A bolttal közös kulcs nincs beállítva (INGEST_SECRET vagy VOUCHER_LICENCE_SHA256) — így a bolt nem tudná ellenőrizni a kérést.' });
      return;
    }
    const params = new URLSearchParams({ rest_route: '/pgv/v1/recipient-change', p: ticket.p });
    if (ticket.s) params.set('s', ticket.s);
    if (ticket.l) params.set('l', ticket.l);

    res.setHeader('Cache-Control', 'no-store');
    res.status(200).json({ ok: true, mode: 'shop', nonce: op.nonce, old: oldName, name,
      applyUrl: site + '/?' + params.toString(), shopOrigin: new URL(site).origin });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
