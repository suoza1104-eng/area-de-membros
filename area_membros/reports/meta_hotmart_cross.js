const fs = require('fs');
const path = require('path');

const extractDir = fs.readFileSync(path.join(__dirname, 'meta_leads_extract_dir.txt'), 'utf8').trim();
const salesPath = path.join(__dirname, 'meta_hotmart_sales.json');
const sheetPath = path.join(__dirname, extractDir.replace(/^reports[\\/]/, ''), 'xl', 'worksheets', 'sheet1.xml');
const sharedPath = path.join(__dirname, extractDir.replace(/^reports[\\/]/, ''), 'xl', 'sharedStrings.xml');

function xmlDecode(s) {
  return String(s ?? '')
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"').replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&');
}

function normalizeHeader(s) {
  return String(s ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '');
}

function normalizeEmail(s) {
  return String(s ?? '').trim().toLowerCase();
}

function onlyDigits(s) {
  return String(s ?? '').replace(/\D+/g, '');
}

function normalizePhone(s) {
  let d = onlyDigits(s);
  if (d.startsWith('55') && d.length > 11) d = d.slice(2);
  if (d.length > 11) d = d.slice(-11);
  return d;
}

function phoneKeys(s) {
  const d = normalizePhone(s);
  const keys = new Set();
  if (d.length >= 8) keys.add(d);
  if (d.length >= 10) keys.add(d.slice(-10));
  if (d.length >= 8) keys.add(d.slice(-8));
  return [...keys];
}

function colIndex(ref) {
  const letters = String(ref).match(/^[A-Z]+/i)?.[0] || '';
  let n = 0;
  for (const ch of letters.toUpperCase()) n = n * 26 + ch.charCodeAt(0) - 64;
  return n - 1;
}

function loadSharedStrings(file) {
  const xml = fs.readFileSync(file, 'utf8');
  const out = [];
  for (const m of xml.matchAll(/<si\b[^>]*>([\s\S]*?)<\/si>/g)) {
    const parts = [...m[1].matchAll(/<t\b[^>]*>([\s\S]*?)<\/t>/g)].map(x => xmlDecode(x[1]));
    out.push(parts.join(''));
  }
  return out;
}

function loadSheetRows(sheetFile, shared) {
  const xml = fs.readFileSync(sheetFile, 'utf8');
  const rows = [];
  for (const rm of xml.matchAll(/<row\b[^>]*\br="(\d+)"[^>]*>([\s\S]*?)<\/row>/g)) {
    const row = [];
    for (const cm of rm[2].matchAll(/<c\b([^>]*)>([\s\S]*?)<\/c>/g)) {
      const attrs = cm[1];
      const body = cm[2];
      const ref = attrs.match(/\br="([^"]+)"/)?.[1] || '';
      const type = attrs.match(/\bt="([^"]+)"/)?.[1] || '';
      let value = '';
      if (type === 's') {
        const idx = Number(body.match(/<v>([\s\S]*?)<\/v>/)?.[1] ?? -1);
        value = shared[idx] ?? '';
      } else if (type === 'inlineStr') {
        value = [...body.matchAll(/<t\b[^>]*>([\s\S]*?)<\/t>/g)].map(x => xmlDecode(x[1])).join('');
      } else {
        value = xmlDecode(body.match(/<v>([\s\S]*?)<\/v>/)?.[1] ?? '');
      }
      row[colIndex(ref)] = value;
    }
    rows.push(row.map(v => v ?? ''));
  }
  return rows;
}

function pick(row, indexes) {
  for (const i of indexes) {
    const v = row[i];
    if (v !== undefined && String(v).trim() !== '') return String(v).trim();
  }
  return '';
}

function findIndexes(headers, patterns) {
  return headers.map(normalizeHeader).map((h, i) => patterns.some(p => h.includes(p)) ? i : -1).filter(i => i >= 0);
}

function saleIsPurchase(s) {
  const status = normalizeHeader(s.status || '');
  if (status.includes('reembols') || status.includes('refund') || status.includes('chargeback') || status.includes('cancel')) return false;
  return status.includes('aprov') || status.includes('completo') || status.includes('approved') || status.includes('complete');
}

function money(n) {
  const v = Number(n || 0);
  return Number.isFinite(v) ? v : 0;
}

const shared = loadSharedStrings(sharedPath);
const rows = loadSheetRows(sheetPath, shared);
const headers = rows[0] || [];
const dataRows = rows.slice(1).filter(r => r.some(v => String(v).trim() !== ''));

const emailIdx = findIndexes(headers, ['email', 'correioeletronico']);
const phoneIdx = findIndexes(headers, ['telefone', 'phone', 'celular', 'whatsapp']);
let nameIdx = findIndexes(headers, ['firstname', 'first_name', 'nomecompleto', 'nomedocompleto', 'fullname']);
if (!nameIdx.length) nameIdx = findIndexes(headers, ['nome']);
const createdIdx = findIndexes(headers, ['criado', 'created', 'datadecriacao', 'submission', 'submitted']);

