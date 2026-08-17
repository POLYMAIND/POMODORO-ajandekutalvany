// A kanonikus utalvány-CSV formátum: fejléc <-> belső mező leképezés, státusz-fordítás.
// Ez az import ÉS az export közös igazsága.

// Sorrend = export oszlopsorrend (az "egység" az export elején külön kezelve).
const FIELDS = [
  ['utalvány kódja', 'serial'],
  ['megrendelési azonosító', 'order_ref'],
  ['WooCommerce rendelésszám', 'wc_order_id'],
  ['utalvány neve', 'label'],
  ['érték', 'amount'],
  ['státusz', 'status'],
  ['vásárlás időpontja', 'created_at'],
  ['érvényesség kezdete', 'valid_from'],
  ['érvényesség vége', 'valid_until'],
  ['felhasználás dátuma', 'redeemed_at'],
  ['vevő neve', 'buyer_name'],
  ['vevő e-mail címe', 'buyer_email'],
  ['vevő telefonszáma', 'buyer_phone'],
  ['ország', 'country'],
  ['irányítószám', 'postcode'],
  ['település', 'city'],
  ['utca, házszám', 'street'],
  ['ajándékozó neve', 'giver_name'],
  ['megajándékozott neve', 'recipient_name'],
  ['üzenet a megajándékozottnak', 'message'],
  ['kézbesítési e-mail cím', 'delivery_email'],
  ['vevő megjegyzése', 'buyer_note'],
  ['promóciós kód', 'promo_code'],
  ['fizetési szolgáltató', 'payment_provider'],
  ['fizetési azonosító', 'transaction_id'],
  ['hírlevél', 'marketing_opt_in'],
];

// Elfogadott fejléc-nevek mezőnként (a kanonikus + a régi SimplePay/WP-admin exportok).
const ALIASES = {
  serial: ['utalvány kódja', 'azonosító', 'sorszám', 'serial'],
  order_ref: ['megrendelési azonosító'],
  wc_order_id: ['woocommerce rendelésszám'],
  label: ['utalvány neve'],
  amount: ['érték', 'ár', 'összeg', 'amount'],
  status: ['státusz', 'status'],
  created_at: ['vásárlás időpontja', 'vásárlás dátuma'],
  valid_from: ['érvényesség kezdete'],
  valid_until: ['érvényesség vége', 'lejárat'],
  redeemed_at: ['felhasználás dátuma'],
  buyer_name: ['vevő neve'],
  buyer_email: ['vevő e-mail címe', 'email', 'e-mail', 'e-mail cím'],
  buyer_phone: ['vevő telefonszáma'],
  country: ['ország'],
  postcode: ['irányítószám', 'postai irányítószám'],
  city: ['település'],
  street: ['utca, házszám', 'utca házszám'],
  giver_name: ['ajándékozó neve', 'számlázási név'],
  recipient_name: ['megajándékozott neve'],
  message: ['üzenet a megajándékozottnak', 'üzenet'],
  delivery_email: ['kézbesítési e-mail cím'],
  buyer_note: ['vevő megjegyzése', 'vendég megjegyzése'],
  promo_code: ['promóciós kód'],
  payment_provider: ['fizetési szolgáltató'],
  transaction_id: ['fizetési azonosító', 'tranzakció azonosító', 'transaction id'],
  marketing_opt_in: ['hírlevél', 'marketing feliratkozás', 'marketing'],
};

const IMPORT_STATUS = {
  'fizetve': 'active', 'aktív': 'active', 'aktiv': 'active',
  'felhasználva': 'redeemed', 'felhasznalva': 'redeemed', 'beváltva': 'redeemed', 'bevaltva': 'redeemed',
  'törölve': 'cancelled', 'torolve': 'cancelled', 'sztornó': 'cancelled', 'sztorno': 'cancelled',
  'lejárt': 'expired', 'lejart': 'expired',
  'függőben': 'pending', 'fuggoben': 'pending', 'fizetésre vár': 'pending', 'fizetesre var': 'pending',
};
const EXPORT_STATUS = {
  active: 'fizetve', redeemed: 'felhasználva', cancelled: 'törölve',
  expired: 'lejárt', pending: 'függőben',
};

function normHeader(h) {
  return String(h || '').replace(/^﻿/, '').trim().toLowerCase();
}
function amountFrom(v) {
  const digits = String(v == null ? '' : v).replace(/[^\d]/g, '');
  return digits ? parseInt(digits, 10) : 0;
}
function truthy(v) {
  return ['igen', 'yes', '1', 'true', 'igaz'].includes(String(v || '').trim().toLowerCase());
}

// Egy nyers CSV-sor (fejléc-index alapján) -> belső rekord (upsertVouchers-hez).
function headersFor(field) {
  if (ALIASES[field]) return ALIASES[field];
  const f = FIELDS.find(x => x[1] === field);
  return f ? [f[0]] : [];
}
function serialColumnPresent(headerIdx) {
  return headersFor('serial').some(h => headerIdx[normHeader(h)] != null);
}
function rowToRecord(headerIdx, row, unitSlug) {
  const get = field => {
    for (const h of headersFor(field)) {
      const i = headerIdx[normHeader(h)];
      if (i != null) return (row[i] || '').trim();
    }
    return '';
  };
  return {
    unit: unitSlug,
    serial: get('serial'),
    order_ref: get('order_ref'),
    wc_order_id: get('wc_order_id'),
    label: get('label'),
    amount: amountFrom(get('amount')),
    status: IMPORT_STATUS[String(get('status')).toLowerCase()] || (get('status') ? String(get('status')).toLowerCase() : 'active'),
    created_at: get('created_at'),
    valid_from: get('valid_from'),
    valid_until: get('valid_until'),
    redeemed_at: get('redeemed_at'),
    buyer_name: get('buyer_name'),
    buyer_email: get('buyer_email'),
    buyer_phone: get('buyer_phone'),
    country: get('country'),
    postcode: get('postcode'),
    city: get('city'),
    street: get('street'),
    giver_name: get('giver_name'),
    recipient_name: get('recipient_name'),
    message: get('message'),
    delivery_email: get('delivery_email'),
    buyer_note: get('buyer_note'),
    promo_code: get('promo_code'),
    payment_provider: get('payment_provider'),
    transaction_id: get('transaction_id'),
    marketing_opt_in: truthy(get('marketing_opt_in')),
    is_legacy: true,
  };
}

// Export fejléc (egység + kanonikus oszlopok).
function exportHeader() {
  return ['egység'].concat(FIELDS.map(f => f[0]));
}
// Egy DB-utalvány -> export CSV-sor (unitName az egység olvasható neve).
function recordToRow(v, unitName) {
  const val = field => {
    if (field === 'amount') return String(v.amount == null ? 0 : v.amount);
    if (field === 'status') return EXPORT_STATUS[v.status] || (v.status || '');
    if (field === 'marketing_opt_in') return v.marketing_opt_in ? 'igen' : 'nem';
    const x = v[field];
    if (x == null) return '';
    // dátum/idő: ISO -> "YYYY-MM-DD HH:MM:SS" / "YYYY-MM-DD"
    if (x instanceof Date) {
      const iso = x.toISOString();
      return field.endsWith('_at') || field === 'created_at' || field === 'paid_at'
        ? iso.slice(0, 19).replace('T', ' ') : iso.slice(0, 10);
    }
    return String(x);
  };
  return [unitName].concat(FIELDS.map(f => val(f[1])));
}

module.exports = { FIELDS, ALIASES, IMPORT_STATUS, EXPORT_STATUS, normHeader, headersFor, serialColumnPresent, rowToRecord, exportHeader, recordToRow };
