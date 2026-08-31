// Munkamenet (session) + jelszókezelés — függőség nélkül, Node beépített crypto-val.
// A cookie egy aláírt tokent tartalmaz, amibe bele van ágyazva a felhasználó
// szerepe és egységei, így minden kéréskor tudjuk, ki mit láthat.
const crypto = require('crypto');
const { parseCookies } = require('./shops.js');

const ROLES = ['superadmin', 'unit_manager', 'cashier'];
const ROLE_LABELS = {
  superadmin: 'Központi admin',
  unit_manager: 'Egység-kezelő',
  cashier: 'Kasszás',
};

function secret() {
  return process.env.AUTH_SECRET || process.env.APP_PASSWORD || 'pgv-dev-secret';
}

function b64url(str) {
  return Buffer.from(str).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}
function b64urlDecode(str) {
  const s = str.replace(/-/g, '+').replace(/_/g, '/');
  return Buffer.from(s, 'base64').toString('utf8');
}
function hmac(v) {
  return crypto.createHmac('sha256', secret()).update(String(v)).digest('base64')
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// A tokenbe CSAK az azonosítót (és a mester-jelzőt) tesszük — a szerep/egységek
// minden kérésnél frissen az adatbázisból jönnek, így az átállítás/letiltás azonnal hat.
function makeSession(user) {
  const payload = { uid: user.id || 0, master: !!user.master, ts: Date.now() };
  const p = b64url(JSON.stringify(payload));
  return p + '.' + hmac(p);
}

// Csak a token aláírását/korát ellenőrzi; a nyers payloadot adja vissza.
function readSession(req) {
  const tok = parseCookies(req)['pgv_auth'];
  if (!tok) return null;
  const i = tok.lastIndexOf('.');
  if (i < 0) return null;
  const p = tok.slice(0, i), sig = tok.slice(i + 1);
  if (hmac(p) !== sig) return null;
  let payload;
  try { payload = JSON.parse(b64urlDecode(p)); } catch (e) { return null; }
  if (!payload || !payload.ts) return null;
  if (Date.now() - payload.ts > 30 * 864e5) return null; // 30 nap
  return payload;
}

// A tokenből feloldja az AKTUÁLIS felhasználót az adatbázisból (friss szerep/egység).
async function resolveUser(req) {
  const t = readSession(req);
  if (!t) return null;
  if (t.master) return { id: 0, email: 'admin', name: 'Központi admin', role: 'superadmin', units: [] };
  if (!t.uid) return null;
  const { ensureUsersSchema, getUserById } = require('./db.js');
  // Az adatbázis pillanatnyi hibája NEM jelenti azt, hogy a felhasználó nincs
  // bejelentkezve. Korábban minden ilyen hiba 401-et adott, amitől a felület
  // kidobta a belépőre — egy adatbázis-akadozás így folyamatos újra-bejelentkezést
  // okozott. Egyszer újrapróbáljuk, és ha úgy sem megy, jelezzük a hívónak, hogy
  // ez üzemzavar (503), nem hiányzó jogosultság.
  let u, lastErr = null;
  for (let attempt = 0; attempt < 2; attempt++) {
    try { await ensureUsersSchema(); u = await getUserById(t.uid); lastErr = null; break; }
    catch (e) { lastErr = e; if (attempt === 0) await new Promise(r => setTimeout(r, 300)); }
  }
  if (lastErr) { if (req) req.authUnavailable = true; return null; }
  if (!u || u.disabled) return null;
  return { id: u.id, email: u.email, name: u.name || u.email, role: u.role, units: u.units || [] };
}

// ---- jelszó (scrypt) ----
function hashPassword(pw) {
  const salt = crypto.randomBytes(16);
  const dk = crypto.scryptSync(String(pw), salt, 32);
  return 's1$' + salt.toString('hex') + '$' + dk.toString('hex');
}
function verifyPassword(pw, stored) {
  if (!stored) return false;
  const parts = String(stored).split('$');
  if (parts.length !== 3 || parts[0] !== 's1') return false;
  let dk;
  try { dk = crypto.scryptSync(String(pw), Buffer.from(parts[1], 'hex'), 32); } catch (e) { return false; }
  const exp = Buffer.from(parts[2], 'hex');
  return dk.length === exp.length && crypto.timingSafeEqual(dk, exp);
}

function canSeeUnit(session, unit) {
  if (!session) return false;
  if (session.role === 'superadmin') return true;
  const n = u => String(u == null ? '' : u).trim().toLowerCase();
  return Array.isArray(session.units) && session.units.map(n).includes(n(unit));
}

// A 401 (nincs jogosultság) és az 503 (átmenetileg nem elérhető) megkülönböztetése:
// az elsőre a felület belépőt mutat, a másodikra csak vár és újrapróbál.
function authFail(req, res) {
  if (req && req.authUnavailable) {
    res.status(503).json({ error: 'Az adatbázis pillanatnyilag nem elérhető. Újrapróbálkozás…', retry: true });
  } else {
    res.status(401).json({ error: 'auth' });
  }
}

module.exports = { ROLES, ROLE_LABELS, makeSession, readSession, resolveUser, authFail, hashPassword, verifyPassword, canSeeUnit, secret };
