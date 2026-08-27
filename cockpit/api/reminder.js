const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf, markReminderSent } = require('../lib/db.js');
const { getEmailConfig, senderFor, sendBrevo, voucherEmailHtml } = require('../lib/email.js');

const DEFAULT_SUBJECT = '{egyseg} · Ajándékutalványod {napok}';
const DEFAULT_BODY =
`Kedves {nev}!

Öröm, hogy nálunk választottál ajándékutalványt. Csak finoman emlékeztetnénk: a(z) {osszeg} értékű utalványod {napok}, és {ervenyesseg}-ig váltható be nálunk, a(z) {egyseg} egységben.

Foglalj asztalt, hozd magaddal ezt a levelet, a többiről pedig mi gondoskodunk. Szeretettel várunk egy kellemes vendéglátós élményre!

Ha bármi kérdésed lenne, keress minket bizalommal.

Viszontlátásra:
a(z) {egyseg} csapata`;

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

    // Utalvány-PDF csatolása CSAK akkor, ha tényleg van ilyen: azaz plugin-eredetű
    // (van site_url, nem legacy) utalvány, és a plugin vissza is adta a PDF-et.
    // A régi / importált tételekhez nem mi generáltunk PDF-et → nincs csatolmány,
    // és a levél sem ígér mellékletet.
    // A PDF-et a plugin push-olja fel (pgv_pdfs tábla) — a bolt bejövő hívását a
    // tárhely blokkolja, ezért nem húzzuk le, hanem a tárolt base64-et csatoljuk.
    let attachments = null;
    let pdfAttached = false;
    let pdfReason = v.is_legacy ? 'legacy' : 'no_pdf_stored';
    if (!v.is_legacy) {
      const b64 = await getVoucherPdf(shop.slug, v.serial);
      if (b64) {
        attachments = [{ content: b64, name: 'ajandekutalvany-' + v.serial + '.pdf' }];
        pdfAttached = true;
        pdfReason = 'ok';
      }
    }
    console.log('[reminder pdf]', JSON.stringify({ serial: v.serial, unit: shop.slug, reason: pdfReason }));

    // Egyszerű szöveges változat: a szerkeszthető törzs + az utalvány adatai.
    let text = bodyText + '\n\n———\n' +
      'Sorszám: ' + v.serial + '\n' +
      'Érték: ' + amount + '\n' +
      'Érvényes: ' + valid + '-ig' + (daysPhrase ? ' (' + daysPhrase + ')' : '') + '\n' +
      'Beváltás helye: ' + unitName;
    if (pdfAttached) text += '\n\nAz ajándékutalványt PDF-ben is mellékeltük ehhez a levélhez.';

    const html = voucherEmailHtml({ bodyText, unitName, serial: v.serial, amount, valid, daysPhrase, daysLeft, pdfAttached });

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
    res.status(200).json({ ok: true, sent, email, subject, text, pdfAttached, pdfReason });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
