const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, deleteVoucher, undeleteVoucher, logVoucherAction } = require('../lib/db.js');

// Utalvány végleges törlése. Csak központi admin, és csak a sorszám pontos
// megerősítésével — a művelet nem vonható vissza. A törölt utalvány adatai a
// naplóban maradnak meg (ki, mikor, mit törölt), különben nyomtalanul tűnne el.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }
  if (user.role !== 'superadmin') {
    res.status(403).json({ error: 'Utalványt csak központi admin törölhet.' });
    return;
  }

  const body = await readBody(req);
  const serial = String((body && body.serial) || '').trim();
  const undo = String((body && body.action) || '') === 'undelete';
  const confirm = String((body && body.confirm) || '').trim();
  if (!serial) { res.status(400).json({ error: 'Hiányzó sorszám.' }); return; }
  if (!undo && confirm.toUpperCase() !== serial.toUpperCase()) {
    res.status(400).json({ error: 'A megerősítéshez a sorszámot pontosan be kell írni.' });
    return;
  }

  const shops = getShops();
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  const shop = shops.find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'Ismeretlen egység.' }); return; }

  try {
    await ensureSchema();

    // Visszaengedés: a tiltást oldjuk fel, magát az utalványt a bolt következő
    // szinkronja vagy egy import hozza vissza — az adatai onnan jönnek, nem a naplóból.
    if (undo) {
      const ok = await undeleteVoucher(shop.slug, serial);
      if (!ok) { res.status(404).json({ error: 'Ez az utalvány nincs letiltva.' }); return; }
      await logVoucherAction({ unit: shop.slug, serial, action: 'undelete', user,
        note: 'A törlés feloldva — a következő szinkron visszahozhatja.' });
      res.status(200).json({ ok: true, undeleted: true, serial, unit: shop.slug });
      return;
    }

    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány ebben az egységben.' }); return; }

    // A napló ELŐBB készül el: ha az írás nem sikerül, nem törlünk nyom nélkül.
    const who = v.buyer_name || v.giver_name || v.recipient_name || '';
    const ft = String(v.amount == null ? 0 : v.amount).replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' Ft';
    const note = [who, ft, v.status, v.buyer_email || v.delivery_email || '']
      .filter(Boolean).join(' · ');
    await logVoucherAction({ unit: shop.slug, serial, action: 'delete', amount: v.amount, user, note });

    const gone = await deleteVoucher(shop.slug, serial);
    if (!gone) { res.status(409).json({ error: 'Nem sikerült törölni (időközben megváltozott).' }); return; }
    res.status(200).json({ ok: true, serial, unit: shop.slug });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
