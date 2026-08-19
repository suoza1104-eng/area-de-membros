import fs from "node:fs/promises";
import path from "node:path";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const inputCsv = "C:\\Users\\Emerson\\Downloads\\bd34d4757862cac605c828db814508043aa0.csv";
const outputDir = "C:\\Users\\Emerson\\Desktop\\area de membros - projeto\\area_membros\\area_membros\\outputs\\01a0023a-e970-7701-a5ba-1c471082a9a0";
const outputXlsx = path.join(outputDir, "relatorio_dom_pagamentos_agosto_2026.xlsx");

function parseTsv(text) {
  const rows = [];
  let row = [];
  let cell = "";
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    const next = text[i + 1];
    if (quoted) {
      if (ch === '"' && next === '"') {
        cell += '"';
        i++;
      } else if (ch === '"') {
        quoted = false;
      } else {
        cell += ch;
      }
      continue;
    }
    if (ch === '"') quoted = true;
    else if (ch === "\t") {
      row.push(cell);
      cell = "";
    } else if (ch === "\n") {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = "";
    } else if (ch !== "\r") {
      cell += ch;
    }
  }
  if (cell !== "" || row.length) {
    row.push(cell);
    rows.push(row);
  }
  return rows.filter((r) => r.some((v) => String(v).trim() !== ""));
}

function num(value) {
  const raw = String(value ?? "").trim();
  if (!raw) return 0;
  const normalized = raw.includes(",") ? raw.replace(/\./g, "").replace(",", ".") : raw;
  return Number(normalized) || 0;
}

