// Közös szerveroldali segédek: bolt-konfiguráció, hitelesítés, bolt-API hívás.
const crypto = require('crypto');

// A boltok konfigurációja a SHOPS környezeti változóból (JSON tömb):
// [{ "slug":"casa","name":"Casa Pomo d'Oro","prefix":"CASA",
//    "url":"https://casa.pomodorobudapest.com","apiKey":"pk_..." }, ...]
function getShops() {
  try {
    const arr = JSON.parse(process.env.SHOPS || '[]');
    return Array.isArray(arr) ? arr.filter(s => s && s.url && s.apiKey) : [];
  } catch (e) {
    return [];
  }
}

function secret() {
  return process.env.AUTH_SECRET || process.env.APP_PASSWORD || 'pgv-dev-secret';
}
function sign(v) {
  return crypto.createHmac('sha256', secret()).update(String(v)).digest('hex');
}
function makeToken() {
  const t = Date.now().toString();
  return t + '.' + sign(t);
}
function checkToken(tok) {
  if (!tok) return false;
  const i = tok.indexOf('.');
  if (i < 0) return false;
  const t = tok.slice(0, i), sig = tok.slice(i + 1);
  if (sign(t) !== sig) return false;
  return (Date.now() - parseInt(t, 10)) < 30 * 864e5; // 30 nap
}
function parseCookies(req) {
  const h = req.headers.cookie || '';
  const o = {};
  h.split(';').forEach(p => {
    const i = p.indexOf('=');
    if (i > 0) o[p.slice(0, i).trim()] = decodeURIComponent(p.slice(i + 1).trim());
  });
  return o;
}
function isAuthed(req) {
  return checkToken(parseCookies(req)['pgv_auth']);
}
async function readBody(req) {
  if (req.body && typeof req.body === 'object') return req.body;
  return await new Promise(resolve => {
    let d = '';
    req.on('data', c => (d += c));
    req.on('end', () => { try { resolve(JSON.parse(d || '{}')); } catch (e) { resolve({}); } });
    req.on('error', () => resolve({}));
  });
}

async function rawFetch(url, headers, opts) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 12000);
  try {
    const r = await fetch(url, Object.assign({}, opts, { headers, signal: ctrl.signal }));
    const text = await r.text();
    let json = null;
    try { json = text ? JSON.parse(text) : null; } catch (e) { json = null; }
    return { ok: r.ok, status: r.status, json };
  } finally {
    clearTimeout(timer);
  }
}

async function shopFetch(shop, path, opts = {}) {
  const base = shop.url.replace(/\/+$/, '') + '/wp-json/pgv/v1' + path;
  const ua = 'Mozilla/5.0 (compatible; PomodoroCockpit/1.0; +https://polymaind.hu)';

  // 1) kulcs a fejlécben (ajánlott)
  const headers = Object.assign({
    'x-api-key': shop.apiKey,
    'accept': 'application/json',
    'user-agent': ua,
  }, opts.headers || {});
  let res = await rawFetch(base, headers, opts);
  if (res.ok || (res.status && res.status !== 401 && res.status !== 403 && res.status !== 406)) {
    return res;
  }

  // 2) fallback: kulcs query-ben, egyszerű fejlécekkel (WAF-kerülő)
  const sep = base.includes('?') ? '&' : '?';
  const url2 = base + sep + 'api_key=' + encodeURIComponent(shop.apiKey);
  const headers2 = Object.assign({ 'accept': 'application/json', 'user-agent': ua }, opts.headers || {});
  const res2 = await rawFetch(url2, headers2, opts);
  return res2.ok ? res2 : res; // ha egyik sem, az elsődleges hibát adjuk vissza
}

module.exports = { getShops, makeToken, isAuthed, parseCookies, readBody, shopFetch, sign };
