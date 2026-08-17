// Függőség nélküli CSV parser/serializer (pontosvessző-elválasztó, idézőjeles mezők,
// "" escape, idézőjelen belüli sortörés, CRLF/LF tűrés).
function parseCSV(text, delimiter = ';') {
  const rows = [];
  let row = [], field = '', inQ = false;
  const t = String(text || '').replace(/^﻿/, ''); // BOM le
  for (let i = 0; i < t.length; i++) {
    const c = t[i];
    if (inQ) {
      if (c === '"') {
        if (t[i + 1] === '"') { field += '"'; i++; }
        else inQ = false;
      } else field += c;
    } else {
      if (c === '"') inQ = true;
      else if (c === delimiter) { row.push(field); field = ''; }
      else if (c === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
      else if (c === '\r') { /* skip, a \n zárja a sort */ }
      else field += c;
    }
  }
  // utolsó mező/sor
  if (field.length || row.length) { row.push(field); rows.push(row); }
  // teljesen üres sorok ki
  return rows.filter(r => r.some(v => String(v).trim() !== ''));
}

function toCSV(rows, delimiter = ';', bom = true) {
  const esc = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
  const body = rows.map(r => r.map(esc).join(delimiter)).join('\r\n') + '\r\n';
  return (bom ? '﻿' : '') + body;
}

module.exports = { parseCSV, toCSV };
