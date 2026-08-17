const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, upsertVouchers, deleteLegacyByUnit } = require('../lib/db.js');
const { parseCSV } = require('../lib/csv.js');
const { normHeader, rowToRecord, serialColumnPresent } = require('../lib/voucher_csv.js');

// Korábbi CSV-k importálása a vezérlőpultba (egységhez kötve, is_legacy jelöléssel),
// illetve egy egység importjának visszavonása (mode: 'undo').
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { res.status(401).json({ error: 'auth' }); return; }
  if (user.role === 'cashier') { res.status(403).json({ error: 'Importhoz kezelői/admin jog kell.' }); return; }

  const body = await readBody(req);
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  const mode = String((body && body.mode) || 'import');
  const csv = String((body && body.csv) || '');

  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(400).json({ error: 'Ismeretlen egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  // Import visszavonása: az egység összes importált (legacy) utalványának törlése.
  if (mode === 'undo') {
    try {
      await ensureSchema();
      const deleted = await deleteLegacyByUnit(shop.slug);
      res.status(200).json({ ok: true, deleted, unit: shop.slug });
    } catch (e) {
      res.status(500).json({ error: String(e && e.message || e) });
    }
    return;
  }

  if (!csv.trim()) { res.status(400).json({ error: 'Üres CSV.' }); return; }

  let rows;
  try { rows = parseCSV(csv, ';'); } catch (e) { res.status(400).json({ error: 'A CSV nem értelmezhető.' }); return; }
  if (rows.length < 2) { res.status(400).json({ error: 'A CSV nem tartalmaz adatsort.' }); return; }

  const headerIdx = {};
  rows[0].forEach((h, i) => { headerIdx[normHeader(h)] = i; });
  if (!serialColumnPresent(headerIdx)) {
    res.status(400).json({ error: 'Nem találom az utalvány kódját tartalmazó oszlopot („utalvány kódja” vagy „azonosító”).' });
    return;
  }

  const records = [];
  let skipped = 0;
  for (let i = 1; i < rows.length; i++) {
    const rec = rowToRecord(headerIdx, rows[i], shop.slug);
    if (!rec.serial) { skipped++; continue; }
    records.push(rec);
  }

  try {
    await ensureSchema();
    const imported = await upsertVouchers(records);
    res.status(200).json({ ok: true, imported, skipped, unit: shop.slug });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
