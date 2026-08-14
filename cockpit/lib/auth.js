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

// user: { id, email, name, role, units[] }
function makeSession(user) {
  const payload = {
    uid: user.id || 0,
    email: user.email || '',
    name: user.name || '',
    role: ROLES.includes(user.role) ? user.role : 'cashier',
    units: Array.isArray(user.units) ? user.units : [],
    ts: Date.now(),
  };
  const p = b64url(JSON.stringify(payload));
  return p + '.' + hmac(p);
}

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
  if (!Array.isArray(payload.units)) payload.units = [];
  return payload;
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
  return Array.isArray(session.units) && session.units.includes(unit);
}

module.exports = { ROLES, ROLE_LABELS, makeSession, readSession, hashPassword, verifyPassword, canSeeUnit, secret };
