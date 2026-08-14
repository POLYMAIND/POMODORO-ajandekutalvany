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
  _schemaReady = true;
}

function d(v) { // dátum/idő normalizálás: üres / 0000 -> null
  if (!v) return null;
  const s = String(v).trim();
  if (!s || s.startsWith('0000')) return null;
  return s;
}
function norm(v) {
  return {
    unit: String(v.unit || '').trim(),
    serial: String(v.serial || '').trim(),
    amount: v.amount != null ? (parseInt(v.amount, 10) || 0) : 0,
    status: v.status || null,
    giver_name: v.giver_name || null,
    recipient_name: v.recipient_name || null,
    delivery_email: v.delivery_email || null,
    buyer_email: v.buyer_email || null,
    marketing_opt_in: !!v.marketing_opt_in,
    valid_from: d(v.valid_from),
    valid_until: d(v.valid_until),
    redeemed_at: d(v.redeemed_at),
    is_legacy: !!v.is_legacy,
    created_at: d(v.created_at),
    updated_at: d(v.updated_at) || d(v.created_at),
  };
}

const COLS = ['unit', 'serial', 'amount', 'status', 'giver_name', 'recipient_name',
  'delivery_email', 'buyer_email', 'marketing_opt_in', 'valid_from', 'valid_until',
  'redeemed_at', 'is_legacy', 'created_at', 'updated_at'];

async function upsertVouchers(rows) {
  const clean = (rows || []).map(norm).filter(r => r.unit && r.serial);
  if (!clean.length) return 0;
  const sql = db();
  // Kötegelt upsert (a Neon max ~soronkénti paraméterszámot elbírja; batch-eljük).
  const BATCH = 200;
  let total = 0;
  for (let i = 0; i < clean.length; i += BATCH) {
    const chunk = clean.slice(i, i + BATCH);
    await sql`
      INSERT INTO pgv_vouchers ${sql(chunk, ...COLS)}
      ON CONFLICT (unit, serial) DO UPDATE SET
        amount = EXCLUDED.amount,
        status = EXCLUDED.status,
        giver_name = EXCLUDED.giver_name,
        recipient_name = EXCLUDED.recipient_name,
        delivery_email = EXCLUDED.delivery_email,
        buyer_email = EXCLUDED.buyer_email,
        marketing_opt_in = EXCLUDED.marketing_opt_in,
        valid_from = EXCLUDED.valid_from,
        valid_until = EXCLUDED.valid_until,
        redeemed_at = EXCLUDED.redeemed_at,
        is_legacy = EXCLUDED.is_legacy,
        created_at = EXCLUDED.created_at,
        updated_at = EXCLUDED.updated_at,
        ingested_at = now()
    `;
    total += chunk.length;
  }
  return total;
}

async function allVouchers() {
  const sql = db();
  return await sql`SELECT * FROM pgv_vouchers ORDER BY created_at DESC NULLS LAST`;
}

module.exports = { db, ensureSchema, upsertVouchers, allVouchers };
