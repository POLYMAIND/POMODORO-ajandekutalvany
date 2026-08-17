const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser } = require('../lib/auth.js');
const { getConfig, setConfig } = require('../lib/db.js');
const { getEmailConfig, senderFor, sendBrevo } = require('../lib/email.js');

const DEFAULT_SUBJECT = 'Ajándékutalványod hamarosan lejár – {egyseg}';
const DEFAULT_BODY =
`Kedves {nev}!

Ezúton szeretnénk emlékeztetni, hogy a nálunk vásárolt ajándékutalványod hamarosan lejár. Kérjük, használd fel a lejárat előtt – szeretettel várunk!

Üdvözlettel:
{egyseg}`;

// E-mail (Brevo) beállítások — kizárólag központi admin (superadmin).
module.exports = async (req, res) => {
  const u = await resolveUser(req);
  if (!u) { res.status(401).json({ error: 'auth' }); return; }
  if (u.role !== 'superadmin') { res.status(403).json({ error: 'Csak központi admin.' }); return; }

  const units = getShops().map(x => ({ slug: x.slug, name: x.name }));

  if (req.method === 'GET') {
    const c = (await getConfig('email')) || {};
    const key = c.apiKey || '';
    const rem = c.reminder || {};
    res.status(200).json({
      provider: 'brevo',
      apiKeySet: !!key,
      apiKeyHint: key ? ('••••••' + key.slice(-4)) : '',
      from: c.from || {},
      reminder: { subject: rem.subject || DEFAULT_SUBJECT, body: rem.body || DEFAULT_BODY },
      units,
    });
    return;
  }

  if (req.method !== 'POST') { res.status(405).json({ error: 'method' }); return; }

  const body = await readBody(req);
  const cur = (await getConfig('email')) || {};
  const next = { apiKey: cur.apiKey || '', from: cur.from || {}, reminder: cur.reminder || {} };

  // Emlékeztető szövege (tárgy + törzs, helykitöltőkkel).
  if (body.reminder && typeof body.reminder === 'object') {
    next.reminder = {
      subject: String(body.reminder.subject || '').trim() || DEFAULT_SUBJECT,
      body: String(body.reminder.body || '').trim() || DEFAULT_BODY,
    };
  }

  // API kulcs: csak akkor frissítjük, ha új, nem-maszkolt értéket küldtek.
  if (typeof body.apiKey === 'string' && body.apiKey.trim() && body.apiKey.indexOf('•') === -1) {
    next.apiKey = body.apiKey.trim();
  }
  if (body.clearApiKey) next.apiKey = '';

  // Egységenkénti feladó.
  if (body.from && typeof body.from === 'object') {
    const valid = new Set(units.map(x => x.slug.toLowerCase()));
    const clean = {};
    for (const [slug, val] of Object.entries(body.from)) {
      const s = String(slug).toLowerCase();
      if (!valid.has(s)) continue;
      const email = String((val && val.email) || '').trim();
      const name = String((val && val.name) || '').trim();
      if (email || name) clean[s] = { email, name };
    }
    next.from = clean;
  }

  await setConfig('email', next);

  // Opcionális teszt-küldés egy megadott egységgel + címre.
  if (body.test && body.test.unit && body.test.to) {
    const cfg = await getEmailConfig();
    if (!cfg.apiKey) { res.status(200).json({ ok: true, test: { ok: false, error: 'Nincs API kulcs.' } }); return; }
    const sender = senderFor(cfg, body.test.unit);
    if (!sender) { res.status(200).json({ ok: true, test: { ok: false, error: 'Ehhez az egységhez nincs feladó cím.' } }); return; }
    const r = await sendBrevo(cfg.apiKey, sender, String(body.test.to), 'Teszt e-mail – Pomo d\'Oro vezérlőpult',
      'Ez egy teszt e-mail a Pomo d\'Oro vezérlőpultból. Ha megkaptad, a Brevo-küldés működik.');
    res.status(200).json({ ok: true, test: r });
    return;
  }

  res.status(200).json({ ok: true });
};
