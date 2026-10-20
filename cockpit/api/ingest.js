const { readBody } = require('../lib/shops.js');
const { ensureSchema, upsertVouchers, upsertVoucherPdfs, renameVoucher,
        getConfig, setConfig } = require('../lib/db.js');

// Az egység „névjegye”: a bolt minden felküldéssel elküldi a nevét és a
// beállított utalvány-képeit (nyilvános média-URL-ek). A vezérlőpult ezekből
// rajzolja ki a nyomtatható példányt azoknál az utalványoknál, amelyekhez
// nincs kiküldött PDF (a korábbi rendszerből importált sorok).
//
// A boltból jövő szöveget soha nem vesszük készpénznek: csak http(s) URL-t
// fogadunk el, legfeljebb 12-t, korlátozott hosszal.
function cleanUnitMeta(m) {
  if (!m || typeof m !== 'object') return null;
  const slug = String(m.slug || '').trim().toLowerCase();
  if (!/^[a-z0-9_-]{1,40}$/.test(slug)) return null;
  const images = [];
  for (const im of (Array.isArray(m.images) ? m.images : [])) {
    const url = String((im && im.url) || '').trim();
    if (!/^https?:\/\//i.test(url) || url.length > 600) continue;
    images.push({ title: String((im && im.title) || '').trim().slice(0, 120), url });
    if (images.length >= 12) break;
  }
  return { slug, name: String(m.name || '').trim().slice(0, 120), images };
}

// Egységenként külön kulcs — így két bolt egyidejű felküldése nem írja felül
// egymást. Csak akkor írunk, ha tényleg változott valami (a PDF-ek back-fillje
// utalványonként egy kérés, ott ez több száz fölösleges írás lenne).
async function saveUnitMeta(m) {
  const key = 'unit:' + m.slug;
  const cur = (await getConfig(key)) || {};
  const same = cur.name === m.name &&
    JSON.stringify((cur.images || []).map(x => [x.title, x.url])) ===
    JSON.stringify(m.images.map(x => [x.title, x.url]));
  if (same) return false;
  await setConfig(key, { name: m.name, images: m.images, updated_at: new Date().toISOString() });
  return true;
}

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

    // Az egység névjegye (név + utalvány-képek). Üres felküldésnél is jön —
    // a boltban a „Kapcsolat tesztelése” gomb ezzel frissíti a képeket.
    let unit = null;
    const meta = cleanUnitMeta(body && body.unit_meta);
    if (meta) {
      try { unit = { slug: meta.slug, images: meta.images.length, saved: await saveUnitMeta(meta) }; }
      catch (e) { unit = { slug: meta.slug, error: String((e && e.message) || e) }; }
    }

    res.status(200).json({ ok: true, count, pdfs, renamed, unit });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