const sales = JSON.parse(fs.readFileSync(salesPath, 'utf8').replace(/^\uFEFF/, ''));
const salesByEmail = new Map();
const salesByPhone = new Map();
for (const s of sales) {
  const emails = [normalizeEmail(s.buyer_email)].filter(Boolean);
  const phones = phoneKeys(s.buyer_phone_norm || s.buyer_phone_raw);
  for (const e of emails) {
    if (!salesByEmail.has(e)) salesByEmail.set(e, []);
    salesByEmail.get(e).push(s);
  }
  for (const p of phones) {
    if (!salesByPhone.has(p)) salesByPhone.set(p, []);
    salesByPhone.get(p).push(s);
  }
}

const details = [];
const matchedLeadKeys = new Set();
const unmatched = [];

dataRows.forEach((row, idx) => {
  const email = normalizeEmail(pick(row, emailIdx));
  const phoneRaw = pick(row, phoneIdx);
  const phone = normalizePhone(phoneRaw);
  const name = pick(row, nameIdx);
  const leadDate = pick(row, createdIdx);
  const key = `${email}|${phone}|${idx + 2}`;
  const candidate = [];
  const seenTx = new Set();
  if (email && salesByEmail.has(email)) {
    for (const s of salesByEmail.get(email)) {
      if (!seenTx.has(s.transaction_code)) candidate.push({ sale: s, criterion: 'email' });
      seenTx.add(s.transaction_code);
    }
  }
  for (const pk of phoneKeys(phone)) {
    if (!salesByPhone.has(pk)) continue;
    for (const s of salesByPhone.get(pk)) {
      if (!seenTx.has(s.transaction_code)) candidate.push({ sale: s, criterion: email && normalizeEmail(s.buyer_email) === email ? 'email+telefone' : 'telefone' });
      seenTx.add(s.transaction_code);
    }
  }
  const purchaseCandidates = candidate.filter(x => saleIsPurchase(x.sale));
  if (purchaseCandidates.length) {
    matchedLeadKeys.add(key);
    for (const { sale, criterion } of purchaseCandidates) {
      details.push({
        lead_row: idx + 2,
        lead_name: name,
        lead_email: email,
        lead_phone: phoneRaw,
        lead_phone_norm: phone,
        lead_created_at: leadDate,
        match_criterion: criterion,
        transaction_code: sale.transaction_code || '',
        sale_status: sale.status || '',
        sale_date: sale.payment_confirmed_at || sale.transaction_date || '',
        buyer_name: sale.buyer_name || '',
        buyer_email: sale.buyer_email || '',
        buyer_phone: sale.buyer_phone_raw || sale.buyer_phone_norm || '',
        product_name: sale.product_name || '',
        price_name: sale.price_name || '',
        gross_revenue: money(sale.gross_revenue),
        producer_net: money(sale.producer_net),
        refunded_value: money(sale.refunded_value) + money(sale.chargeback_value),
      });
    }
  } else {
    unmatched.push({
      lead_row: idx + 2,
      lead_name: name,
      lead_email: email,
      lead_phone: phoneRaw,
      lead_phone_norm: phone,
      lead_created_at: leadDate,
    });
  }
});

const uniqueTx = new Set(details.map(d => d.transaction_code).filter(Boolean));
const uniqueTxRows = [];
const uniqueTxSeen = new Set();
for (const d of details) {
  if (!d.transaction_code || uniqueTxSeen.has(d.transaction_code)) continue;
  uniqueTxSeen.add(d.transaction_code);
  uniqueTxRows.push(d);
}
const uniqueLeadRows = new Set(details.map(d => d.lead_row));
const summary = {
  source_rows: dataRows.length,
  source_with_email: dataRows.filter(r => normalizeEmail(pick(r, emailIdx))).length,
  source_with_phone: dataRows.filter(r => normalizePhone(pick(r, phoneIdx))).length,
  matched_leads: uniqueLeadRows.size,
  unmatched_leads: unmatched.length,
  matched_lines: details.length,
  matched_transactions: uniqueTx.size,
  gross_revenue: uniqueTxRows.reduce((s, d) => s + d.gross_revenue, 0),
  producer_net: uniqueTxRows.reduce((s, d) => s + d.producer_net, 0),
  email_columns: emailIdx.map(i => headers[i]),
  phone_columns: phoneIdx.map(i => headers[i]),
  name_columns: nameIdx.map(i => headers[i]),
};

fs.writeFileSync(path.join(__dirname, 'meta_hotmart_result.json'), JSON.stringify({ summary, headers, details, unmatched }, null, 2), 'utf8');
console.log(JSON.stringify(summary, null, 2));
