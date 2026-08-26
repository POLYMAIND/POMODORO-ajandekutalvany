const { readBody, getShops } = require('../lib/shops.js');
const { resolveUser, hashPassword, ROLES, ROLE_LABELS } = require('../lib/auth.js');
const { ensureUsersSchema, listUsers, getUserByEmail, createUser, updateUser, deleteUser } = require('../lib/db.js');

// Felhasználó- és jogosultságkezelés — kizárólag központi admin (superadmin).
module.exports = async (req, res) => {
  const s = await resolveUser(req);
  if (!s) { res.status(401).json({ error: 'auth' }); return; }
  if (s.role !== 'superadmin') { res.status(403).json({ error: 'Csak központi admin.' }); return; }

  try {
    await ensureUsersSchema();
  } catch (e) {
    res.status(500).json({ error: 'DB: ' + String(e && e.message || e) }); return;
  }

  const units = getShops().map(x => ({ slug: x.slug, name: x.name }));

  if (req.method === 'GET') {
    const users = await listUsers();
    res.status(200).json({ users, roles: ROLES, roleLabels: ROLE_LABELS, units });
    return;
  }

  if (req.method !== 'POST') { res.status(405).json({ error: 'method' }); return; }

  const body = await readBody(req);
  const action = (body && body.action) || 'create';

  try {
    if (action === 'create') {
      const email = String(body.email || '').trim().toLowerCase();
      if (!email || !body.password) { res.status(400).json({ error: 'E-mail és jelszó kötelező.' }); return; }
      if (email === 'admin' || email === 'master') { res.status(400).json({ error: 'Ez az e-mail fenntartott.' }); return; }
      if (await getUserByEmail(email)) { res.status(409).json({ error: 'Ez az e-mail már foglalt.' }); return; }
      const role = ROLES.includes(body.role) ? body.role : 'cashier';
      const list = role === 'superadmin' ? [] : sanitizeUnits(body.units, units);
      const id = await createUser({ email, name: String(body.name || ''), pass_hash: hashPassword(String(body.password)), role, units: list });
      res.status(200).json({ ok: true, id });
      return;
    }

    if (action === 'update') {
      const id = Number(body.id);
      if (!id) { res.status(400).json({ error: 'Hiányzó azonosító.' }); return; }
      const patch = {};
      if (body.name != null) patch.name = String(body.name);
      // Belépési e-mail módosítása (csak központi admin jut el idáig).
      if (body.email != null) {
        const email = String(body.email).trim().toLowerCase();
        if (!email || /\s/.test(email)) { res.status(400).json({ error: 'Az e-mail nem lehet üres.' }); return; }
        if (email === 'admin' || email === 'master') { res.status(400).json({ error: 'Ez az e-mail fenntartott.' }); return; }
        const other = await getUserByEmail(email);
        if (other && Number(other.id) !== id) { res.status(409).json({ error: 'Ez az e-mail már foglalt.' }); return; }
        patch.email = email;
      }
      if (body.role && ROLES.includes(body.role)) patch.role = body.role;
      if (body.units != null || body.role) {
        const role = patch.role || body.role;
        patch.units = role === 'superadmin' ? [] : sanitizeUnits(body.units, units);
      }
      if (body.disabled != null) patch.disabled = !!body.disabled;
      if (body.password) patch.pass_hash = hashPassword(String(body.password));
      await updateUser(id, patch);
      res.status(200).json({ ok: true });
      return;
    }

    if (action === 'delete') {
      const id = Number(body.id);
      if (!id) { res.status(400).json({ error: 'Hiányzó azonosító.' }); return; }
      await deleteUser(id);
      res.status(200).json({ ok: true });
      return;
    }

    res.status(400).json({ error: 'Ismeretlen művelet.' });
  } catch (e) {
    // Párhuzamos mentésnél az egyedi e-mail index üthet — ne nyers DB-hiba menjen ki.
    if (e && (e.code === '23505' || /duplicate key/i.test(String(e.message || '')))) {
      res.status(409).json({ error: 'Ez az e-mail már foglalt.' }); return;
    }
    res.status(500).json({ error: String(e && e.message || e) });
  }
};

function sanitizeUnits(input, units) {
  const valid = new Set(units.map(u => u.slug));
  return (Array.isArray(input) ? input : []).map(String).filter(u => valid.has(u));
}
