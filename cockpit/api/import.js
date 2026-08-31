const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, upsertVouchers, deleteLegacyByUnit } = require('../lib/db.js');
const { parseCSV } = require('../lib/csv.js');
const { parseXLSX, isLegacyXls, isZip } = require('../lib/xlsx.js');
const { normHeader, rowToRecord, serialColumnPresent } = require('../lib/voucher_csv.js');

// Korábbi CSV-k importálása a vezérlőpultba (egységhez kötve, is_legacy jelöléssel),
// illetve egy egység importjának visszavonása (mode: 'undo').
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { authFail(req, res); return; }
  if (user.role === 'cashier') { res.status(403).json({ error: 'Importhoz kezelői/admin jog kell.' }); return; }

  const body = await readBody(req);
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  const mode = String((body && body.mode) || 'import');
  const csv = String((body && body.csv) || '');
  // Excel (.xlsx/.xlsm) esetén a böngésző a fájl bájtjait küldi base64-ben.
  const fileB64 = String((body && body.file) || '');
  const filename = String((body && body.filename) || '');

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

  if (!csv.trim() && !fileB64) { res.status(400).json({ error: 'Üres fájl.' }); return; }

  let rows;
  if (fileB64) {
    let buf;
    try { buf = Buffer.from(fileB64, 'base64'); } catch (e) { buf = null; }
    if (!buf || !buf.length) { res.status(400).json({ error: 'A feltöltött fájl nem olvasható.' }); return; }
    if (isLegacyXls(buf)) {
      res.status(400).json({
        error: 'Ez régi Excel 97-2003 (.xls) formátum, amit nem tudok olvasni. '
          + 'Nyisd meg Excelben/Google Sheetsben, és mentsd „Excel-munkafüzet (.xlsx)” vagy CSV formátumban.',
      });
      return;
    }
    if (isZip(buf)) {
      try { rows = parseXLSX(buf); }
      catch (e) { res.status(400).json({ error: 'Az Excel-fájl nem értelmezhető: ' + String(e && e.message || e) }); return; }
    } else {
      // Nem ZIP és nem OLE2 → sima szöveg (pl. .csv kiterjesztés nélkül).
      try { rows = parseCSV(buf.toString('utf8'), ';'); }
      catch (e) { rows = null; }
      if (!rows) { res.status(400).json({ error: 'Nem ismerem fel a fájl formátumát (CSV vagy .xlsx kell).' }); return; }
    }
  } else {
    try { rows = parseCSV(csv, ';'); } catch (e) { res.status(400).json({ error: 'A CSV nem értelmezhető.' }); return; }
  }
  const kind = fileB64 && isZipName(filename) ? 'Excel-fájl' : 'CSV';
  if (rows.length < 2) { res.status(400).json({ error: 'A ' + kind + ' nem tartalmaz adatsort.' }); return; }

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
    const r = await upsertVouchers(records);
    res.status(200).json({
      ok: true, imported: r.total, added: r.inserted, updated: r.updated,
      skipped, blocked: r.skipped || 0, unit: shop.slug,
    });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};

function isZipName(name) {
  return /\.(xlsx|xlsm)$/i.test(String(name || ''));
}
