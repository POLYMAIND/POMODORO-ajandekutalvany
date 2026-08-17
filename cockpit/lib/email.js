// E-mail konfiguráció (Brevo) + küldés. A beállításokat a pgv_config 'email' kulcsa tárolja:
//   { apiKey, from: { <unitSlug>: { email, name } } }
const { getConfig } = require('./db.js');

async function getEmailConfig() {
  const c = (await getConfig('email')) || {};
  return {
    apiKey: c.apiKey || process.env.BREVO_API_KEY || '',
    from: c.from || {}, // { slug: {email,name} }
    reminder: c.reminder || {}, // { subject, body }
  };
}

// A megadott egységhez tartozó feladó (nincs fallback — egységenként külön cím).
function senderFor(cfg, unitSlug) {
  const s = cfg.from && cfg.from[String(unitSlug).toLowerCase()];
  if (s && s.email) return { email: s.email, name: s.name || s.email };
  return null;
}

async function sendBrevo(apiKey, sender, toEmail, subject, text, html) {
  try {
    const payload = {
      sender: { email: sender.email, name: sender.name || sender.email },
      to: [{ email: toEmail }],
      subject,
      textContent: text,
    };
    if (html) payload.htmlContent = html;
    const r = await fetch('https://api.brevo.com/v3/smtp/email', {
      method: 'POST',
      headers: { 'api-key': apiKey, 'content-type': 'application/json', accept: 'application/json' },
      body: JSON.stringify(payload),
    });
    if (!r.ok) { const t = await r.text(); return { ok: false, error: t.slice(0, 300) }; }
    return { ok: true };
  } catch (e) {
    return { ok: false, error: String(e && e.message || e) };
  }
}

module.exports = { getEmailConfig, senderFor, sendBrevo };
