const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, redeemVoucher, logVoucherAction } = require('../lib/db.js');
const { ymd } = require('../lib/dates.js');

// A lejáratot budapesti naptár szerint ítéljük meg — ugyanúgy, ahogy a beváltás
// időbélyege és az adatbázis feltétele is. (A szerver órája UTC-n jár, ezért a
// puszta `new Date()` éjfél után két órán át még az előző napot mondaná.)
const todayBudapest = () =>
  new Intl.DateTimeFormat('sv-SE', { timeZone: 'Europe/Budapest' }).format(new Date());

// Egységes kassza: beváltás a központi adatbázisban (legacy és élő utalványra is).
// Atomikus — beváltott és sztornó utalványt nem enged még egyszer beváltani.
//
// Lejárt utalvány: alapból elutasítjuk, de a válasz `expired: true` mezővel
// jelzi, hogy felülbírálható. A kassza ilyenkor külön gombot és megerősítést
// mutat, és `force: true`-val küldi újra — ez a vendéglátós tudatos döntése,
// ezért külön naplósorba kerül (`redeem_expired`).
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
    const force = !!(body && body.force);
    const validUntil = ymd(v.valid_until);
    // Lejárt kétféleképpen lehet: a dátuma elmúlt, vagy importból már `expired`
    // státusszal érkezett. A kassza mindkettőt ugyanúgy kezeli.
    const expired = v.status === 'expired' || (!!validUntil && validUntil < todayBudapest());

    if (v.status !== 'active' && !(force && v.status === 'expired')) {
      res.status(409).json({
        error: 'Nem beváltható (állapot: ' + (labels[v.status] || v.status) + ').',
        // A lejártat a kassza felülbírálhatja; a sztornót és a függőben lévőt nem.
        expired: v.status === 'expired' || undefined,
        valid_until: v.status === 'expired' ? validUntil : undefined,
      });
      return;
    }
    if (expired && !force) {
      res.status(409).json({
        error: 'Ez az utalvány lejárt (érvényes volt: ' + validUntil + ').',
        expired: true,
        valid_until: validUntil,
      });
      return;
    }

    // A megengedő ágra CSAK akkor váltunk, ha tényleg lejárt utalványról van
    // szó. Így a napló sosem mondhat mást, mint amit az adatbázis csinált: egy
    // feleslegesen küldött `force` nem kerüli meg a szokásos feltételt.
    const done = await redeemVoucher(shop.slug, serial, user, force && expired);
    if (!done) {
      // A feltétel nem teljesült: vagy időközben beváltották, vagy (ritkán, a
      // nap fordulóján) a szerver naptára szerint már lejárt. Megnézzük, melyik,
      // hogy a kassza fel tudja ajánlani a felülbírálást.
      const now = await getVoucher(shop.slug, serial);
      if (now && now.status === 'redeemed') {
        res.status(409).json({ error: 'Ezt az utalványt időközben már beváltották.' });
        return;
      }
      res.status(409).json({
        error: 'Ez az utalvány lejárt (érvényes volt: ' + (ymd(now && now.valid_until) || validUntil) + ').',
        expired: true,
        valid_until: ymd(now && now.valid_until) || validUntil,
      });
      return;
    }
    try {
      await logVoucherAction({
        unit: shop.slug, serial, amount: done.amount, user,
        action: expired ? 'redeem_expired' : 'redeem',
        note: expired ? 'lejárt utalvány — érvényes volt: ' + validUntil : null,
      });
    } catch (e) { /* a napló hibája ne buktassa el a beváltást */ }
    res.status(200).json({ ok: true, status: 'redeemed', redeemed_at: done.redeemed_at, expired: expired || undefined });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
