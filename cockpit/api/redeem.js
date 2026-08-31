const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, redeemVoucher, logVoucherAction } = require('../lib/db.js');

// Egységes kassza: beváltás a központi adatbázisban (legacy és élő utalványra is).
// Atomikus — beváltott/lejárt/sztornó utalványt nem enged még egyszer beváltani.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }

  const body = await readBody(req);
  const serial = String((body && body.serial) || '').trim();
  if (!serial) { res.status(400).json({ error: 'Hiányzó sorszám.' }); return; }

  // Egység: a kliens küldi (a lista rekordjából), vagy a sorszám előtagjából.
  const shops = getShops();
  let unit = String((body && body.unit) || '').trim().toLowerCase();
  if (!unit) {
    const byPrefix = shops.find(s => serial.toUpperCase().startsWith(String(s.prefix || '').toUpperCase() + '-'));
    unit = byPrefix ? byPrefix.slug : '';
  }
  const shop = shops.find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'A sorszám alapján nem azonosítható az egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  try {
    await ensureSchema();
    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány ebben az egységben.' }); return; }

    const labels = { active: 'Aktív', redeemed: 'Beváltva', cancelled: 'Sztornó', expired: 'Lejárt', pending: 'Függőben' };
    if (v.status !== 'active') {
      res.status(409).json({ error: 'Nem beváltható (állapot: ' + (labels[v.status] || v.status) + ').' });
      return;
    }

    const done = await redeemVoucher(shop.slug, serial, user);
    if (!done) {
      // Aktív volt, de a feltétel mégsem teljesült → lejárt, vagy közben beváltották.
      res.status(409).json({ error: 'Nem beváltható (lejárt, vagy időközben beváltották).' });
      return;
    }
    try {
      await logVoucherAction({ unit: shop.slug, serial, action: 'redeem', amount: done.amount, user });
    } catch (e) { /* a napló hibája ne buktassa el a beváltást */ }
    res.status(200).json({ ok: true, status: 'redeemed', redeemed_at: done.redeemed_at });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
