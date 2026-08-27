// Dátum-segédek + a hiányzó dátumok levezetése.
//
// A korábbi rendszerből importált utalványoknál gyakran hiányzik a vásárlás
// időpontja és az érvényesség kezdete, viszont az érvényesség VÉGE mindig megvan.
// Mivel az érvényesség a vásárlástól számított fix időszak, a hiányzó dátumok
// ebből egyértelműen visszaszámolhatók — ezzel nem tűnnek el a tételek a
// dátumra szűrt kimutatásokból.
const VALIDITY_MONTHS = 12;

function ymd(v) {
  if (!v) return null;
  if (v instanceof Date) return isNaN(v) ? null : v.toISOString().slice(0, 10);
  return String(v).slice(0, 10);
}
function ymdhms(v) {
  if (!v) return null;
  if (v instanceof Date) return isNaN(v) ? null : v.toISOString().slice(0, 19).replace('T', ' ');
  return String(v).replace('T', ' ').slice(0, 19);
}
function shiftMonths(v, months) {
  const s = ymd(v);
  if (!s) return null;
  const d = new Date(s + 'T00:00:00Z');
  if (isNaN(d)) return null;
  const day = d.getUTCDate();
  d.setUTCMonth(d.getUTCMonth() + months);
  // Hónapvégi átfordulás (pl. 31. -> következő hónap eleje) visszaigazítása.
  if (d.getUTCDate() !== day) d.setUTCDate(0);
  return d.toISOString().slice(0, 10);
}

// Az érvényesség vége: ami be van írva, különben vásárlás + 12 hónap.
function effectiveValidUntil(v) {
  return ymd(v.valid_until) || shiftMonths(v.created_at || v.valid_from, VALIDITY_MONTHS);
}
// A vásárlás napja SZŰRÉSHEZ: ami be van írva, különben érvényesség vége − 12 hónap.
// (Az exportban a „vásárlás időpontja” oszlopba nem írunk kitalált értéket.)
function effectivePurchaseDay(v) {
  return ymd(v.created_at) || shiftMonths(effectiveValidUntil(v), -VALIDITY_MONTHS);
}
// Az érvényesség kezdete: definíció szerint a vásárlás napja.
function effectiveValidFrom(v) {
  return ymd(v.valid_from) || effectivePurchaseDay(v);
}

module.exports = { VALIDITY_MONTHS, ymd, ymdhms, shiftMonths, effectiveValidUntil, effectivePurchaseDay, effectiveValidFrom };
