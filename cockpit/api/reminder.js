const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser, canSeeUnit } = require('../lib/auth.js');
const { ensureSchema, getVoucher, markReminderSent } = require('../lib/db.js');
const { getEmailConfig, senderFor, sendBrevo } = require('../lib/email.js');

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
    const subject = `Ajándékutalványod hamarosan lejár – ${unitName}`;
    const text =
`Kedves ${who}!

Ezúton szeretnénk emlékeztetni, hogy a nálunk vásárolt ajándékutalványod hamarosan lejár:

• Sorszám: ${v.serial}
• Érték: ${amount}
• Érvényes: ${valid}-ig
• Beváltás helye: ${unitName}

Kérjük, használd fel a lejárat előtt – szeretettel várunk!

Üdvözlettel:
${unitName}`;

    // Automatikus küldés Brevón át, ha be van állítva (E-mail beállítások).
    let sent = false;
    const cfg = await getEmailConfig();
    if (cfg.apiKey) {
      const sender = senderFor(cfg, shop.slug);
      if (!sender) {
        res.status(400).json({ error: 'Ehhez az egységhez nincs feladó e-mail beállítva (E-mail beállítások).' });
        return;
      }
      const r = await sendBrevo(cfg.apiKey, sender, email, subject, text);
      if (!r.ok) {
        res.status(502).json({ error: 'E-mail küldési hiba (Brevo): ' + r.error });
        return;
      }
      sent = true;
    }

    await markReminderSent(shop.slug, serial);
    res.status(200).json({ ok: true, sent, email, subject, text });
  } catch (e) {
    res.status(500).json({ error: String(e && e.message || e) });
  }
};
