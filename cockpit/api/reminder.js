const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, getVoucher, markReminderSent } = require('../lib/db.js');
const { getEmailConfig, senderFor, sendBrevo } = require('../lib/email.js');

const DEFAULT_SUBJECT = '{egyseg} · Ajándékutalványod {napok}';
const DEFAULT_BODY =
`Kedves {nev}!

Öröm, hogy nálunk választottál ajándékutalványt. Csak finoman emlékeztetnénk: a(z) {osszeg} értékű utalványod {napok}, és {ervenyesseg}-ig váltható be nálunk, a(z) {egyseg} egységben.

Foglalj asztalt, hozd magaddal ezt a levelet vagy a mellékelt utalványt, a többiről pedig mi gondoskodunk. Szeretettel várunk egy kellemes vendéglátós élményre!

Ha bármi kérdésed lenne, keress minket bizalommal.

Viszontlátásra:
a(z) {egyseg} csapata`;

// A bolt pluginjától lekéri az utalvány PDF-jét (base64) a közös push-titokkal.
// Csak plugin-eredetű (site_url-lel rendelkező, nem legacy) utalványnál működik;
// bármilyen hiba esetén null-lal tér vissza, hogy a levél PDF nélkül is kimenjen.
async function fetchVoucherPdf(siteUrl, serial) {
  const secret = process.env.INGEST_SECRET || '';
  if (!siteUrl || !secret) return null;
  try {
    const base = String(siteUrl).replace(/\/+$/, '');
    const url = base + '/wp-json/pgv/v1/voucher-pdf?serial=' + encodeURIComponent(serial);
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 12000);
    let r;
    try {
      r = await fetch(url, { headers: { 'x-ingest-secret': secret, accept: 'application/json' }, signal: ctrl.signal });
    } finally { clearTimeout(timer); }
    if (!r || !r.ok) return null;
    const j = await r.json();
    if (!j || !j.pdf_base64) return null;
    return { content: j.pdf_base64, name: j.filename || ('ajandekutalvany-' + serial + '.pdf') };
  } catch (e) {
    return null;
  }
}

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

// Elegáns, brandelt HTML: a szerkeszthető törzs + egy kiemelt utalvány-doboz.
function buildHtml(d) {
  const body = esc(d.bodyText).replace(/\n/g, '<br>');
  const badge = d.daysPhrase
    ? `<span style="display:inline-block;margin-top:6px;padding:3px 12px;border-radius:20px;background:#fbe9e1;color:#b4460f;font:600 13px Arial,sans-serif">${esc(d.daysPhrase)}</span>`
    : '';
  return `<!doctype html><html lang="hu"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#efe9df;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#efe9df;padding:28px 12px">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border:1px solid #e4dccd;border-radius:16px;overflow:hidden">
  <tr><td style="background:#221d18;padding:22px 28px">
    <div style="font:600 20px Georgia,'Times New Roman',serif;color:#fff;letter-spacing:.2px">${esc(d.unitName)}</div>
    <div style="font:600 10px Arial,sans-serif;letter-spacing:.14em;text-transform:uppercase;color:#b4892f;margin-top:4px">Ajándékutalvány</div>
  </td></tr>
  <tr><td style="padding:26px 28px 8px;color:#221d18;font:15px/1.6 Arial,sans-serif">${body}</td></tr>
  <tr><td style="padding:8px 28px 28px">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px dashed #d9caa6;border-radius:12px;background:#faf7f1">
      <tr><td style="padding:18px 20px" align="center">
        <div style="font:700 30px Georgia,serif;color:#221d18">${esc(d.amount)}</div>
        <div style="font:700 15px 'Courier New',monospace;color:#6d6357;letter-spacing:1px;margin-top:6px">${esc(d.serial)}</div>
        <div style="font:14px Arial,sans-serif;color:#6d6357;margin-top:8px">Érvényes: <strong style="color:#221d18">${esc(d.valid)}</strong>-ig</div>
        ${badge}
      </td></tr>
    </table>
  </td></tr>
  <tr><td style="padding:0 28px 26px;font:12px Arial,sans-serif;color:#9a9084">${esc(d.unitName)} · Ajándékutalvány</td></tr>
</table>
</td></tr></table>
</body></html>`;
}

