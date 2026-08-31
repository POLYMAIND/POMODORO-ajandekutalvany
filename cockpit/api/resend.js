const { getShops, readBody } = require('../lib/shops.js');
const { resolveUser, canSeeUnit, authFail } = require('../lib/auth.js');
const { ensureSchema, getVoucher, getVoucherPdf, logVoucherAction } = require('../lib/db.js');
const { getEmailConfig, senderFor, sendBrevo, voucherEmailHtml, huDate } = require('../lib/email.js');

// Két eset, két szöveg. Semmit nem feltételezünk arról, hogy a vendég kérte-e:
// lehet, hogy a levél elveszett, vagy nálunk történt hiba.
const TEXTS = {
  resend: {
    subject: '{egyseg} · Az ajándékutalványod',
    body:
`Kedves {nev}!

Küldjük a(z) {egyseg} egységbe szóló ajándékutalványodat — a részleteket alább találod, az utalványt PDF-ben is mellékeltük.

Foglalj asztalt, hozd magaddal ezt a levelet, a többiről pedig mi gondoskodunk.

Viszontlátásra:
a(z) {egyseg} csapata`,
  },
  newcode: {
    subject: '{egyseg} · Az ajándékutalványod új azonosítót kapott',
    body:
`Kedves {nev}!

A(z) {egyseg} egységbe szóló ajándékutalványod új azonosítót kapott. A korábban kapott kód már nem érvényes — beváltáskor ez a levél, illetve a mellékelt PDF az érvényes.

Az utalvány értéke és érvényessége nem változott. Foglalj asztalt, hozd magaddal ezt a levelet, a többiről pedig mi gondoskodunk.

Elnézést a kellemetlenségért!

Viszontlátásra:
a(z) {egyseg} csapata`,
  },
};

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
  if (!user) { authFail(req, res); return; }

  const body = await readBody(req);
  const unit = String((body && body.unit) || '').trim().toLowerCase();
  const serial = String((body && body.serial) || '').trim();
  const toRaw = String((body && body.to) || '').trim();
  const reason = TEXTS[String((body && body.reason) || '')] ? String(body.reason) : 'resend';
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
      valid: huDate(v.valid_until),
    };
    const tpl = TEXTS[reason];
    const bodyText = fill(tpl.body, d);
    const subject = fill(tpl.subject, d);

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
      await logVoucherAction({ unit: shop.slug, serial, action: 'newcode' === reason ? 'resend_newcode' : 'resend', amount: v.amount, user });
    } catch (e) { /* a napló hibája ne buktassa el a küldést */ }

    res.status(200).json({ ok: true, to, reason, pdfAttached: attachments.length > 0 });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
