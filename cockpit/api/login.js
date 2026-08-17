const { readBody } = require('../lib/shops.js');
const { ensureUsersSchema, getUserByEmail } = require('../lib/db.js');
const { makeSession, verifyPassword } = require('../lib/auth.js');

// Belépés: e-mail + jelszó (valódi felhasználók), vagy üres e-mail + APP_PASSWORD
// mester-belépés (mindig van bejárat a központi adminhoz).
module.exports = async (req, res) => {
  if (req.method !== 'POST') { res.status(405).json({ error: 'POST' }); return; }
  const body = await readBody(req);
  const email = String((body && body.email) || '').trim();
  const pass = String((body && body.password) || '');

  if (!pass) { res.status(400).json({ error: 'Adj meg jelszót.' }); return; }

  let session = null;

  // 1) Valódi felhasználó az adatbázisból.
  if (email && email.toLowerCase() !== 'admin' && email.toLowerCase() !== 'master') {
    try {
      await ensureUsersSchema();
      const u = await getUserByEmail(email);
      if (u && !u.disabled && verifyPassword(pass, u.pass_hash)) {
        session = { id: u.id, email: u.email, name: u.name || u.email, role: u.role, units: u.units || [] };
      } else if (u) {
        res.status(401).json({ error: u.disabled ? 'A fiók le van tiltva.' : 'Hibás e-mail vagy jelszó.' });
        return;
      }
    } catch (e) {
      // DB elérhetetlen — essünk vissza a mesterjelszóra alább.
    }
  }

  // 2) Mester-belépés: APP_PASSWORD (üres vagy "admin" e-maillel) → központi admin.
  if (!session && process.env.APP_PASSWORD && pass === process.env.APP_PASSWORD
      && (!email || email.toLowerCase() === 'admin' || email.toLowerCase() === 'master')) {
    session = { id: 0, master: true, name: 'Központi admin', role: 'superadmin' };
  }

  if (!session) { res.status(401).json({ error: 'Hibás e-mail vagy jelszó.' }); return; }

  const tok = makeSession(session);
  res.setHeader('Set-Cookie', `pgv_auth=${tok}; HttpOnly; Secure; SameSite=Lax; Path=/; Max-Age=2592000`);
  res.status(200).json({ ok: true, role: session.role, name: session.name });
};
