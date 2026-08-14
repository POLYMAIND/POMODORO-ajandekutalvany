const { makeToken, readBody } = require('../lib/shops.js');

module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const body = await readBody(req);
  const pass = (body && body.password) || '';
  if (!process.env.APP_PASSWORD) {
    res.status(500).json({ error: 'Nincs beállítva jelszó (APP_PASSWORD).' });
    return;
  }
  if (pass !== process.env.APP_PASSWORD) {
    res.status(401).json({ error: 'Hibás jelszó.' });
    return;
  }
  const tok = makeToken();
  res.setHeader('Set-Cookie', `pgv_auth=${tok}; HttpOnly; Secure; SameSite=Lax; Path=/; Max-Age=2592000`);
  res.status(200).json({ ok: true });
};
