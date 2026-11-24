// Függőség nélküli CSV parser/serializer (pontosvessző-elválasztó, idézőjeles mezők,
// "" escape, idézőjelen belüli sortörés, CRLF/LF tűrés).
// Lenient CSV-parser (a Python csv.reader nem-strict viselkedéséhez igazítva):
// - idézőjel csak MEZŐ ELEJÉN nyit idézetet;
// - idézeten belül a `""` egy literál idézőjel;
// - idézeten belül egy magányos `"` csak akkor ZÁR, ha utána elválasztó/sorvég/EOF jön,
//   különben literál idézőjelként kezeljük (így egy nem-duplázott `"` nem "szalad el").
function parseCSV(text, delimiter = ';') {
  const rows = [];
  let row = [], field = '', inQ = false, atFieldStart = true;
  const t = String(text || '').replace(/^﻿/, ''); // BOM le
  const n = t.length;
  let i = 0;
  while (i < n) {
    const c = t[i];
    if (inQ) {
      if (c === '"') {
        if (t[i + 1] === '"') { field += '"'; i += 2; continue; }
        const next = t[i + 1];
        if (next === undefined || next === delimiter || next === '\n' || next === '\r') {
          inQ = false; i++; continue;
        }
        field += '"'; i++; continue; // magányos idézőjel a mezőben → literál
      }
      field += c; i++; continue;
    }
    if (c === '"' && atFieldStart) { inQ = true; atFieldStart = false; i++; continue; }
    if (c === delimiter) { row.push(field); field = ''; atFieldStart = true; i++; continue; }
    if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; atFieldStart = true; i++; continue; }
    if (c === '\r') { i++; continue; }
    field += c; atFieldStart = false; i++; continue;
  }
  if (field.length || row.length) { row.push(field); rows.push(row); }
  return rows.filter(r => r.some(v => String(v).trim() !== ''));
}

function toCSV(rows, delimiter = ';', bom = true) {
  const esc = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const body = rows.map(r => r.map(esc).join(delimiter)).join('\r\n') + '\r\n';
  return (bom ? '﻿' : '') + body;
}

module.exports = { parseCSV, toCSV };
