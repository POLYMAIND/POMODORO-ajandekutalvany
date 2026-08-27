// A kanonikus utalvány-CSV formátum: fejléc <-> belső mező leképezés, státusz-fordítás.
// Ez az import ÉS az export közös igazsága.

// Sorrend = export oszlopsorrend (az "egység" az export elején külön kezelve).
const FIELDS = [
  ['utalvány kódja', 'serial'],
  ['megrendelési azonosító', 'order_ref'],
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
  // A belső sorszám nem export-oszlop (a vezérlőpulton látszik), de ha egy
  // korábbi export fájlban szerepel, visszatöltéskor elfogadjuk.
  seq_label: ['belső sorszám'],
  order_ref: ['megrendelési azonosító', 'woocommerce rendelésszám'],
  label: ['utalvány neve'],
  amount: ['érték', 'ár', 'összeg', 'amount'],
  status: ['státusz', 'status'],
  created_at: ['vásárlás időpontja', 'vásárlás dátuma'],
  valid_from: ['érvényesség kezdete'],
  valid_until: ['érvényesség vége', 'lejárat'],
  redeemed_at: ['felhasználás dátuma'],
  // A régi rendszer „számlázási név” oszlopa a VEVŐ neve. Ajándékutalványnál
  // a vevő egyben az ajándékozó, ezért ha külön ajándékozó-oszlop nincs a
  // fájlban, abba is ez kerül (lásd lentebb).
  buyer_name: ['vevő neve', 'számlázási név', 'számlázási név / vevő neve'],
  buyer_email: ['vevő e-mail címe', 'email', 'e-mail', 'e-mail cím'],
  buyer_phone: ['vevő telefonszáma'],
  country: ['ország'],
  postcode: ['irányítószám', 'postai irányítószám'],
  city: ['település'],
  street: ['utca, házszám', 'utca házszám'],
  giver_name: ['ajándékozó neve'],
  recipient_name: ['megajándékozott neve'],
  message: ['üzenet a megajándékozottnak', 'üzenet'],
  delivery_email: ['kézbesítési e-mail cím'],
  buyer_note: ['vevő megjegyzése', 'vendég megjegyzése'],
  promo_code: ['promóciós kód'],
  payment_provider: ['fizetési szolgáltató'],
  transaction_id: ['fizetési azonosító', 'tranzakció azonosító', 'transaction id'],
  marketing_opt_in: ['hírlevél', 'marketing feliratkozás', 'marketing'],
};

// A forrásrendszerek sokféleképpen írják ugyanazt az állapotot (felhasználva /
// felhasznált / beváltva…). Minden ismert alakot ide gyűjtünk, ékezet nélküli
// változattal együtt — ami kimarad, az nyers szövegként kerülne az adatbázisba,
// és se a szűrő, se a kimutatás nem találná meg.
const IMPORT_STATUS = {
  'fizetve': 'active', 'aktív': 'active', 'aktiv': 'active', 'active': 'active',
  'paid': 'active', 'kifizetve': 'active', 'érvényes': 'active', 'ervenyes': 'active',
  'nem használt fel': 'active', 'nem hasznalt fel': 'active', 'felhasználatlan': 'active', 'felhasznalatlan': 'active',

  'felhasználva': 'redeemed', 'felhasznalva': 'redeemed',
  'felhasznált': 'redeemed', 'felhasznalt': 'redeemed',
  'beváltva': 'redeemed', 'bevaltva': 'redeemed', 'beváltott': 'redeemed', 'bevaltott': 'redeemed',
  'levásárolva': 'redeemed', 'levasarolva': 'redeemed', 'redeemed': 'redeemed', 'used': 'redeemed',

  'törölve': 'cancelled', 'torolve': 'cancelled', 'sztornó': 'cancelled', 'sztorno': 'cancelled',
  'sztornózva': 'cancelled', 'sztornozva': 'cancelled', 'visszavonva': 'cancelled',
  'cancelled': 'cancelled', 'canceled': 'cancelled',

  'lejárt': 'expired', 'lejart': 'expired', 'expired': 'expired',

  'függőben': 'pending', 'fuggoben': 'pending', 'fizetésre vár': 'pending', 'fizetesre var': 'pending',
  'feldolgozás alatt': 'pending', 'feldolgozas alatt': 'pending', 'pending': 'pending',
};

const CANONICAL_STATUS = ['active', 'redeemed', 'cancelled', 'expired', 'pending'];

// Bármilyen forrásból jövő állapot -> a rendszer saját állapotai.
// Ismeretlen érték esetén az eredetit adjuk vissza (kisbetűsen), hogy látszódjon
// a felületen, hogy valami nem stimmel — nem nyeljük el csendben.
function normalizeStatus(raw) {
  const s = String(raw == null ? '' : raw).trim().toLowerCase().replace(/\s+/g, ' ');
  if (!s) return '';
  if (CANONICAL_STATUS.includes(s)) return s;
  return IMPORT_STATUS[s] || s;
}
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
  const rec = {
    unit: unitSlug,
    serial: get('serial'),
    order_ref: get('order_ref'),
    label: get('label'),
    amount: amountFrom(get('amount')),
    status: normalizeStatus(get('status')) || 'active',
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
  // Ha a forrás csak az egyik névoszlopot hozza, a másikat is töltsük: az
  // ajándékutalványt a vevő adja ajándékba, tehát a kettő ugyanaz a személy.
  if (!rec.giver_name && rec.buyer_name) rec.giver_name = rec.buyer_name;
  if (!rec.buyer_name && rec.giver_name) rec.buyer_name = rec.giver_name;
  return rec;
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

module.exports = { FIELDS, ALIASES, IMPORT_STATUS, EXPORT_STATUS, CANONICAL_STATUS, normalizeStatus, normHeader, headersFor, serialColumnPresent, rowToRecord, exportHeader, recordToRow };
