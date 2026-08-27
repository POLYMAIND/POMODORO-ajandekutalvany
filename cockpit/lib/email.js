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

// attachments: [{ content: <base64>, name: 'utalvany.pdf' }]
async function sendBrevo(apiKey, sender, toEmail, subject, text, html, attachments) {
  try {
    const payload = {
      sender: { email: sender.email, name: sender.name || sender.email },
      to: [{ email: toEmail }],
      subject,
      textContent: text,
    };
    if (html) payload.htmlContent = html;
    if (Array.isArray(attachments) && attachments.length) payload.attachment = attachments;
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

function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }

// Elegáns, brandelt HTML: a szerkeszthető törzs + egy kiemelt utalvány-doboz.
function voucherEmailHtml(d) {
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
  ${d.pdfAttached ? `<tr><td style="padding:0 28px 18px;font:13px Arial,sans-serif;color:#6d6357">📎 Az ajándékutalványt PDF-ben is mellékeltük ehhez a levélhez.</td></tr>` : ''}
  <tr><td style="padding:0 28px 26px;font:12px Arial,sans-serif;color:#9a9084">${esc(d.unitName)} · Ajándékutalvány</td></tr>
</table>
</td></tr></table>
</body></html>`;
}


module.exports = { getEmailConfig, senderFor, sendBrevo, voucherEmailHtml };