function asDate(value) {
  const raw = String(value ?? "").replace(/^"|"$/g, "").trim();
  if (!raw) return null;
  const d = new Date(raw.replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? raw : d;
}

function brl(v) {
  return Number(v || 0);
}

function r2(v) {
  return Math.round((Number(v || 0) + Number.EPSILON) * 100) / 100;
}

function fmtMoney(v) {
  return `R$ ${Number(v || 0).toLocaleString("pt-BR", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function safeSheetName(name) {
  return name.slice(0, 31).replace(/[\\/*?:[\]]/g, " ");
}

function setWidths(sheet, widths) {
  widths.forEach((px, i) => {
    sheet.getRangeByIndexes(0, i, 1, 1).format.columnWidthPx = px;
  });
}

function styleTitle(sheet, range, title, subtitle = "") {
  sheet.getRange(range).merge();
  sheet.getRange(range).values = [[subtitle ? `${title}\n${subtitle}` : title]];
  sheet.getRange(range).format = {
    fill: "#12343B",
    font: { bold: true, color: "#FFFFFF" },
    wrapText: true,
  };
}

function styleHeader(range) {
  range.format = {
    fill: "#245C63",
    font: { bold: true, color: "#FFFFFF" },
    wrapText: true,
  };
}

function styleBlock(range, fill = "#F3F7F7") {
  range.format = {
    fill,
  };
}

const text = await fs.readFile(inputCsv, "utf8");
const parsed = parseTsv(text);
const headers = parsed[0];
const rawRows = parsed.slice(1).map((cols) => Object.fromEntries(headers.map((h, i) => [h, cols[i] ?? ""])));

const approved = rawRows.filter((r) => r.status_type === "approved" && String(r.paid_date || "").startsWith("2026-08"));
const totalRows = approved.map((r) => {
  const itemPrice = num(r.item_price) * (num(r.item_quant) || 1);
  const totalPaid = num(r.total);
  const liquid = num(r.total_liquid);
  const mdr = num(r.mdr_value);
  const installment = num(r.fee_installment_value);
  const transactionFee = num(r.fee_transaction);
  return {
    id: r.id_transaction,
    paidDate: asDate(r.paid_date),
    customer: r.client_name,
    email: r.client_email,
    phone: r.client_phone,
    product: r.product_first || r.item_name,
    payment: r.type_payment,
    installments: num(r.installments),
    status: r.status,
    itemPrice,
    totalPaid,
    installmentFee: installment,
    totalWithoutInstallment: totalPaid - installment,
    mdr,
    transactionFee,
    sellerNet: liquid,
    productVsNet: itemPrice - liquid,
    gatewayFeesNoInstallment: mdr + transactionFee,
    clientInstallmentExtra: totalPaid - itemPrice,
  };
});

function groupBy(rows, keyFn) {
  const map = new Map();
  for (const r of rows) {
    const key = keyFn(r) || "Sem informação";
    if (!map.has(key)) map.set(key, []);
    map.get(key).push(r);
  }
  return [...map.entries()].map(([key, rows]) => ({
    key,
    count: rows.length,
    itemPrice: rows.reduce((s, r) => s + r.itemPrice, 0),
    totalPaid: rows.reduce((s, r) => s + r.totalPaid, 0),
    installmentFee: rows.reduce((s, r) => s + r.installmentFee, 0),
    mdr: rows.reduce((s, r) => s + r.mdr, 0),
    transactionFee: rows.reduce((s, r) => s + r.transactionFee, 0),
    sellerNet: rows.reduce((s, r) => s + r.sellerNet, 0),
    productVsNet: rows.reduce((s, r) => s + r.productVsNet, 0),
  }));
}

const byProduct = groupBy(totalRows, (r) => r.product).sort((a, b) => b.itemPrice - a.itemPrice);
const byPayment = groupBy(totalRows, (r) => r.payment).sort((a, b) => b.productVsNet - a.productVsNet);
const byDay = groupBy(totalRows, (r) => {
  if (!(r.paidDate instanceof Date)) return "";
  return r.paidDate.toISOString().slice(0, 10);
}).sort((a, b) => a.key.localeCompare(b.key));

const totals = byProduct.reduce((acc, r) => {
  for (const k of ["count", "itemPrice", "totalPaid", "installmentFee", "mdr", "transactionFee", "sellerNet", "productVsNet"]) acc[k] += r[k];
  return acc;
}, { count: 0, itemPrice: 0, totalPaid: 0, installmentFee: 0, mdr: 0, transactionFee: 0, sellerNet: 0, productVsNet: 0 });

const workbook = Workbook.create();
const dashboard = workbook.worksheets.add("Resumo");
const detail = workbook.worksheets.add("Vendas detalhadas");
const productSheet = workbook.worksheets.add("Por produto");
const paymentSheet = workbook.worksheets.add("Por pagamento");
const daySheet = workbook.worksheets.add("Por dia");
const sourceSheet = workbook.worksheets.add("Fonte e criterios");

for (const s of [dashboard, detail, productSheet, paymentSheet, daySheet, sourceSheet]) s.showGridLines = false;

styleTitle(dashboard, "A1:H2", "Relatório DomPagamentos - Agosto/2026", "Base: vendas aprovadas do CSV baixado da Dom. Preço normal = item_price, sem acréscimo de parcelamento.");
setWidths(dashboard, [290, 130, 145, 135, 105, 105, 130, 150]);

dashboard.getRange("A4:H5").values = [
  ["Vendas aprovadas", "Valor dos produtos", "Total pago pelo cliente", "Acréscimo parcelamento", "MDR", "Taxa fixa", "Líquido vendedor", "Diferença produto x líquido"],
  [totals.count, fmtMoney(totals.itemPrice), fmtMoney(totals.totalPaid), fmtMoney(totals.totalPaid - totals.itemPrice), fmtMoney(totals.mdr), fmtMoney(totals.transactionFee), fmtMoney(totals.sellerNet), fmtMoney(totals.productVsNet)],
];
styleHeader(dashboard.getRange("A4:H4"));
styleBlock(dashboard.getRange("A5:H5"), "#FFFFFF");
dashboard.getRange("B5:H5").format.numberFormat = 'R$ #,##0.00';
dashboard.getRange("A5").format.numberFormat = "0";
dashboard.getRange("H5").format = { fill: "#FFF4D6", font: { bold: true, color: "#7C4A03" } };
dashboard.getRange("A7:D11").values = [
  ["Leitura correta", "", "", ""],
  ["Valor dos produtos", fmtMoney(totals.itemPrice), "Preço normal dos itens, sem parcelamento", ""],
  ["Líquido vendedor", fmtMoney(totals.sellerNet), "Valor efetivamente recebido após descontos", ""],
  ["Diferença real", fmtMoney(totals.productVsNet), "Produto - líquido recebido", ""],
  ["Taxas sem parcelamento", fmtMoney(totals.mdr + totals.transactionFee), "MDR + taxa fixa da transação", ""],
];
dashboard.getRange("A7:D7").merge();
styleHeader(dashboard.getRange("A7:D7"));
styleBlock(dashboard.getRange("A8:D11"), "#F8FBFB");

dashboard.getRange("F8:H10").values = [
  ["Composição da diferença", "Valor", "% do produto"],
  ["MDR + taxa fixa", fmtMoney(totals.mdr + totals.transactionFee), `${(((totals.mdr + totals.transactionFee) / totals.itemPrice) * 100).toFixed(2)}%`],
  ["Impacto líquido parcelamento", fmtMoney(totals.productVsNet - (totals.mdr + totals.transactionFee)), `${(((totals.productVsNet - (totals.mdr + totals.transactionFee)) / totals.itemPrice) * 100).toFixed(2)}%`],
];
styleHeader(dashboard.getRange("F8:H8"));
styleBlock(dashboard.getRange("F9:H10"), "#FFFFFF");
dashboard.getRange("G9:G10").format.numberFormat = 'R$ #,##0.00';
dashboard.getRange("H9:H10").format.numberFormat = "0.00%";

dashboard.getRange("A14:H14").values = [["Produto", "Vendas", "Preço normal", "Total pago", "Parcelamento", "MDR", "Taxa fixa", "Líquido vendedor"]];
dashboard.getRange(`A15:H${14 + byProduct.length}`).values = byProduct.map((r) => [r.key, r.count, fmtMoney(r.itemPrice), fmtMoney(r.totalPaid), fmtMoney(r.installmentFee), fmtMoney(r.mdr), fmtMoney(r.transactionFee), fmtMoney(r.sellerNet)]);
styleHeader(dashboard.getRange("A14:H14"));
dashboard.getRange(`C15:H${14 + byProduct.length}`).format.numberFormat = 'R$ #,##0.00';


const detailHeaders = [
  "ID transação", "Data pagamento", "Cliente", "E-mail", "Telefone", "Produto", "Pagamento", "Parcelas", "Status",
  "Preço normal produto", "Total pago cliente", "Taxa parcelamento", "Total sem parcelamento", "MDR", "Taxa fixa",
  "Líquido vendedor", "Diferença produto x líquido", "Taxas sem parcelamento", "Acréscimo parcelamento cliente",
];
detail.getRange("A1:S1").values = [detailHeaders];
detail.getRange(`A2:S${totalRows.length + 1}`).values = totalRows.map((r) => [
  r.id, r.paidDate, r.customer, r.email, r.phone, r.product, r.payment, r.installments, r.status,
  r2(r.itemPrice), r2(r.totalPaid), r2(r.installmentFee), r2(r.totalWithoutInstallment), r2(r.mdr), r2(r.transactionFee),
  r2(r.sellerNet), r2(r.productVsNet), r2(r.gatewayFeesNoInstallment), r2(r.clientInstallmentExtra),
]);
styleHeader(detail.getRange("A1:S1"));
detail.freezePanes.freezeRows(1);
setWidths(detail, [230, 135, 180, 220, 120, 280, 85, 70, 90, 125, 125, 130, 135, 90, 90, 125, 150, 140, 160]);
detail.getRange(`B2:B${totalRows.length + 1}`).format.numberFormat = "dd/mm/yyyy hh:mm";
detail.getRange(`J2:S${totalRows.length + 1}`).format.numberFormat = 'R$ #,##0.00';
detail.getRange(`H2:H${totalRows.length + 1}`).format.numberFormat = "0";

function writeSummarySheet(sheet, title, rows, tableName) {
  styleTitle(sheet, "A1:I2", title, "Valores calculados usando o preço normal do produto, separado do acréscimo de parcelamento.");
  setWidths(sheet, [300, 80, 125, 125, 125, 90, 90, 125, 145]);
  sheet.getRange("A4:I4").values = [["Categoria", "Vendas", "Preço normal", "Total pago", "Parcelamento", "MDR", "Taxa fixa", "Líquido vendedor", "Dif. produto x líquido"]];
  sheet.getRange(`A5:I${rows.length + 4}`).values = rows.map((r) => [r.key, r.count, fmtMoney(r.itemPrice), fmtMoney(r.totalPaid), fmtMoney(r.installmentFee), fmtMoney(r.mdr), fmtMoney(r.transactionFee), fmtMoney(r.sellerNet), fmtMoney(r.productVsNet)]);
  styleHeader(sheet.getRange("A4:I4"));
  sheet.getRange(`C5:I${rows.length + 4}`).format.numberFormat = 'R$ #,##0.00';
  sheet.freezePanes.freezeRows(4);
}

writeSummarySheet(productSheet, "Resumo por produto", byProduct, "TabelaPorProduto");
writeSummarySheet(paymentSheet, "Resumo por forma de pagamento", byPayment, "TabelaPorPagamento");
writeSummarySheet(daySheet, "Resumo por dia", byDay, "TabelaPorDia");

sourceSheet.getRange("A1:F1").merge();
sourceSheet.getRange("A1:F1").values = [["Fonte e critérios de cálculo"]];
styleHeader(sourceSheet.getRange("A1:F1"));
sourceSheet.getRange("A3:B11").values = [
  ["Arquivo fonte", inputCsv],
  ["Período", "Agosto/2026"],
  ["Filtro principal", "status_type = approved e paid_date em agosto/2026"],
  ["Preço normal do produto", "item_price * item_quant"],
  ["Total pago pelo cliente", "total"],
  ["Acréscimo de parcelamento", "total - preço normal do produto"],
  ["Líquido vendedor", "total_liquid"],
  ["Diferença produto x líquido", "preço normal do produto - total_liquid"],
  ["Taxas sem parcelamento", "mdr_value + fee_transaction"],
];
styleBlock(sourceSheet.getRange("A3:B11"), "#F8FBFB");
setWidths(sourceSheet, [220, 760, 120, 120, 120, 120]);

await fs.mkdir(outputDir, { recursive: true });

for (const sheetName of ["Resumo", "Vendas detalhadas", "Por produto", "Por pagamento", "Por dia", "Fonte e criterios"]) {
  const preview = await workbook.render({ sheetName, autoCrop: "all", scale: 1, format: "png" });
  await fs.writeFile(path.join(outputDir, `${safeSheetName(sheetName)}.png`), new Uint8Array(await preview.arrayBuffer()));
}

const inspect = await workbook.inspect({ kind: "table", range: "Resumo!A1:H15", include: "values,formulas", tableMaxRows: 20, tableMaxCols: 10 });
console.log(inspect.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 300 }, summary: "final formula error scan" });
console.log(errors.ndjson);

const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputXlsx);
console.log(outputXlsx);
