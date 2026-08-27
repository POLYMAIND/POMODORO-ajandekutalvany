const { getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf } = require('../lib/db.js');

// Az utalvány PDF-je (amit a bolt pluginja feltöltött). Megnyitáshoz/letöltéshez
// a vezérlőpultról — csak bejelentkezve és csak a saját egység utalványára.
module.exports = async (req, res) => {
  const user = await resolveUser(req);
  if (!user) { res.status(401).json({ error: 'auth' }); return; }

  const q = req.query || {};
  const unit = String(q.unit || '').trim().toLowerCase();
  const serial = String(q.serial || '').trim();
  if (!unit || !serial) { res.status(400).json({ error: 'Hiányzó egység vagy sorszám.' }); return; }

  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'Ismeretlen egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  try {
    await ensureSchema();
    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány.' }); return; }
    const b64 = await getVoucherPdf(shop.slug, serial);
    if (!b64) {
      res.status(404).json({ error: 'Ehhez az utalványhoz nincs feltöltött PDF. A boltban a Beállítások → „Összes felküldése” gombbal pótolható.' });
      return;
    }
    const buf = Buffer.from(b64, 'base64');
    res.setHeader('Content-Type', 'application/pdf');
    res.setHeader('Content-Disposition', 'inline; filename="ajandekutalvany-' + serial.replace(/[^A-Za-z0-9\-_]/g, '') + '.pdf"');
    res.setHeader('Cache-Control', 'no-store');
    res.status(200).send(buf);
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
