const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, getVoucher, unredeemVoucher, logVoucherAction } = require('../lib/db.js');

// Téves beváltás visszavonása: a beváltott utalvány visszaáll aktívra.
// Atomikus — csak 'redeemed' állapotból, és csak egyszer. Minden visszaállítás
// naplózódik (ki, mikor), hogy a napi összesítő utólag is ellenőrizhető maradjon.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { res.status(401).json({ error: 'auth' }); return; }

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
    if (v.status !== 'redeemed') {
      res.status(409).json({ error: 'Csak beváltott utalvány állítható vissza.' });
      return;
    }

    const done = await unredeemVoucher(shop.slug, serial);
    if (!done) { res.status(409).json({ error: 'Nem sikerült visszaállítani (időközben megváltozott az állapota).' }); return; }
    try {
      await logVoucherAction({
        unit: shop.slug, serial, action: 'unredeem', amount: done.amount, user,
        prev_redeemed_at: v.redeemed_at, prev_redeemed_by: v.redeemed_by,
      });
    } catch (e) { /* a napló hibája ne buktassa el a visszaállítást */ }
    res.status(200).json({ ok: true, status: done.status });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