// Lejárati emlékeztető: ha be van állítva e-mail szolgáltató (RESEND_API_KEY +
// REMINDER_FROM), a szerver azonnal kiküldi; különben visszaadja az előre kitöltött
// levelet, hogy a kasszás a saját postafiókjából küldje. Mindkét esetben megjelöli.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { res.status(401).json({ error: 'auth' }); return; }

  const body = await readBody(req);
  const serial = String((body && body.serial) || '').trim();
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'Ismeretlen egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  try {
    await ensureSchema();
    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány.' }); return; }

    const email = (v.buyer_email || v.delivery_email || '').trim();
    if (!email) { res.status(400).json({ error: 'Ehhez az utalványhoz nincs e-mail cím.' }); return; }

    if (v.reminder_sent_at && !body.force) {
      res.status(409).json({ error: 'Erre már küldtünk emlékeztetőt.' });
      return;
    }

    const amount = Number(v.amount || 0).toLocaleString('hu-HU') + ' Ft';
    const valid = v.valid_until instanceof Date ? v.valid_until.toISOString().slice(0, 10) : String(v.valid_until || '').slice(0, 10);
    const who = v.buyer_name || v.giver_name || 'Kedves Vásárlónk';
    const unitName = String(shop.name || shop.slug);

    // Hátralévő napok a lejáratig.
    let daysLeft = null;
    if (valid) {
      const t = new Date(valid + 'T00:00:00');
      if (!isNaN(t)) daysLeft = Math.ceil((t - new Date().setHours(0, 0, 0, 0)) / 86400000);
    }
    const daysPhrase = daysLeft == null ? '' : (daysLeft < 0 ? 'lejárt' : (daysLeft === 0 ? 'ma jár le' : 'még ' + daysLeft + ' napig érvényes'));

    const cfg = await getEmailConfig();
    const vars = { nev: who, egyseg: unitName, sorszam: v.serial, osszeg: amount, ervenyesseg: valid, napok: daysPhrase, uzenet: v.message || '' };
    const subst = s => String(s == null ? '' : s).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));
    const subject = subst((cfg.reminder && cfg.reminder.subject) || DEFAULT_SUBJECT);
    const bodyText = subst((cfg.reminder && cfg.reminder.body) || DEFAULT_BODY);

    // Egyszerű szöveges változat: a szerkeszthető törzs + az utalvány adatai.
    const text = bodyText + '\n\n———\n' +
      'Sorszám: ' + v.serial + '\n' +
      'Érték: ' + amount + '\n' +
      'Érvényes: ' + valid + '-ig' + (daysPhrase ? ' (' + daysPhrase + ')' : '') + '\n' +
      'Beváltás helye: ' + unitName;

    const html = buildHtml({ bodyText, unitName, serial: v.serial, amount, valid, daysPhrase, daysLeft });

    // Utalvány-PDF csatolása, ha a plugin elérhető (nem legacy, van site_url).
    let attachments = null;
    let pdfAttached = false;
    if (!v.is_legacy && v.site_url) {
      const pdf = await fetchVoucherPdf(v.site_url, v.serial);
      if (pdf) { attachments = [pdf]; pdfAttached = true; }
    }

    // Automatikus küldés Brevón át, ha be van állítva (E-mail beállítások).
    let sent = false;
    if (cfg.apiKey) {
      const sender = senderFor(cfg, shop.slug);
      if (!sender) {
        res.status(400).json({ error: 'Ehhez az egységhez nincs feladó e-mail beállítva (E-mail beállítások).' });
        return;
      }
      const r = await sendBrevo(cfg.apiKey, sender, email, subject, text, html, attachments);
      if (!r.ok) {
        res.status(502).json({ error: 'E-mail küldési hiba (Brevo): ' + r.error });
        return;
      }
      sent = true;
    }

    await markReminderSent(shop.slug, serial);
    res.status(200).json({ ok: true, sent, email, subject, text, pdfAttached });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
