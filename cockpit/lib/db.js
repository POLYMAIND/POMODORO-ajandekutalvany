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
  'postcode', 'city', 'street', 'message', 'delivery_date', 'redeem_method',
  'buyer_note', 'promo_code', 'payment_provider', 'paid_at', 'print_serial'];

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
    ADD COLUMN IF NOT EXISTS paid_at timestamptz,
    ADD COLUMN IF NOT EXISTS print_serial text`;
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
    delivery_date: d(v.delivery_date),
    redeem_method: s(v.redeem_method),
    buyer_note: s(v.buyer_note),
    promo_code: s(v.promo_code),
    payment_provider: s(v.payment_provider),
    paid_at: d(v.paid_at),
    print_serial: s(v.print_serial),
  };
}

const CORE_COLS = ['unit', 'serial', 'amount', 'status', 'giver_name', 'recipient_name',
  'delivery_email', 'buyer_email', 'marketing_opt_in', 'valid_from', 'valid_until',
  'redeemed_at', 'is_legacy', 'created_at', 'updated_at'];
const COLS = CORE_COLS.concat(EXTRA_COLS);

async function upsertVouchers(rows) {
  const clean = (rows || []).map(norm).filter(r => r.unit && r.serial);
  if (!clean.length) return 0;
  const sql = db();
  // A core mezőket felülírjuk; az extra CRM mezőket COALESCE-szal védjük.
  const coreSet = CORE_COLS.filter(c => c !== 'unit' && c !== 'serial')
    .map(c => `${c} = EXCLUDED.${c}`);
  const extraSet = EXTRA_COLS.map(c => `${c} = COALESCE(EXCLUDED.${c}, pgv_vouchers.${c})`);
  const setSql = coreSet.concat(extraSet).join(', ') + ', ingested_at = now()';

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

async function allVouchers() {
  const sql = db();
  return await sql`SELECT * FROM pgv_vouchers ORDER BY created_at DESC NULLS LAST`;
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
  db, ensureSchema, upsertVouchers, allVouchers,
  ensureUsersSchema, countUsers, getUserByEmail, getUserById, listUsers, createUser, updateUser, deleteUser,
};
