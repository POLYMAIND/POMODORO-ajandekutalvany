const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf, logVoucherAction } = require('../lib/db.js');
const { getEmailConfig, senderFor, sendBrevo, voucherEmailHtml } = require('../lib/email.js');

const DEFAULT_BODY =
`Kedves {nev}!

Ahogy kérted, újraküldjük a(z) {egyseg} egységbe szóló ajándékutalványodat. A részletek alább, az utalványt PDF-ben is mellékeltük.

Foglalj asztalt, hozd magaddal ezt a levelet, a többiről pedig mi gondoskodunk.

Viszontlátásra:
a(z) {egyseg} csapata`;

function fill(tpl, d) {
  return String(tpl || '')
    .replace(/\{nev\}/g, d.name || '')
    .replace(/\{egyseg\}/g, d.unitName || '')
    .replace(/\{sorszam\}/g, d.serial || '')
    .replace(/\{osszeg\}/g, d.amount || '')
    .replace(/\{ervenyesseg\}/g, d.valid || '');
}

// Az utalvány újraküldése e-mailben (a tárolt PDF-fel), a vezérlőpultról.
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const user = await resolveUser(req);
  if (!user) { res.status(401).json({ error: 'auth' }); return; }

  const body = await readBody(req);
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  const serial = String((body && body.serial) || '').trim();
  const toRaw = String((body && body.to) || '').trim();
  if (!unit || !serial) { res.status(400).json({ error: 'Hiányzó egység vagy sorszám.' }); return; }

  const shop = getShops().find(s => String(s.slug).toLowerCase() === unit);
  if (!shop) { res.status(404).json({ error: 'Ismeretlen egység.' }); return; }
  if (!canSeeUnit(user, shop.slug)) { res.status(403).json({ error: 'Nincs jogosultságod ehhez az egységhez.' }); return; }

  try {
    await ensureSchema();
    const v = await getVoucher(shop.slug, serial);
    if (!v) { res.status(404).json({ error: 'Nincs ilyen utalvány.' }); return; }

    const to = toRaw || v.delivery_email || v.buyer_email || '';
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(to)) {
      res.status(400).json({ error: 'Nincs érvényes e-mail cím — add meg, hova küldjük.' });
      return;
    }

    const cfg = await getEmailConfig();
    if (!cfg.apiKey) { res.status(400).json({ error: 'Nincs Brevo API kulcs beállítva (E-mail beállítások).' }); return; }
    const sender = senderFor(cfg, shop.slug);
    if (!sender) { res.status(400).json({ error: 'Ehhez az egységhez nincs feladó e-mail cím beállítva (E-mail beállítások).' }); return; }

    const nf = n => Number(n || 0).toLocaleString('hu-HU');
    const d = {
      name: v.recipient_name || v.buyer_name || v.giver_name || 'Vendégünk',
      unitName: shop.name || shop.slug,
      serial: v.serial,
      amount: nf(v.amount) + ' Ft',
      valid: v.valid_until ? String(v.valid_until).slice(0, 10) : '',
    };
    const bodyText = fill((cfg.resend && cfg.resend.body) || DEFAULT_BODY, d);
    const subject = fill((cfg.resend && cfg.resend.subject) || '{egyseg} · Az ajándékutalványod', d);

    const pdf = await getVoucherPdf(shop.slug, serial);
    const attachments = pdf
      ? [{ content: pdf, name: 'ajandekutalvany-' + String(serial).replace(/[^A-Za-z0-9\-_]/g, '') + '.pdf' }]
      : [];

    const html = voucherEmailHtml({
      bodyText, unitName: d.unitName, serial: d.serial, amount: d.amount,
      valid: d.valid, pdfAttached: attachments.length > 0,
    });

    const r = await sendBrevo(cfg.apiKey, sender, to, subject, bodyText, html, attachments);
    if (!r.ok) { res.status(502).json({ error: 'A küldés nem sikerült: ' + r.error }); return; }

    try {
      await logVoucherAction({ unit: shop.slug, serial, action: 'resend', amount: v.amount, user });
    } catch (e) { /* a napló hibája ne buktassa el a küldést */ }

    res.status(200).json({ ok: true, to, pdfAttached: attachments.length > 0 });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
