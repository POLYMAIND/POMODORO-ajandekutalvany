// Függőség nélküli XLSX (Excel 2007+) olvasó — a Node beépített zlib-jével.
// Az .xlsx valójában egy ZIP, benne XML-ekkel; itt kicsomagoljuk és soronként
// ugyanolyan tömb-a-tömbben szerkezetet adunk vissza, mint a CSV-parser,
// hogy az import többi része változatlan maradhasson.
const zlib = require('zlib');

// ---- ZIP ----
// Csak azt csomagoljuk ki, amire szükség van (központi könyvtár -> lokális fejléc).
function zipIndex(buf) {
  // End of Central Directory megkeresése a fájl végéről (max. 64 KB megjegyzés).
  const sig = 0x06054b50;
  let eocd = -1;
  for (let i = buf.length - 22; i >= Math.max(0, buf.length - 65557); i--) {
    if (buf.readUInt32LE(i) === sig) { eocd = i; break; }
  }
  if (eocd < 0) throw new Error('Nem ZIP/XLSX fájl.');
  const count = buf.readUInt16LE(eocd + 10);
  let p = buf.readUInt32LE(eocd + 16);
  const map = new Map();
  for (let i = 0; i < count && p + 46 <= buf.length; i++) {
    if (buf.readUInt32LE(p) !== 0x02014b50) break;
    const method = buf.readUInt16LE(p + 10);
    const compSize = buf.readUInt32LE(p + 20);
    const nameLen = buf.readUInt16LE(p + 28);
    const extraLen = buf.readUInt16LE(p + 30);
    const commentLen = buf.readUInt16LE(p + 32);
    const localOff = buf.readUInt32LE(p + 42);
    const name = buf.toString('utf8', p + 46, p + 46 + nameLen);
    map.set(name, { method, compSize, localOff });
    p += 46 + nameLen + extraLen + commentLen;
  }
  return map;
}
function zipRead(buf, entry) {
  if (!entry) return null;
  const p = entry.localOff;
  if (buf.readUInt32LE(p) !== 0x04034b50) throw new Error('Sérült ZIP bejegyzés.');
  const nameLen = buf.readUInt16LE(p + 26);
  const extraLen = buf.readUInt16LE(p + 28);
  const start = p + 30 + nameLen + extraLen;
  const data = buf.subarray(start, start + entry.compSize);
  if (entry.method === 0) return data;
  if (entry.method === 8) return zlib.inflateRawSync(data);
  throw new Error('Nem támogatott tömörítés a fájlban.');
}

// ---- XML ----
function xmlDecode(s) {
  return String(s == null ? '' : s)
    .replace(/&#x([0-9a-fA-F]+);/g, (m, h) => String.fromCodePoint(parseInt(h, 16)))
    .replace(/&#(\d+);/g, (m, d) => String.fromCodePoint(parseInt(d, 10)))
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"').replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&');
}
function attrOf(tag, name) {
  const m = new RegExp('\\s' + name + '="([^"]*)"').exec(tag);
  return m ? xmlDecode(m[1]) : '';
}
// Egy <si>/<is> elem szövege: az összes <t> tartalma összefűzve (a fonetikus <rPh> nélkül).
function textOf(xml) {
  let out = '';
  const clean = String(xml || '').replace(/<rPh[\s\S]*?<\/rPh>/g, '');
  const re = /<t\b[^>]*?(\/>|>([\s\S]*?)<\/t>)/g;
  let m;
  while ((m = re.exec(clean))) out += m[1] === '/>' ? '' : xmlDecode(m[2]);
  return out;
}

// ---- dátum-formátumok ----
const BUILTIN_DATE_FMT = new Set([14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47]);
function dateStyles(stylesXml) {
  const isDate = [];
  if (!stylesXml) return isDate;
  const custom = {};
  const nf = /<numFmt\b([^>]*)\/>/g;
  let m;
  while ((m = nf.exec(stylesXml))) {
    const id = parseInt(attrOf(m[1], 'numFmtId'), 10);
    const code = attrOf(m[1], 'formatCode');
    if (!isNaN(id)) custom[id] = code;
  }
  const block = /<cellXfs\b[^>]*>([\s\S]*?)<\/cellXfs>/.exec(stylesXml);
  if (!block) return isDate;
  const xf = /<xf\b([^>]*?)(?:\/>|>[\s\S]*?<\/xf>)/g;
  while ((m = xf.exec(block[1]))) {
    const id = parseInt(attrOf(m[1], 'numFmtId'), 10) || 0;
    let d = BUILTIN_DATE_FMT.has(id);
    if (!d && custom[id]) {
      // A saját formátumban az 'm' perc is lehet, ezért 'y'/'d'/'h' alapján döntünk.
      const code = custom[id].replace(/\[[^\]]*\]/g, '').replace(/"[^"]*"/g, '');
      d = /[yd]/i.test(code) || /h/i.test(code);
    }
    isDate.push(d);
  }
  return isDate;
}
const DAY_MS = 86400000;
function serialToDate(n) {
  // Excel dátum-sorszám: 1899-12-30 óta eltelt napok (az 1900-as szökőnap-hibával együtt).
  const ms = Math.round((n - 25569) * DAY_MS / 1000) * 1000;
  const d = new Date(ms);
  if (isNaN(d)) return String(n);
  const p = x => String(x).padStart(2, '0');
  const ymd = d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate());
  const hasTime = Math.abs(n - Math.floor(n)) > 1e-9;
  if (!hasTime) return ymd;
  return ymd + ' ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds());
}

