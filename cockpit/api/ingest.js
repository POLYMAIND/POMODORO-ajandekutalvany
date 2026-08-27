const { readBody } = require('../lib/shops.js');
const { ensureSchema, upsertVouchers, upsertVoucherPdfs, renameVoucher } = require('../lib/db.js');

// A boltok pluginja ide küldi (push) az utalványokat. Titok-alapú hitelesítés.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }

  const secret = req.headers['x-ingest-secret'] || (req.query && req.query.secret);
  if (!process.env.INGEST_SECRET || secret !== process.env.INGEST_SECRET) {
    res.status(401).json({ error: 'Érvénytelen ingest titok.' });
    return;
  }

  let body;
  try { body = await readBody(req); } catch (e) { body = {}; }
  const vouchers = Array.isArray(body && body.vouchers) ? body.vouchers
    : (body && body.serial ? [body] : []);

  try {
    await ensureSchema();

    // Kód-csere a boltban: előbb átnevezzük a meglévő sort, csak utána
    // upsertelünk — így nem keletkezik két bejegyzés ugyanarról az utalványról.
    let renamed = 0;
    for (const v of vouchers) {
      if (v && v.previous_serial && v.serial && v.previous_serial !== v.serial) {
        try { if (await renameVoucher(v.unit, v.previous_serial, v.serial)) renamed++; }
        catch (e) { /* ha nem sikerül, az upsert így is létrehozza az új sort */ }
      }
    }

    const { total: count } = await upsertVouchers(vouchers);
    const pdfs = await upsertVoucherPdfs(vouchers); // base64 PDF-ek külön táblába (ha jött)
    res.status(200).json({ ok: true, count, pdfs, renamed });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
