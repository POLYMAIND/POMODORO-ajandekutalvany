// Közös szerveroldali segédek: bolt-konfiguráció, hitelesítés, bolt-API hívás.
const crypto = require('crypto');

// A boltok konfigurációja a SHOPS környezeti változóból (JSON tömb):
// [{ "slug":"casa","name":"Casa Pomo d'Oro","prefix":"CASA",
//    "url":"https://casa.pomodorobudapest.com","apiKey":"pk_..." }, ...]
function getShops() {
  try {
    const arr = JSON.parse(process.env.SHOPS || '[]');
    // Csak a slug kötelező: a push-alapú boltnál (a bolt küld, mi nem hívjuk) nincs szükség
    // url-re és CRM API-kulcsra. A régi, teljes sorok változatlanul működnek.
    return Array.isArray(arr) ? arr.filter(s => s && /^[a-z0-9_-]{1,40}$/i.test(String(s.slug || ''))) : [];
  } catch (e) {
    return [];
  }
}

// A MÁRKA (fehér címkés telepítés): ügyfelenként külön Vercel-projekt, a nevet, logót és a
// kiemelő színt környezeti változó adja. Üresen a semleges „Ajándékutalvány” felirat jelenik meg.
function getBrand() {
  const color = String(process.env.BRAND_COLOR || '').trim();
  const logo = String(process.env.BRAND_LOGO_URL || '').trim();
  return {
    name: String(process.env.BRAND_NAME || '').trim().slice(0, 80) || 'Ajándékutalvány',
    logo_url: /^https:\/\//i.test(logo) ? logo.slice(0, 500) : '',
    color: /^#[0-9a-f]{6}$/i.test(color) ? color : '',
  };
}

// A bolt felküldésének hitelesítése a POLYMAIND-licenccel (a bővítmény 2.0-tól ezt küldi, ha nincs
// külön ingest-titok beállítva). A projektben CSAK a licenc sha256-hash-e van (VOUCHER_LICENCE_SHA256),
// maga a kulcs nem. Időzítés-biztos összehasonlítás.
function licenceOk(req) {
  const want = String(process.env.VOUCHER_LICENCE_SHA256 || '').trim().toLowerCase();
  if (!/^[a-f0-9]{64}$/.test(want)) return false;
  const h = String((req.headers && (req.headers.authorization || req.headers.Authorization)) || '');
  const m = /^Bearer\s+(vfl_[A-Za-z0-9_-]{16,76})$/.exec(h.trim());
  if (!m) return false;
  const got = crypto.createHash('sha256').update(m[1]).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(got), Buffer.from(want));
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

module.exports = { getBrand, licenceOk, getShops, makeToken, isAuthed, parseCookies, readBody, shopFetch, sign };