// ---- oszlop-hivatkozás ("BC12" -> 54) ----
function colIndex(ref) {
  let n = 0;
  for (let i = 0; i < ref.length; i++) {
    const c = ref.charCodeAt(i);
    if (c < 65 || c > 90) break;
    n = n * 26 + (c - 64);
  }
  return n - 1;
}

// A munkafüzet ELSŐ munkalapjának útvonala (a workbook sorrendje szerint, nem a ZIP-é).
function firstSheetPath(zip, buf) {
  try {
    const wb = zipRead(buf, zip.get('xl/workbook.xml'));
    const rels = zipRead(buf, zip.get('xl/_rels/workbook.xml.rels'));
    if (wb && rels) {
      const s = /<sheet\b([^>]*)\/?>/.exec(wb.toString('utf8'));
      const rid = s ? attrOf(s[1], 'r:id') || attrOf(s[1], 'id') : '';
      if (rid) {
        const re = new RegExp('<Relationship\\b[^>]*Id="' + rid + '"[^>]*>');
        const r = re.exec(rels.toString('utf8'));
        if (r) {
          let t = attrOf(r[0], 'Target').replace(/^\//, '');
          if (!t.startsWith('xl/')) t = 'xl/' + t;
          if (zip.has(t)) return t;
        }
      }
    }
  } catch (e) { /* jöhet a tartalék ág */ }
  // Tartalék: a legkisebb sorszámú munkalap.
  const sheets = [...zip.keys()].filter(k => /^xl\/worksheets\/sheet\d+\.xml$/.test(k))
    .sort((a, b) => (parseInt(a.replace(/\D/g, ''), 10) - parseInt(b.replace(/\D/g, ''), 10)));
  return sheets[0] || null;
}

// Régi, bináris Excel 97-2003 (.xls) — OLE2 fejléc. Ezt nem tudjuk olvasni.
function isLegacyXls(buf) {
  return buf.length > 8 && buf.readUInt32LE(0) === 0xe011cfd0 && buf.readUInt32LE(4) === 0xe11ab1a1;
}
function isZip(buf) {
  return buf.length > 4 && buf[0] === 0x50 && buf[1] === 0x4b;
}

// XLSX puffer -> sorok (tömb a tömbben, minden cella szöveg) — a parseCSV kimenetével azonos alak.
function parseXLSX(buf) {
  const zip = zipIndex(buf);
  const sheetPath = firstSheetPath(zip, buf);
  if (!sheetPath) throw new Error('Nem találok munkalapot a fájlban.');

  const ss = [];
  const ssBuf = zipRead(buf, zip.get('xl/sharedStrings.xml'));
  if (ssBuf) {
    const re = /<si\b[^>]*?(\/>|>([\s\S]*?)<\/si>)/g;
    let m;
    while ((m = re.exec(ssBuf.toString('utf8')))) ss.push(m[1] === '/>' ? '' : textOf(m[2]));
  }
  const stylesBuf = zipRead(buf, zip.get('xl/styles.xml'));
  const isDate = dateStyles(stylesBuf ? stylesBuf.toString('utf8') : '');

  const xml = zipRead(buf, zip.get(sheetPath)).toString('utf8');
  const rows = [];
  const rowRe = /<row\b([^>]*?)(?:\/>|>([\s\S]*?)<\/row>)/g;
  let rm;
  while ((rm = rowRe.exec(xml))) {
    const cells = [];
    const body = rm[2] || '';
    const cellRe = /<c\b([^>]*?)(?:\/>|>([\s\S]*?)<\/c>)/g;
    let cm;
    while ((cm = cellRe.exec(body))) {
      const at = cm[1], inner = cm[2] || '';
      const ref = attrOf(at, 'r');
      const idx = ref ? colIndex(ref) : cells.length;
      const type = attrOf(at, 't');
      let val = '';
      if (type === 'inlineStr') {
        val = textOf(inner);
      } else {
        const v = /<v\b[^>]*>([\s\S]*?)<\/v>/.exec(inner);
        const raw = v ? xmlDecode(v[1]) : '';
        if (type === 's') {
          val = ss[parseInt(raw, 10)] || '';
        } else if (type === 'e') {
          val = '';
        } else if (type === 'b') {
          val = raw === '1' ? '1' : '0';
        } else if (type === 'str') {
          val = raw;
        } else if (raw !== '') {
          const sIdx = parseInt(attrOf(at, 's'), 10);
          const num = Number(raw);
          val = (!isNaN(num) && isDate[sIdx] && num > 0) ? serialToDate(num)
            : (isNaN(num) ? raw : String(num));
        }
      }
      while (cells.length < idx) cells.push('');
      cells[idx] = String(val).trim();
    }
    rows.push(cells);
  }
  // Az üres sorokat eldobjuk (ahogy a CSV-parser is).
  return rows.filter(r => r.some(v => String(v).trim() !== ''));
}

module.exports = { parseXLSX, isLegacyXls, isZip };
