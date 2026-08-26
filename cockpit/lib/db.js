// Neon Postgres réteg (porsager/postgres driver). A kapcsolati stringet
// automatikusan megtaláljuk a Vercel/Neon által létrehozott env-változók közül.
const postgres = require('postgres');

function connString() {
  const candidates = [
    'DATABASE_URL', 'POSTGRES_URL', 'POSTGRES_PRISMA_URL',
    'STORAGE_URL', 'STORAGE_DATABASE_URL', 'STORAGE_POSTGRES_URL',
    'POSTGRES_URL_NON_POOLING', 'DATABASE_URL_UNPOOLED', 'NEON_DATABASE_URL',
  ];
  for (const k of candidates) {
    const v = process.env[k];
    if (v && /^postgres(ql)?:\/\//.test(v)) return v;
  }
  return null;
}

let _sql = null;
function db() {
  if (_sql) return _sql;
  const cs = connString();
  if (!cs) throw new Error('Nincs Postgres kapcsolati string (DATABASE_URL/POSTGRES_URL…).');
  _sql = postgres(cs, { ssl: 'require', prepare: false, max: 1, idle_timeout: 10 });
  return _sql;
}

// Extra (import-only) CRM oszlopok — a plugin push nem küldi ezeket, ezért
// upsertnél COALESCE-szal védjük, hogy egy későbbi push ne nullázza őket.
const EXTRA_COLS = ['order_ref', 'label', 'buyer_name', 'buyer_phone', 'country',
  'postcode', 'city', 'street', 'message',
  'buyer_note', 'promo_code', 'payment_provider', 'transaction_id', 'paid_at',
  'site_url'];

let _schemaReady = false;
async function ensureSchema() {
  if (_schemaReady) return;
  const sql = db();
  await sql`CREATE TABLE IF NOT EXISTS pgv_vouchers (
    unit text NOT NULL,
    serial text NOT NULL,
    amount bigint DEFAULT 0,
    status text,
    giver_name text,
    recipient_name text,
    delivery_email text,
    buyer_email text,
    marketing_opt_in boolean DEFAULT false,
    valid_from date,
    valid_until date,
    redeemed_at timestamptz,
    is_legacy boolean DEFAULT false,
    created_at timestamptz,
    updated_at timestamptz,
    ingested_at timestamptz DEFAULT now(),
    PRIMARY KEY (unit, serial)
  )`;
  // Extra CRM oszlopok (idempotens bővítés meglévő táblán is).
  await sql`ALTER TABLE pgv_vouchers
    ADD COLUMN IF NOT EXISTS order_ref text,
    ADD COLUMN IF NOT EXISTS wc_order_id text,
    ADD COLUMN IF NOT EXISTS label text,
    ADD COLUMN IF NOT EXISTS buyer_name text,
    ADD COLUMN IF NOT EXISTS buyer_phone text,
    ADD COLUMN IF NOT EXISTS country text,
    ADD COLUMN IF NOT EXISTS postcode text,
    ADD COLUMN IF NOT EXISTS city text,
    ADD COLUMN IF NOT EXISTS street text,
    ADD COLUMN IF NOT EXISTS message text,
    ADD COLUMN IF NOT EXISTS delivery_date date,
    ADD COLUMN IF NOT EXISTS redeem_method text,
    ADD COLUMN IF NOT EXISTS buyer_note text,
    ADD COLUMN IF NOT EXISTS promo_code text,
    ADD COLUMN IF NOT EXISTS payment_provider text,
    ADD COLUMN IF NOT EXISTS transaction_id text,
    ADD COLUMN IF NOT EXISTS paid_at timestamptz,
    ADD COLUMN IF NOT EXISTS print_serial text,
    ADD COLUMN IF NOT EXISTS site_url text,
    ADD COLUMN IF NOT EXISTS redeemed_by text,
    ADD COLUMN IF NOT EXISTS redeemed_via text,
    ADD COLUMN IF NOT EXISTS reminder_sent_at timestamptz`;
  // Az utalvány-PDF-ek külön táblában (a plugin push-olja fel base64-ben, mert a
  // bolt bejövő REST-hívását a tárhely bot-védelme blokkolja). Külön tábla, hogy a
  // fő lekérdezéseket (allVouchers, data) ne terhelje a nagy szöveges mező.
  await sql`CREATE TABLE IF NOT EXISTS pgv_pdfs (
    unit text NOT NULL,
    serial text NOT NULL,
    pdf_base64 text,
    updated_at timestamptz DEFAULT now(),
    PRIMARY KEY (unit, serial)
  )`;
  _schemaReady = true;
}

function d(v) { // dátum/idő normalizálás: üres / 0000 -> null
  if (!v) return null;
  const s = String(v).trim();
  if (!s || s.startsWith('0000')) return null;
  return s;
}
function s(v) { // szöveg: üres -> null
  if (v == null) return null;
  const t = String(v).trim();
  return t === '' ? null : t;
}
function norm(v) {
  return {
    unit: String(v.unit || '').trim(),
    serial: String(v.serial || '').trim(),
    amount: v.amount != null ? (parseInt(v.amount, 10) || 0) : 0,
    status: v.status || null,
    giver_name: s(v.giver_name),
    recipient_name: s(v.recipient_name),
    delivery_email: s(v.delivery_email),
    buyer_email: s(v.buyer_email),
    marketing_opt_in: !!v.marketing_opt_in,
    valid_from: d(v.valid_from),
    valid_until: d(v.valid_until),
    redeemed_at: d(v.redeemed_at),
    is_legacy: !!v.is_legacy,
    created_at: d(v.created_at),
    updated_at: d(v.updated_at) || d(v.created_at),
    // extra
    order_ref: s(v.order_ref),
    label: s(v.label),
    buyer_name: s(v.buyer_name),
    buyer_phone: s(v.buyer_phone),
    country: s(v.country),
    postcode: s(v.postcode),
    city: s(v.city),
    street: s(v.street),
    message: s(v.message),
    buyer_note: s(v.buyer_note),
    promo_code: s(v.promo_code),
    payment_provider: s(v.payment_provider),
    transaction_id: s(v.transaction_id),
    paid_at: d(v.paid_at),
    site_url: s(v.site_url),
  };
}

const CORE_COLS = ['unit', 'serial', 'amount', 'status', 'giver_name', 'recipient_name',
  'delivery_email', 'buyer_email', 'marketing_opt_in', 'valid_from', 'valid_until',
  'redeemed_at', 'is_legacy', 'created_at', 'updated_at'];
const COLS = CORE_COLS.concat(EXTRA_COLS);

// A vezérlőpulton (kasszán) végzett beváltást a bolt push-a nem írhatja vissza
// „aktív”-ra: a bolt saját adatbázisa erről a beváltásról nem tud, és egy
// sync_all különben csendben eltüntetné a napi beváltásokat. Ha a boltban
// váltják be (onnan jön redeemed / redeemed_at), az normálisan felülír.
const GUARDED_COLS = ['status', 'redeemed_at'];
const GUARDED_SET = [
  `status = CASE WHEN pgv_vouchers.redeemed_via = 'cockpit' AND pgv_vouchers.status = 'redeemed'
      AND EXCLUDED.status IS DISTINCT FROM 'redeemed' THEN pgv_vouchers.status ELSE EXCLUDED.status END`,
  `redeemed_at = CASE WHEN pgv_vouchers.redeemed_via = 'cockpit' AND pgv_vouchers.status = 'redeemed'
      AND EXCLUDED.redeemed_at IS NULL THEN pgv_vouchers.redeemed_at ELSE EXCLUDED.redeemed_at END`,
];

async function upsertVouchers(rows) {
  const clean = (rows || []).map(norm).filter(r => r.unit && r.serial);
  if (!clean.length) return 0;
  const sql = db();
  // A core mezőket felülírjuk; az extra CRM mezőket COALESCE-szal védjük.
  const coreSet = CORE_COLS.filter(c => c !== 'unit' && c !== 'serial' && !GUARDED_COLS.includes(c))
    .map(c => `${c} = EXCLUDED.${c}`);
  const extraSet = EXTRA_COLS.map(c => `${c} = COALESCE(EXCLUDED.${c}, pgv_vouchers.${c})`);
  const setSql = coreSet.concat(GUARDED_SET, extraSet).join(', ') + ', ingested_at = now()';

  const BATCH = 200;
  let total = 0;
  for (let i = 0; i < clean.length; i += BATCH) {
    const chunk = clean.slice(i, i + BATCH);
    await sql`
      INSERT INTO pgv_vouchers ${sql(chunk, ...COLS)}
      ON CONFLICT (unit, serial) DO UPDATE SET ${sql.unsafe(setSql)}
    `;
    total += chunk.length;
  }
  return total;
}

// Utalvány-PDF-ek eltárolása a push payloadból (base64). Külön táblába, kis kötegekben
// (a serverless kérés-törzs korlát miatt a plugin úgyis tételenként küldi fel).
async function upsertVoucherPdfs(rows) {
  const items = (rows || []).map(v => ({
    unit: String((v && v.unit) || '').trim(),
    serial: String((v && v.serial) || '').trim(),
    pdf_base64: (v && typeof v.pdf_base64 === 'string' && v.pdf_base64.trim()) ? v.pdf_base64.trim() : null,
  })).filter(r => r.unit && r.serial && r.pdf_base64);
  if (!items.length) return 0;
  const sql = db();
  const BATCH = 10;
  let total = 0;
  for (let i = 0; i < items.length; i += BATCH) {
    const chunk = items.slice(i, i + BATCH);
    await sql`INSERT INTO pgv_pdfs ${sql(chunk, 'unit', 'serial', 'pdf_base64')}
      ON CONFLICT (unit, serial) DO UPDATE SET pdf_base64 = EXCLUDED.pdf_base64, updated_at = now()`;
    total += chunk.length;
  }
  return total;
}

async function getVoucherPdf(unit, serial) {
  const sql = db();
  const r = await sql`SELECT pdf_base64 FROM pgv_pdfs
    WHERE lower(unit) = lower(${String(unit)}) AND serial = ${String(serial)} LIMIT 1`;
  return (r[0] && r[0].pdf_base64) || null;
}

async function allVouchers() {
  const sql = db();
  return await sql`SELECT * FROM pgv_vouchers ORDER BY created_at DESC NULLS LAST`;
}

async function getVoucher(unit, serial) {
  const sql = db();
  const r = await sql`SELECT * FROM pgv_vouchers
    WHERE lower(unit) = lower(${String(unit)}) AND serial = ${String(serial)} LIMIT 1`;
  return r[0] || null;
}

// A rendszer a helyi (budapesti) faliórát tárolja — ezt küldi fel a bolt plugin is
// (current_time('mysql')), és a felület is így jeleníti meg. Így a napi
// összesítő aznapra esik akkor is, ha a szerver UTC-ben jár.
function nowLocal(sql) { return sql`(now() AT TIME ZONE 'Europe/Budapest')`; }

// Atomikus beváltás: CSAK aktív + nem lejárt utalvány váltható be, egyszer.
// A WHERE-feltétel biztosítja, hogy párhuzamos hívásnál se legyen dupla beváltás.
async function redeemVoucher(unit, serial, actor) {
  const sql = db();
  const by = actor && (actor.name || actor.email) ? String(actor.name || actor.email) : null;
  const rows = await sql`UPDATE pgv_vouchers
    SET status = 'redeemed', redeemed_at = ${nowLocal(sql)}, redeemed_by = ${by}, redeemed_via = 'cockpit'
    WHERE lower(unit) = lower(${String(unit)}) AND serial = ${String(serial)}
      AND status = 'active'
      AND (valid_until IS NULL OR valid_until >= CURRENT_DATE)
    RETURNING *`;
  return rows[0] || null;
}

// Beváltás visszavonása (téves beváltás javítása): csak beváltott utalványon,
// és csak egyszer — a WHERE status = 'redeemed' teszi atomikussá.
async function unredeemVoucher(unit, serial) {
  const sql = db();
  const rows = await sql`UPDATE pgv_vouchers
    SET status = 'active', redeemed_at = NULL, redeemed_by = NULL, redeemed_via = NULL
    WHERE lower(unit) = lower(${String(unit)}) AND serial = ${String(serial)}
      AND status = 'redeemed'
    RETURNING *`;
  return rows[0] || null;
}

// ---- Beváltási napló (ki, mikor, mit váltott be / állított vissza) ----
// A visszaállítás nyoma nélkül a napi összesítő utólag észrevétlenül módosulhatna,
// ezért minden beváltás és visszaállítás bekerül ide.
let _logReady = false;
async function ensureLogSchema() {
  if (_logReady) return;
  const sql = db();
  await sql`CREATE TABLE IF NOT EXISTS pgv_voucher_log (
    id bigserial PRIMARY KEY,
    unit text NOT NULL,
    serial text NOT NULL,
    action text NOT NULL,
    amount bigint,
    user_id integer,
    user_name text,
    created_at timestamptz DEFAULT now()
  )`;
  // A visszaállítás sorába eltesszük, mit írtunk felül (idempotens bővítés meglévő táblán is).
  await sql`ALTER TABLE pgv_voucher_log
    ADD COLUMN IF NOT EXISTS prev_redeemed_at timestamptz,
    ADD COLUMN IF NOT EXISTS prev_redeemed_by text`;
  await sql`CREATE INDEX IF NOT EXISTS pgv_voucher_log_created_idx ON pgv_voucher_log (created_at DESC)`;
  _logReady = true;
}

async function logVoucherAction(e) {
  await ensureLogSchema();
  const sql = db();
  const u = e.user || {};
  await sql`INSERT INTO pgv_voucher_log
      (unit, serial, action, amount, user_id, user_name, prev_redeemed_at, prev_redeemed_by, created_at)
    VALUES (${String(e.unit)}, ${String(e.serial)}, ${String(e.action)}, ${e.amount != null ? Number(e.amount) : null},
      ${u.id != null ? Number(u.id) : null}, ${u.name || u.email || null},
      ${e.prev_redeemed_at || null}, ${e.prev_redeemed_by || null}, ${nowLocal(sql)})`;
}

// Az utóbbi N nap naplója (a napi/időszaki önellenőrzéshez).
// `action` megadásával csak az adott műveletet adja vissza — a vezérlőpult így
// csak a ritka visszaállításokat kéri le, nem az összes beváltást.
async function recentVoucherLog(days, action) {
  await ensureLogSchema();
  const sql = db();
  const n = Math.max(1, Math.min(3650, parseInt(days, 10) || 90));
  const since = sql`${nowLocal(sql)} - ${sql.unsafe("interval '" + n + " days'")}`;
  const cols = sql`unit, serial, action, amount, user_name, prev_redeemed_at, prev_redeemed_by, created_at`;
  if (action) {
    return await sql`SELECT ${cols} FROM pgv_voucher_log
      WHERE created_at >= ${since} AND action = ${String(action)}
      ORDER BY created_at DESC`;
  }
  return await sql`SELECT ${cols} FROM pgv_voucher_log
    WHERE created_at >= ${since}
    ORDER BY created_at DESC`;
}

// Emlékeztető-küldés megjelölése (hogy ne menjen ki kétszer ugyanarra).
async function markReminderSent(unit, serial) {
  const sql = db();
  const rows = await sql`UPDATE pgv_vouchers SET reminder_sent_at = now()
    WHERE lower(unit) = lower(${String(unit)}) AND serial = ${String(serial)} RETURNING *`;
  return rows[0] || null;
}

// Egy egység importált (legacy) utalványainak törlése — az import visszavonásához.
async function deleteLegacyByUnit(unit) {
  const sql = db();
  const r = await sql`DELETE FROM pgv_vouchers
    WHERE lower(unit) = lower(${String(unit)}) AND is_legacy = true`;
  return r.count || 0;
}

// ---- Konfiguráció (kulcs-érték, pl. e-mail/Brevo beállítások) ----
let _cfgReady = false;
async function ensureConfigSchema() {
  if (_cfgReady) return;
  const sql = db();
  await sql`CREATE TABLE IF NOT EXISTS pgv_config (
    k text PRIMARY KEY,
    v jsonb,
    updated_at timestamptz DEFAULT now()
  )`;
  _cfgReady = true;
}
async function getConfig(key) {
  await ensureConfigSchema();
  const sql = db();
  const r = await sql`SELECT v FROM pgv_config WHERE k = ${String(key)} LIMIT 1`;
  return r[0] ? r[0].v : null;
}
async function setConfig(key, value) {
  await ensureConfigSchema();
  const sql = db();
  await sql`INSERT INTO pgv_config (k, v, updated_at) VALUES (${String(key)}, ${sql.json(value)}, now())
    ON CONFLICT (k) DO UPDATE SET v = EXCLUDED.v, updated_at = now()`;
}

// ---- Felhasználók (többfelhasználós admin) ----
let _usersReady = false;
async function ensureUsersSchema() {
  if (_usersReady) return;
  const sql = db();
  await sql`CREATE TABLE IF NOT EXISTS pgv_users (
    id serial PRIMARY KEY,
    email text UNIQUE NOT NULL,
    name text,
    pass_hash text NOT NULL,
    role text NOT NULL DEFAULT 'cashier',
    units text[] NOT NULL DEFAULT '{}',
    disabled boolean NOT NULL DEFAULT false,
    created_at timestamptz DEFAULT now()
  )`;
  _usersReady = true;
}

async function countUsers() {
  const sql = db();
  const r = await sql`SELECT count(*)::int AS n FROM pgv_users`;
  return r[0] ? r[0].n : 0;
}
async function getUserByEmail(email) {
  const sql = db();
  const r = await sql`SELECT * FROM pgv_users WHERE lower(email) = lower(${String(email)}) LIMIT 1`;
  return r[0] || null;
}
async function getUserById(id) {
  const sql = db();
  const r = await sql`SELECT * FROM pgv_users WHERE id = ${Number(id)} LIMIT 1`;
  return r[0] || null;
}
async function listUsers() {
  const sql = db();
  return await sql`SELECT id, email, name, role, units, disabled, created_at
    FROM pgv_users ORDER BY created_at ASC, id ASC`;
}
async function createUser(u) {
  const sql = db();
  const r = await sql`INSERT INTO pgv_users (email, name, pass_hash, role, units)
    VALUES (${u.email}, ${u.name || ''}, ${u.pass_hash}, ${u.role}, ${u.units || []})
    RETURNING id`;
  return r[0].id;
}
async function updateUser(id, patch) {
  const sql = db();
  const cols = Object.keys(patch);
  if (!cols.length) return;
  await sql`UPDATE pgv_users SET ${sql(patch, ...cols)} WHERE id = ${Number(id)}`;
}
async function deleteUser(id) {
  const sql = db();
  await sql`DELETE FROM pgv_users WHERE id = ${Number(id)}`;
}

module.exports = {
  db, ensureSchema, upsertVouchers, upsertVoucherPdfs, getVoucherPdf, allVouchers, getVoucher,
  redeemVoucher, unredeemVoucher, markReminderSent, deleteLegacyByUnit,
  ensureLogSchema, logVoucherAction, recentVoucherLog,
  ensureConfigSchema, getConfig, setConfig,
  ensureUsersSchema, countUsers, getUserByEmail, getUserById, listUsers, createUser, updateUser, deleteUser,
};
