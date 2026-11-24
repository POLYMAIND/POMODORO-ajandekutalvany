const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, setRedeemedAt, logVoucherAction } = require('../lib/db.js');

// A beváltás időpontjának utólagos javítása (pl. ha a kasszás csak másnap
// rögzítette). A kasszás nem írhatja át — a napi összesítő ettől megváltozik —,
// és minden módosítás a naplóba kerül a korábbi időponttal együtt.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }
  if (user.role !== 'superadmin' && user.role !== 'unit_manager') {
    res.status(403).json({ error: 'A beváltás időpontját csak admin vagy egység-kezelő módosíthatja.' });
    return;
  }

  const body = await readBody(req);
  const serial = String((body && body.serial) || '').trim();
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  // Elfogadott alak: "YYYY-MM-DD HH:MM" vagy "YYYY-MM-DDTHH:MM" (+ opcionális :SS).
  const raw = String((body && body.at) || '').trim().replace('T', ' ');
  const m = raw.match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::(\d{2}))?$/);
  if (!serial) { res.status(400).json({ error: 'Hiányzó sorszám.' }); return; }
  if (!m) { res.status(400).json({ error: 'Az időpont formátuma nem megfelelő.' }); return; }
  const when = `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:${m[6] || '00'}`;
  if (isNaN(new Date(when.replace(' ', 'T')))) { res.status(400).json({ error: 'Nem létező időpont.' }); return; }

  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'Ismeretlen egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  try {
    await ensureSchema();
    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány ebben az egységben.' }); return; }
    if (v.status !== 'redeemed') { res.status(409).json({ error: 'Csak beváltott utalványnál módosítható a beváltás ideje.' }); return; }

    const done = await setRedeemedAt(shop.slug, serial, when);
    if (!done) { res.status(409).json({ error: 'Nem sikerült módosítani (időközben megváltozott az állapota).' }); return; }
    try {
      const before = v.redeemed_at ? String(new Date(v.redeemed_at).toISOString()).slice(0, 19).replace('T', ' ') : '—';
      await logVoucherAction({ unit: shop.slug, serial, action: 'retime', amount: v.amount, user,
        prev_redeemed_at: v.redeemed_at, prev_redeemed_by: v.redeemed_by, prev_redeemed_by_email: v.redeemed_by_email,
        note: before + ' → ' + when });
    } catch (e) { /* a napló hibája ne buktassa el a javítást */ }
    res.status(200).json({ ok: true, redeemed_at: when });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
