import fs from "node:fs/promises";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const outputDir = new URL("./", import.meta.url);
const fyStart = "2025-04-01";
const fyEnd = "2026-03-31";
const standardRate = 15;

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};
const pad = (value, length = 3) => String(value).padStart(length, "0");
const money = cents => `${cents < 0 ? "-" : ""}${Math.floor(Math.abs(cents) / 100)}.${pad(Math.abs(cents) % 100, 2)}`;
const csvCell = value => {
  const text = String(value ?? "");
  return /[",\r\n]/.test(text) ? `"${text.replaceAll('"', '""')}"` : text;
};
const csv = rows => rows.map(row => row.map(csvCell).join(",")).join("\r\n") + "\r\n";
const dateText = date => date.toISOString().slice(0, 10);
const addDays = (text, days) => {
  const date = new Date(`${text}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return dateText(date);
};
const addDaysWithinYear = (text, days) => {
  const result = addDays(text, days);
  return result > fyEnd ? fyEnd : result;
};
const businessDate = (index, salt = 0) => {
  const date = new Date("2025-04-01T00:00:00Z");
  const offset = (index * 17 + salt * 29 + Math.floor(index / 7) * 3) % 365;
  date.setUTCDate(date.getUTCDate() + offset);
  if (index % 11 === 0) {
    date.setUTCMonth(date.getUTCMonth() + 1, 0);
  }
  while (date.getUTCDay() === 0 || date.getUTCDay() === 6) date.setUTCDate(date.getUTCDate() - 1);
  if (date < new Date(`${fyStart}T00:00:00Z`)) return fyStart;
  if (date > new Date(`${fyEnd}T00:00:00Z`)) return fyEnd;
  return dateText(date);
};

const files = new Map();
const writeCsv = (name, headers, rows, meta) => files.set(name, { headers, rows, meta, content: csv([headers, ...rows]) });

writeCsv("00_accounting_entity_import.csv", ["Entity Name", "Entity Type", "Legal Name", "Country / Jurisdiction", "Base Currency", "Timezone", "Financial Year Start", "Financial Year End", "Address", "Email", "Phone"], [["Arua Demo Trading Ltd", "Company", "Arua Demo Trading Ltd", "NZ", "NZD", "Pacific/Auckland", "2025-04-01", "2026-03-31", "18 Example Quay, Auckland 1010", "accounts@aruademotrading.example", "+64 9 555 0100"]],
  { importable: true, type: "accounting entity import", purpose: "Creates the sample Company through the standard entity workflow" });

const systemAccounts = [
  ["1000", "Cash and Cash Equivalents", "asset"], ["1100", "Accounts Receivable", "asset"],
  ["2000", "Accounts Payable", "liability"], ["3000", "Owner Equity", "equity"],
  ["4000", "Revenue", "revenue"], ["5000", "Operating Expenses", "expense"],
];
const importedAccounts = [
  ["1010", "Business Bank Account", "asset", "debit"], ["1020", "Petty Cash", "asset", "debit"],
  ["1200", "Input Tax Recoverable", "asset", "debit"], ["1300", "Prepayments", "asset", "debit"],
  ["1400", "Other Current Assets", "asset", "debit"], ["1500", "Office Equipment", "asset", "debit"],
  ["1510", "Computer Equipment", "asset", "debit"], ["1520", "Vehicles", "asset", "debit"],
  ["2010", "Trade Creditors Control", "liability", "credit"], ["2100", "Output Tax Payable", "liability", "credit"],
  ["2200", "Accrued Expenses", "liability", "credit"], ["2300", "Loan Payable", "liability", "credit"],
  ["3010", "Share Capital", "equity", "credit"], ["3020", "Retained Earnings", "equity", "credit"],
  ["4010", "Product Sales", "income", "credit"], ["4020", "Service Revenue", "income", "credit"],
  ["4030", "Delivery Income", "income", "credit"], ["4040", "Other Income", "income", "credit"],
  ["5010", "Product Purchases", "expense", "debit"], ["5100", "Freight and Delivery", "expense", "debit"],
  ["5200", "Rent", "expense", "debit"], ["5300", "Electricity", "expense", "debit"],
  ["5400", "Telephone", "expense", "debit"], ["5410", "Internet", "expense", "debit"],
  ["5500", "Office Supplies", "expense", "debit"], ["5600", "Advertising", "expense", "debit"],
  ["5700", "Insurance", "expense", "debit"], ["5800", "Travel", "expense", "debit"],
  ["5810", "Vehicle Expenses", "expense", "debit"], ["5900", "Repairs and Maintenance", "expense", "debit"],
  ["6000", "Bank Fees", "expense", "debit"], ["6100", "Professional Fees", "expense", "debit"],
  ["6110", "Contractor Expense", "expense", "debit"], ["6200", "Miscellaneous Expense", "expense", "debit"],
];
const accountCodes = new Set([...systemAccounts, ...importedAccounts].map(row => row[0]));

writeCsv("01_company_setup_reference.csv", ["Field", "Value"], [
  ["Company Name", "Arua Demo Trading Ltd"], ["Legal Name", "Arua Demo Trading Limited"],
  ["Entity Type", "Company"], ["Country / Jurisdiction", "New Zealand"], ["Base Currency", "NZD"],
  ["Timezone", "Pacific/Auckland"], ["Financial Year Start", fyStart], ["Financial Year End", fyEnd],
], { importable: false, purpose: "Manual entity setup reference" });
writeCsv("02_branches_reference.csv", ["Branch Code", "Branch Name", "Timezone", "Active"], [
  ["AKL", "Auckland", "Pacific/Auckland", "true"], ["WLG", "Wellington", "Pacific/Auckland", "true"],
], { importable: false, purpose: "Manual branch setup reference" });
writeCsv("02_tax_setup_reference.csv", ["Setting", "Value"], [
  ["Tax Type", "GST"], ["Registration Name", "Arua Demo Trading Ltd"], ["Registration Number", "DEMO-NZ-001"],
  ["Effective From", fyStart], ["Effective To", fyEnd], ["Accounting Basis", "accrual"],
  ["Filing Frequency", "two_monthly"], ["Standard Code", "STANDARD"], ["Standard Rate", "15.00"],
  ["Zero-rated Code", "ZERO"], ["Output Tax Account", "2100"], ["Input Tax Account", "1200"],
], { importable: false, purpose: "Generic v0.7 tax setup reference; not an NZ return configuration" });
writeCsv("03_chart_of_accounts.csv", ["Account Code", "Account Name", "Account Type", "Normal Balance", "Parent Account Code", "Active"],
  importedAccounts.map(row => [row[0], row[1], row[2], row[3], "", "true"]),
  { importable: true, type: "chart_of_accounts", purpose: "Adds 34 accounts to the six system accounts created with the entity" });

const customerPrefixes = ["Kauri", "Harbour", "Southern", "Tui", "Rimu", "Pacific", "Fern", "Summit", "Coastal", "Pohutukawa"];
const customerKinds = ["Design", "Engineering", "Hospitality", "Property", "Advisory"];
const customers = Array.from({ length: 50 }, (_, i) => {
  const number = i + 1;
  const name = number % 7 === 0 ? `Fictional Customer ${pad(number)}` : `${customerPrefixes[i % customerPrefixes.length]} ${customerKinds[Math.floor(i / 10)]} ${pad(number)} Ltd`;
  return [`CUST${pad(number)}`, name, name.endsWith("Ltd") ? name.replace(" Ltd", " Limited") : name, `accounts${pad(number)}@demo-customer.example`, `+64 21 40${pad(number)}`, `${number} Sample Customer Road`, number % 3 === 0 ? 14 : 30, money(200000 + number * 25000), "true"];
});
writeCsv("04_customers.csv", ["Customer Code", "Customer Name", "Legal Name", "Email", "Phone", "Billing Address", "Payment Terms Days", "Credit Limit", "Active"], customers,
  { importable: true, type: "customers", purpose: "Fifty fictional customers" });

const supplierCategories = ["Office Supplies", "Telecommunications", "Utilities", "Equipment", "Freight", "Professional Services", "Maintenance", "Advertising", "Vehicle Services"];
const suppliers = Array.from({ length: 25 }, (_, i) => {
  const number = i + 1;
  const name = `${["North", "South", "East", "West", "Central"][i % 5]} ${supplierCategories[i % supplierCategories.length]} ${pad(number)} Ltd`;
  return [`SUP${pad(number)}`, name, name.replace(" Ltd", " Limited"), `billing${pad(number)}@demo-supplier.example`, `+64 21 50${pad(number)}`, `${number} Sample Supplier Avenue`, i % 4 === 0 ? 14 : 30, money(300000 + number * 40000), "true"];
});
writeCsv("05_suppliers.csv", ["Supplier Code", "Supplier Name", "Legal Name", "Email", "Phone", "Address", "Payment Terms Days", "Credit Limit", "Active"], suppliers,
  { importable: true, type: "suppliers", purpose: "Twenty-five fictional suppliers" });

const productAccounts = ["4010", "4010", "4030"];
const productExpenseAccounts = ["5010", "5010", "5100"];
const products = Array.from({ length: 24 }, (_, i) => {
  const number = i + 1;
  const sales = 4500 + (i % 8) * 1750;
  const purchase = Math.round(sales * (45 + (i % 4) * 5) / 100);
  return [`PROD${pad(number)}`, `Demo Product ${pad(number)}`, `Fictional trading product ${pad(number)}`, "product", "each", money(sales), money(purchase), productAccounts[i % 3], productExpenseAccounts[i % 3], i % 5 === 0 ? "ZERO" : "STANDARD", i % 6 === 0 ? "ZERO" : "STANDARD", "true"];
});
const services = Array.from({ length: 16 }, (_, i) => {
  const number = i + 1;
  const sales = 8500 + (i % 8) * 2500;
  const purchase = Math.round(sales * 35 / 100);
  return [`SERV${pad(number)}`, `Demo Service ${pad(number)}`, `Fictional consulting or support service ${pad(number)}`, "service", i % 3 === 0 ? "hour" : "job", money(sales), money(purchase), "4020", "6110", i % 7 === 0 ? "ZERO" : "STANDARD", i % 7 === 0 ? "ZERO" : "STANDARD", "true"];
});
const items = [...products, ...services];
const itemByCode = new Map(items.map(row => [row[0], { code: row[0], name: row[1], type: row[3], sales: Math.round(Number(row[5]) * 100), purchase: Math.round(Number(row[6]) * 100), revenue: row[7], expense: row[8], salesTax: row[9], purchaseTax: row[10] }]));
writeCsv("06_products_services.csv", ["Code / SKU", "Name", "Description", "Type", "Unit", "Sales Price", "Purchase Price", "Sales Account", "Purchase Account", "Default Sales Tax Code", "Default Purchase Tax Code", "Active"], items,
  { importable: true, type: "products", purpose: "Twenty-four products and sixteen services" });

const ledger = new Map([...accountCodes].map(code => [code, { debit: 0, credit: 0 }]));
const post = (debits, credits) => {
  const dr = debits.reduce((sum, [, amount]) => sum + amount, 0);
  const cr = credits.reduce((sum, [, amount]) => sum + amount, 0);
  assert(dr === cr, `Unbalanced generated entry: ${dr} != ${cr}`);
  for (const [code, amount] of debits) { assert(ledger.has(code), `Unknown debit account ${code}`); ledger.get(code).debit += amount; }
  for (const [code, amount] of credits) { assert(ledger.has(code), `Unknown credit account ${code}`); ledger.get(code).credit += amount; }
};

const salesHeaders = ["Invoice Number / Source ID", "Customer Code", "Invoice Date", "Due Date", "Branch Code", "Product / Service Code", "Revenue Account", "Description", "Quantity", "Unit Price", "Discount Amount", "Tax Code", "Tax Inclusive", "Source Tax Amount"];
const salesRows = [];
const invoiceSummaries = [];
let salesNet = 0, salesTax = 0, salesGross = 0;
for (let i = 1; i <= 360; i++) {
  const ref = `INV${pad(i, 4)}`;
  const customer = `CUST${pad(((i * 7) % 50) + 1)}`;
  const date = businessDate(i, 1);
  const due = addDaysWithinYear(date, i % 3 === 0 ? 14 : 30);
  const branch = i % 3 === 0 ? "WLG" : "AKL";
  const lineCount = 1 + ((i * 7) % 4);
  let netTotal = 0, taxTotal = 0;
  for (let line = 1; line <= lineCount; line++) {
    const item = itemByCode.get(items[(i * 11 + line * 5) % items.length][0]);
    const quantity = 1 + ((i + line) % 5);
    const unitPrice = item.sales + ((i + line) % 4) * 250;
    const grossBeforeDiscount = quantity * unitPrice;
    const discount = (i + line) % 13 === 0 ? Math.round(grossBeforeDiscount * 5 / 100) : 0;
    const net = grossBeforeDiscount - discount;
    const taxCode = (i + line) % 5 === 0 ? "ZERO" : "STANDARD";
    const tax = taxCode === "STANDARD" ? Math.round(net * standardRate / 100) : 0;
    salesRows.push([ref, customer, date, due, branch, item.code, item.revenue, `${item.name} sale`, quantity, money(unitPrice), money(discount), taxCode, "false", money(tax)]);
    netTotal += net; taxTotal += tax;
    post([["1100", net + tax]], [[item.revenue, net], ...(tax ? [["2100", tax]] : [])]);
  }
  const grossTotal = netTotal + taxTotal;
  invoiceSummaries.push([ref, customer, date, due, branch, lineCount, money(netTotal), money(taxTotal), money(grossTotal)]);
  salesNet += netTotal; salesTax += taxTotal; salesGross += grossTotal;
}
writeCsv("07_sales_invoices.csv", salesHeaders, salesRows, { importable: true, type: "sales_invoices", purpose: "Three hundred sixty grouped sales invoices" });
writeCsv("07_sales_invoice_summary_reference.csv", ["Invoice Number", "Customer Code", "Invoice Date", "Due Date", "Branch Code", "Line Count", "Net", "Tax", "Gross"], invoiceSummaries,
  { importable: false, purpose: "Reference totals for validating imported grouped invoices" });

const receiptRows = [];
let receiptTotal = 0;
for (let i = 0; i < 240; i++) {
  const invoice = invoiceSummaries[i];
  const invoiceGross = Math.round(Number(invoice[8]) * 100);
  const amount = i % 5 < 3 ? invoiceGross : Math.round(invoiceGross * (55 + (i % 4) * 10) / 100);
  const receiptDate = addDaysWithinYear(invoice[2], 5 + (i % 24));
  receiptRows.push([`REC${pad(i + 1, 4)}`, invoice[1], invoice[0], receiptDate, invoice[4], money(amount), i % 4 === 0 ? "card" : "bank_transfer", "1010"]);
  receiptTotal += amount;
  post([["1010", amount]], [["1100", amount]]);
}
writeCsv("08_customer_receipts_reference.csv", ["Receipt Reference", "Customer Code", "Invoice Number", "Receipt Date", "Branch Code", "Amount", "Payment Method", "Receiving Account"], receiptRows,
  { importable: false, purpose: "REFERENCE DATA – CURRENTLY NOT DIRECTLY IMPORTABLE; enter through Sales receipts workflow" });

const billHeaders = ["Bill Number / Source ID", "Supplier Code", "Bill Date", "Due Date", "Branch Code", "Product / Service Code", "Expense / Asset Account", "Description", "Quantity", "Unit Price", "Discount Amount", "Tax Code", "Tax Inclusive", "Source Tax Amount"];
const billRows = [];
const billSummaries = [];
let purchaseNet = 0, purchaseTax = 0, purchaseGross = 0;
for (let i = 1; i <= 190; i++) {
  const ref = `BILL${pad(i, 4)}`;
  const supplier = `SUP${pad(((i * 3) % 25) + 1)}`;
  const date = businessDate(i, 5);
  const due = addDaysWithinYear(date, i % 4 === 0 ? 14 : 30);
  const branch = i % 4 === 0 ? "WLG" : "AKL";
  const lineCount = 1 + ((i * 5) % 3);
  let netTotal = 0, taxTotal = 0;
  for (let line = 1; line <= lineCount; line++) {
    const item = itemByCode.get(items[(i * 9 + line * 7) % items.length][0]);
    const quantity = 1 + ((i + line) % 4);
    const unitPrice = item.purchase + ((i + line) % 3) * 175;
    const grossBeforeDiscount = quantity * unitPrice;
    const discount = (i + line) % 17 === 0 ? Math.round(grossBeforeDiscount * 3 / 100) : 0;
    const net = grossBeforeDiscount - discount;
    const taxCode = (i + line) % 6 === 0 ? "ZERO" : "STANDARD";
    const tax = taxCode === "STANDARD" ? Math.round(net * standardRate / 100) : 0;
    billRows.push([ref, supplier, date, due, branch, item.code, item.expense, `${item.name} purchase`, quantity, money(unitPrice), money(discount), taxCode, "false", money(tax)]);
    netTotal += net; taxTotal += tax;
    post([[item.expense, net], ...(tax ? [["1200", tax]] : [])], [["2000", net + tax]]);
  }
  const grossTotal = netTotal + taxTotal;
  billSummaries.push([ref, supplier, date, due, branch, lineCount, money(netTotal), money(taxTotal), money(grossTotal)]);
  purchaseNet += netTotal; purchaseTax += taxTotal; purchaseGross += grossTotal;
}
writeCsv("09_supplier_bills.csv", billHeaders, billRows, { importable: true, type: "supplier_bills", purpose: "One hundred ninety grouped supplier bills" });
writeCsv("09_supplier_bill_summary_reference.csv", ["Bill Number", "Supplier Code", "Bill Date", "Due Date", "Branch Code", "Line Count", "Net", "Tax", "Gross"], billSummaries,
  { importable: false, purpose: "Reference totals for validating imported grouped bills" });

const paymentRows = [];
let paymentTotal = 0;
for (let i = 0; i < 140; i++) {
  const bill = billSummaries[i];
  const billGross = Math.round(Number(bill[8]) * 100);
  const amount = i % 4 < 2 ? billGross : Math.round(billGross * (60 + (i % 3) * 10) / 100);
  const paymentDate = addDaysWithinYear(bill[2], 7 + (i % 22));
  paymentRows.push([`SPAY${pad(i + 1, 4)}`, bill[1], bill[0], paymentDate, bill[4], money(amount), "1010"]);
  paymentTotal += amount;
  post([["2000", amount]], [["1010", amount]]);
}
writeCsv("10_supplier_payments_reference.csv", ["Payment Reference", "Supplier Code", "Bill Number", "Payment Date", "Branch Code", "Amount", "Payment Account"], paymentRows,
  { importable: false, purpose: "REFERENCE DATA – CURRENTLY NOT DIRECTLY IMPORTABLE; enter through Purchases payments workflow" });

const journalRows = [];
let manualDebits = 0, manualCredits = 0;
const bankJournalEvidence = [];
for (let i = 1; i <= 40; i++) {
  const ref = `JRN${pad(i, 4)}`;
  const date = businessDate(i, 9);
  const branch = i % 3 === 0 ? "WLG" : "AKL";
  let debit, credit, amount, description;
  if (i <= 12) { debit = "6100"; credit = "2200"; amount = 25000 + i * 3500; description = "Month-end professional fee accrual"; }
  else if (i <= 24) { debit = "1300"; credit = "5700"; amount = 18000 + (i - 12) * 2000; description = "Insurance prepayment adjustment"; }
  else if (i <= 32) { debit = "6000"; credit = "1010"; amount = 1800 + (i - 24) * 225; description = "Bank service fee adjustment"; bankJournalEvidence.push([date, -amount, ref, description]); }
  else if (i <= 36) { debit = "1010"; credit = "3010"; amount = 250000 + (i - 32) * 50000; description = "Shareholder capital contribution"; bankJournalEvidence.push([date, amount, ref, description]); }
  else { debit = "5500"; credit = "6200"; amount = 12000 + (i - 36) * 1750; description = "Expense classification correction"; }
  journalRows.push([ref, date, debit, money(amount), "0.00", description, branch]);
  journalRows.push([ref, date, credit, "0.00", money(amount), description, branch]);
  manualDebits += amount; manualCredits += amount;
  post([[debit, amount]], [[credit, amount]]);
}
writeCsv("11_manual_journals.csv", ["Journal Reference", "Date", "Account Code", "Debit", "Credit", "Description", "Branch Code"], journalRows,
  { importable: true, type: "manual_journals", purpose: "Forty balanced adjusting journals" });

const directBankEvidence = [];
let directBankIncome = 0, directBankExpense = 0;
const directExpenseAccounts = ["5200", "5300", "5400", "5410", "5500", "5600", "5810", "5900", "6000", "6100"];
for (let i = 1; i <= 150; i++) {
  const date = businessDate(i, 13);
  const ref = `BANK${pad(i, 4)}`;
  if (i % 5 === 0) {
    const amount = 1500 + (i % 11) * 425;
    directBankEvidence.push([date, amount, ref, "Interest or direct income"]);
    directBankIncome += amount;
    post([["1010", amount]], [["4040", amount]]);
  } else {
    const amount = 3500 + (i % 17) * 725;
    const account = directExpenseAccounts[i % directExpenseAccounts.length];
    directBankEvidence.push([date, -amount, ref, `Direct bank expense ${account}`]);
    directBankExpense += amount;
    post([[account, amount]], [["1010", amount]]);
  }
}

const statementRows = [
  ...receiptRows.map(row => [row[3], row[5], row[0], `Customer receipt ${row[2]}`]),
  ...paymentRows.map(row => [row[3], money(-Math.round(Number(row[5]) * 100)), row[0], `Supplier payment ${row[2]}`]),
  ...bankJournalEvidence.map(row => [row[0], money(row[1]), row[2], row[3]]),
  ...directBankEvidence.map(row => [row[0], money(row[1]), row[2], row[3]]),
].sort((a, b) => a[0].localeCompare(b[0]) || a[2].localeCompare(b[2]));
writeCsv("12_bank_statement.csv", ["Date", "Amount", "Reference", "Description"], statementRows,
  { importable: true, type: "existing v0.5 bank statement importer", purpose: "Evidence-only statement; import creates no journals" });

const openingRows = [
  ["OPENING-2025", fyStart, "1010", "50000.00", "0.00", "Opening business bank"],
  ["OPENING-2025", fyStart, "1020", "2000.00", "0.00", "Opening petty cash"],
  ["OPENING-2025", fyStart, "1100", "12000.00", "0.00", "Opening receivables"],
  ["OPENING-2025", fyStart, "1300", "3000.00", "0.00", "Opening prepayments"],
  ["OPENING-2025", fyStart, "1500", "30000.00", "0.00", "Opening equipment"],
  ["OPENING-2025", fyStart, "2000", "0.00", "8000.00", "Opening payables"],
  ["OPENING-2025", fyStart, "2300", "0.00", "25000.00", "Opening loan"],
  ["OPENING-2025", fyStart, "3010", "0.00", "50000.00", "Opening share capital"],
  ["OPENING-2025", fyStart, "3020", "0.00", "14000.00", "Opening retained earnings"],
];
writeCsv("13_opening_balances.csv", ["Opening Balance Reference", "Date", "Account Code", "Debit", "Credit", "Description"], openingRows,
  { importable: true, type: "opening_balances", purpose: "Balanced staging only; v0.8 cannot post this batch" });

const customerCodes = new Set(customers.map(row => row[0]));
const supplierCodes = new Set(suppliers.map(row => row[0]));
const itemCodes = new Set(items.map(row => row[0]));
const branchCodes = new Set(["AKL", "WLG"]);
const invoiceIds = new Set(invoiceSummaries.map(row => row[0]));
const billIds = new Set(billSummaries.map(row => row[0]));
assert(invoiceIds.size === invoiceSummaries.length, "Duplicate invoice identifiers");
assert(billIds.size === billSummaries.length, "Duplicate bill identifiers");
for (const row of salesRows) {
  assert(customerCodes.has(row[1]), `Unknown customer ${row[1]}`); assert(branchCodes.has(row[4]), `Unknown branch ${row[4]}`);
  assert(itemCodes.has(row[5]), `Unknown item ${row[5]}`); assert(accountCodes.has(row[6]), `Unknown revenue account ${row[6]}`);
  assert(row[2] >= fyStart && row[2] <= fyEnd, `Sales date outside FY ${row[2]}`);
  assert(row[3] >= fyStart && row[3] <= fyEnd, `Sales due date outside FY ${row[3]}`);
  const net = Math.round(Number(row[8]) * Math.round(Number(row[9]) * 100)) - Math.round(Number(row[10]) * 100);
  const tax = row[11] === "STANDARD" ? Math.round(net * standardRate / 100) : 0;
  assert(tax === Math.round(Number(row[13]) * 100), `Sales tax mismatch ${row[0]}`);
}
for (const row of billRows) {
  assert(supplierCodes.has(row[1]), `Unknown supplier ${row[1]}`); assert(branchCodes.has(row[4]), `Unknown branch ${row[4]}`);
  assert(itemCodes.has(row[5]), `Unknown item ${row[5]}`); assert(accountCodes.has(row[6]), `Unknown expense account ${row[6]}`);
  assert(row[2] >= fyStart && row[2] <= fyEnd, `Bill date outside FY ${row[2]}`);
  assert(row[3] >= fyStart && row[3] <= fyEnd, `Bill due date outside FY ${row[3]}`);
  const net = Math.round(Number(row[8]) * Math.round(Number(row[9]) * 100)) - Math.round(Number(row[10]) * 100);
  const tax = row[11] === "STANDARD" ? Math.round(net * standardRate / 100) : 0;
  assert(tax === Math.round(Number(row[13]) * 100), `Bill tax mismatch ${row[0]}`);
}
const invoiceGrossById = new Map(invoiceSummaries.map(row => [row[0], Math.round(Number(row[8]) * 100)]));
const billGrossById = new Map(billSummaries.map(row => [row[0], Math.round(Number(row[8]) * 100)]));
for (const row of receiptRows) {
  assert(customerCodes.has(row[1]) && invoiceIds.has(row[2]), `Invalid receipt allocation ${row[0]}`);
  assert(row[3] >= fyStart && row[3] <= fyEnd, `Receipt date outside FY ${row[3]}`);
  assert(Math.round(Number(row[5]) * 100) <= invoiceGrossById.get(row[2]), `Receipt exceeds invoice ${row[0]}`);
}
for (const row of paymentRows) {
  assert(supplierCodes.has(row[1]) && billIds.has(row[2]), `Invalid payment allocation ${row[0]}`);
  assert(row[3] >= fyStart && row[3] <= fyEnd, `Payment date outside FY ${row[3]}`);
  assert(Math.round(Number(row[5]) * 100) <= billGrossById.get(row[2]), `Payment exceeds bill ${row[0]}`);
}
for (let i = 0; i < journalRows.length; i += 2) {
  assert(journalRows[i][0] === journalRows[i + 1][0], `Journal grouping mismatch ${journalRows[i][0]}`);
  assert(Math.round(Number(journalRows[i][3]) * 100) === Math.round(Number(journalRows[i + 1][4]) * 100), `Journal imbalance ${journalRows[i][0]}`);
  assert(journalRows[i][1] >= fyStart && journalRows[i][1] <= fyEnd, `Journal date outside FY ${journalRows[i][1]}`);
}
for (const row of statementRows) assert(row[0] >= fyStart && row[0] <= fyEnd, `Bank statement date outside FY ${row[0]}`);
const openingDebits = openingRows.reduce((sum, row) => sum + Math.round(Number(row[3]) * 100), 0);
const openingCredits = openingRows.reduce((sum, row) => sum + Math.round(Number(row[4]) * 100), 0);
assert(openingDebits === openingCredits, "Opening balances do not balance");

const arOutstanding = salesGross - receiptTotal;
const apOutstanding = purchaseGross - paymentTotal;
const bankMovement = [...statementRows].reduce((sum, row) => sum + Math.round(Number(row[1]) * 100), 0);
const ledgerBank = ledger.get("1010").debit - ledger.get("1010").credit;
assert(bankMovement === ledgerBank, `Bank statement does not match pro forma ledger: ${bankMovement} vs ${ledgerBank}`);
assert(arOutstanding === ledger.get("1100").debit - ledger.get("1100").credit, "AR control mismatch");
assert(apOutstanding === ledger.get("2000").credit - ledger.get("2000").debit, "AP control mismatch");
assert(salesNet === [...invoiceSummaries].reduce((sum, row) => sum + Math.round(Number(row[6]) * 100), 0), "Sales net summary mismatch");
assert(salesGross === [...invoiceSummaries].reduce((sum, row) => sum + Math.round(Number(row[8]) * 100), 0), "Sales gross summary mismatch");
assert(purchaseNet === [...billSummaries].reduce((sum, row) => sum + Math.round(Number(row[6]) * 100), 0), "Purchase net summary mismatch");
assert(purchaseGross === [...billSummaries].reduce((sum, row) => sum + Math.round(Number(row[8]) * 100), 0), "Purchase gross summary mismatch");

let trialDebits = 0, trialCredits = 0;
for (const balance of ledger.values()) {
  const net = balance.debit - balance.credit;
  if (net >= 0) trialDebits += net; else trialCredits += -net;
}
assert(trialDebits === trialCredits, `Trial balance mismatch ${trialDebits} vs ${trialCredits}`);
const incomeCodes = new Set(["4000", "4010", "4020", "4030", "4040"]);
const expenseCodes = new Set([...importedAccounts.filter(row => row[2] === "expense").map(row => row[0]), "5000"]);
const revenue = [...incomeCodes].reduce((sum, code) => sum + ledger.get(code).credit - ledger.get(code).debit, 0);
const expenses = [...expenseCodes].reduce((sum, code) => sum + ledger.get(code).debit - ledger.get(code).credit, 0);
const profit = revenue - expenses;
const assetCodes = [...systemAccounts, ...importedAccounts].filter(row => row[2] === "asset").map(row => row[0]);
const liabilityCodes = [...systemAccounts, ...importedAccounts].filter(row => row[2] === "liability").map(row => row[0]);
const equityCodes = [...systemAccounts, ...importedAccounts].filter(row => row[2] === "equity").map(row => row[0]);
const assets = assetCodes.reduce((sum, code) => sum + ledger.get(code).debit - ledger.get(code).credit, 0);
const liabilities = liabilityCodes.reduce((sum, code) => sum + ledger.get(code).credit - ledger.get(code).debit, 0);
const equity = equityCodes.reduce((sum, code) => sum + ledger.get(code).credit - ledger.get(code).debit, 0);
assert(assets === liabilities + equity + profit, `Balance sheet mismatch ${assets} vs ${liabilities + equity + profit}`);

const controls = [
  ["Total Sales Net", salesNet], ["Total Sales Tax", salesTax], ["Total Sales Gross", salesGross],
  ["Total Purchase Net", purchaseNet], ["Total Purchase Tax", purchaseTax], ["Total Purchase Gross", purchaseGross],
  ["Total Customer Receipts", receiptTotal], ["Outstanding AR", arOutstanding],
  ["Total Supplier Payments", paymentTotal], ["Outstanding AP", apOutstanding], ["Total Bank Movement", bankMovement],
  ["Total Manual Journal Debits", manualDebits], ["Total Manual Journal Credits", manualCredits],
  ["Expected Trial Balance Debits", trialDebits], ["Expected Trial Balance Credits", trialCredits],
  ["Expected P&L Revenue", revenue], ["Expected P&L Expenses", expenses], ["Expected Profit", profit],
  ["Expected Balance Sheet Assets", assets], ["Expected Balance Sheet Liabilities", liabilities], ["Expected Balance Sheet Equity Before Profit", equity],
];

const totalMeaningfulRows = importedAccounts.length + customers.length + suppliers.length + items.length + salesRows.length + receiptRows.length + billRows.length + paymentRows.length + journalRows.length + statementRows.length + openingRows.length;
const controlMarkdown = [
  "# Expected Control Totals", "", "All amounts are NZD. These controls describe the complete operational dataset after directly importable documents are posted and reference-only receipts, payments, and direct bank entries are entered through their existing Arua workflows. Bank statement rows are evidence and must not be posted twice.", "",
  "Opening balances are excluded from the operational totals because v0.8 supports balanced staging but not posting. The opening batch separately balances at NZD 97,000.00 debit and credit.", "",
  "| Control | Expected NZD |", "| --- | ---: |", ...controls.map(([label, cents]) => `| ${label} | ${money(cents)} |`), "",
  `- Direct bank income included in P&L: NZD ${money(directBankIncome)}`,
  `- Direct bank expenses included in P&L: NZD ${money(directBankExpense)}`,
  `- Opening balance staging debit: NZD ${money(openingDebits)}`,
  `- Opening balance staging credit: NZD ${money(openingCredits)}`,
  "- Balance Sheet equation verified: Assets = Liabilities + Equity + current-year Profit.",
].join("\n") + "\n";

const manifestRows = [...files.entries()].map(([name, file]) => [name, file.rows.length, file.meta.importable ? "Yes" : "No", file.meta.type ?? "Reference/manual workflow", file.meta.purpose]);
const usageRows = [
  ["00_accounting_entity_import.csv", "Select New Zealand jurisdiction", "Create Arua Demo Trading Ltd through the standard entity creation workflow", "Direct entity import"],
  ["01_company_setup_reference.csv", "None", "Manually create the correctly scoped NZD company and financial year", "Reference only"],
  ["02_branches_reference.csv", "Company exists", "Create AKL and WLG branches", "Reference only"],
  ["02_tax_setup_reference.csv", "Company and tax control accounts exist", "Configure the generic v0.7 registration, codes, and 15%/zero rates", "Reference only"],
  ["03_chart_of_accounts.csv", "Company exists with its six system accounts", "Add 34 accounts; resulting chart contains 40 accounts", "Direct import"],
  ["04_customers.csv", "Company and account 1100 exist", "Create 50 active customers", "Direct import"],
  ["05_suppliers.csv", "Company and account 2000 exist", "Create 25 active suppliers", "Direct import"],
  ["06_products_services.csv", "Accounts and generic tax codes exist", "Create 24 products and 16 services", "Direct import"],
  ["07_sales_invoices.csv", "Customers, items, accounts, branches, tax codes, and open FY exist", "Stage 360 draft invoices containing 900 lines", "Direct import"],
  ["07_sales_invoice_summary_reference.csv", "Sales invoice import completed", "Verify invoice-level net, tax, gross, dates, and line counts", "Reference only"],
  ["08_customer_receipts_reference.csv", "Relevant invoices are posted and bank account exists", "Enter 240 full/partial allocations through Sales; leave the remainder outstanding", "Manual workflow"],
  ["09_supplier_bills.csv", "Suppliers, items, accounts, branches, tax codes, and open FY exist", "Stage 190 draft bills containing 381 lines", "Direct import"],
  ["09_supplier_bill_summary_reference.csv", "Supplier bill import completed", "Verify bill-level net, tax, gross, dates, and line counts", "Reference only"],
  ["10_supplier_payments_reference.csv", "Relevant bills are posted and bank account exists", "Enter 140 full/partial allocations through Purchases; leave the remainder outstanding", "Manual workflow"],
  ["11_manual_journals.csv", "Accounts, branches, and open FY exist", "Stage 40 balanced journals containing 80 lines", "Direct import"],
  ["12_bank_statement.csv", "NZD bank account linked to account 1010 exists", "Import 542 evidence rows; match existing entries and create only the 150 BANK entries", "Direct evidence import"],
  ["13_opening_balances.csv", "Accounts and FY exist", "Validate one NZD 97,000 debit/credit batch without posting it", "Direct staging only"],
];
const legacyGeneratedReadme = [
  "# Arua Demo Trading Ltd", "", "Realistic fictional sample business data for manual testing of Arua Accounting System v0.8.", "",
  "## Accounting Entity import", "", "Use `00_accounting_entity_import.csv` from **Import & Export → New Zealand → Import New Accounting Entity**. Validate the mapped fields and explicitly confirm creation. This creates the Company, its standard Head Office branch, financial year, accounting periods, ownership, and system accounts through Arua's normal Accounting Entity creation service.", "", "After creation, open **Import Data Into This Entity**. Create the additional `AKL` and `WLG` branches from `02_branches_reference.csv`, configure generic tax from `02_tax_setup_reference.csv`, and continue with the import order below. The entity import does not create transactions, additional branches, bank accounts, or tax configuration.", "",
  "## Manual setup", "", "1. Create **Arua Demo Trading Ltd** as a Company in New Zealand with NZD, Pacific/Auckland, and financial year 1 April 2025 to 31 March 2026.",
  "2. The application creates branch `HO` and system accounts 1000, 1100, 2000, 3000, 4000, and 5000. Create active branches `AKL` (Auckland) and `WLG` (Wellington).",
  "3. Configure the generic v0.7 tax registration using `02_tax_setup_reference.csv`: STANDARD 15%, ZERO zero-rated, output account 2100, input account 1200. This is generic test configuration, not NZ GST-return logic.",
  "4. Create an active NZD bank account linked to ledger account 1010 before importing the bank statement.", "",
  "## Import order", "", "1. Import `03_chart_of_accounts.csv` as Chart of Accounts.", "2. Import `04_customers.csv` as Customers.", "3. Import `05_suppliers.csv` as Suppliers.", "4. Import `06_products_services.csv` as Products / Services.",
  "5. Import `07_sales_invoices.csv` as Sales Invoices. Use Draft first; review and post through Arua.", "6. Import `09_supplier_bills.csv` as Supplier Bills. Use Draft first; review and post through Arua.",
  "7. Enter `08_customer_receipts_reference.csv` through the existing Customer Receipt workflow; direct CSV receipt import is not available in v0.8.", "8. Enter `10_supplier_payments_reference.csv` through the existing Supplier Payment workflow; direct CSV payment import is not available in v0.8.",
  "9. Import `11_manual_journals.csv` as Manual Journals, review, then post.", "10. Import `12_bank_statement.csv` through Banking as evidence only. Match receipt/payment/journal rows; use the existing create-from-statement workflow for BANK rows. Do not create duplicate accounting.",
  "11. Import `13_opening_balances.csv` only to validate balanced staging. v0.8 intentionally does not post opening balances.", "12. Compare reports with `expected_control_totals.md` after completing the applicable manual workflows.", "",
  "## File manifest", "", "| File | Rows | Directly importable | Importer/workflow | Purpose |", "| --- | ---: | --- | --- | --- |",
  ...manifestRows.map(row => `| ${row[0]} | ${row[1]} | ${row[2]} | ${row[3]} | ${row[4]} |`), "",
  "## File prerequisites and expected results", "", "| File | Required prerequisites | Expected result | Usage |", "| --- | --- | --- | --- |",
  ...usageRows.map(row => `| ${row[0]} | ${row[1]} | ${row[2]} | ${row[3]} |`), "",
  "## Dataset size", "", `- Resulting Chart of Accounts: ${systemAccounts.length + importedAccounts.length} accounts (${systemAccounts.length} system-created + ${importedAccounts.length} imported)`,
  `- Customers: ${customers.length}`, `- Suppliers: ${suppliers.length}`, `- Products / Services: ${items.length}`, `- Sales invoices: ${invoiceSummaries.length} documents / ${salesRows.length} lines`,
  `- Customer receipts: ${receiptRows.length}`, `- Supplier bills: ${billSummaries.length} documents / ${billRows.length} lines`, `- Supplier payments: ${paymentRows.length}`,
  `- Manual journals: 40 journals / ${journalRows.length} lines`, `- Bank statement: ${statementRows.length} evidence rows`, `- Opening balances: ${openingRows.length} staging rows`,
  `- Meaningful data rows, including reference summaries: ${totalMeaningfulRows}`, "",
  "All names, addresses, phone numbers, email addresses, references, and monetary activity are synthetic.",
].join("\n") + "\n";

// The setup/import guide is maintained as reviewed user-facing documentation.
// Preserve it when regenerating deterministic data files and control totals.
const readme = await fs.readFile(new URL("README.md", outputDir), "utf8");

files.set("README.md", { content: readme, rows: [], headers: [], meta: { importable: false } });
files.set("expected_control_totals.md", { content: controlMarkdown, rows: [], headers: [], meta: { importable: false } });

const validationReport = {
  status: "passed", generated_at: "2026-09-18", financial_year: { starts_on: fyStart, ends_on: fyEnd },
  checks: ["foreign references", "customer references", "supplier references", "product references", "account references", "branch references", "invoice arithmetic", "bill arithmetic", "receipt allocations", "supplier payment allocations", "tax arithmetic", "journal balancing", "duplicate document IDs", "date ranges", "bank-to-ledger reconciliation", "trial balance", "profit and loss", "balance sheet equation"],
  counts: { imported_accounts: importedAccounts.length, customers: customers.length, suppliers: suppliers.length, items: items.length, sales_invoices: invoiceSummaries.length, sales_invoice_lines: salesRows.length, customer_receipts: receiptRows.length, supplier_bills: billSummaries.length, supplier_bill_lines: billRows.length, supplier_payments: paymentRows.length, manual_journals: 40, manual_journal_lines: journalRows.length, bank_statement_rows: statementRows.length, opening_balance_rows: openingRows.length },
  controls: Object.fromEntries(controls.map(([label, cents]) => [label, money(cents)])),
};
files.set("validation_report.json", { content: JSON.stringify(validationReport, null, 2) + "\n", rows: [], headers: [], meta: { importable: false } });

for (const [name, file] of files) await fs.writeFile(new URL(name, outputDir), file.content, "utf8");

const workbook = Workbook.create();
const summary = workbook.worksheets.add("Summary");
summary.showGridLines = false;
summary.getRange("A2").values = [["Arua Demo Trading Ltd: Sample Business Dataset"]];
summary.getRange("A3:B6").values = [["Currency", "NZD"], ["Entity type", "Company"], ["Country", "New Zealand"], ["Financial year", "1 Apr 2025 to 31 Mar 2026"]];
summary.getRange("A8:B8").values = [["Dataset count", "Value"]];
summary.getRange("A9:B18").values = [
  ["Customers", customers.length], ["Suppliers", suppliers.length], ["Products / Services", items.length], ["Sales invoices", invoiceSummaries.length], ["Sales invoice lines", salesRows.length],
  ["Customer receipts", receiptRows.length], ["Supplier bills", billSummaries.length], ["Supplier bill lines", billRows.length], ["Supplier payments", paymentRows.length], ["Bank statement rows", statementRows.length],
];
const controlSheet = workbook.worksheets.add("Controls");
controlSheet.showGridLines = false;
controlSheet.getRange("A1:B1").values = [["Control", "Expected NZD"]];
controlSheet.getRange("A2").write(controls.map(([label, cents]) => [label, cents / 100]));
const manifestSheet = workbook.worksheets.add("File Manifest");
manifestSheet.showGridLines = false;
manifestSheet.getRange("A1:E1").values = [["File", "Rows", "Directly importable", "Importer / workflow", "Purpose"]];
manifestSheet.getRange("A2").write(manifestRows);
for (const sheet of [summary, controlSheet, manifestSheet]) {
  const used = sheet.getUsedRange();
  used.format.font = { name: "Arial", size: 10, color: "#1F2937" };
  used.format.verticalAlignment = "center";
  used.format.autofitColumns();
  used.format.autofitRows();
  sheet.freezePanes.freezeRows(sheet === summary ? 4 : 1);
}
summary.getRange("A2:D2").format = { font: { name: "Arial", size: 14, bold: true, color: "#1F2937" }, borders: { preset: "doubleBottom", style: "thin", color: "#1F4E78" } };
summary.getRange("A3:A6").format.font = { name: "Arial", size: 10, italic: true, color: "#5B6573" };
for (const range of [summary.getRange("A8:B8"), controlSheet.getRange("A1:B1"), manifestSheet.getRange("A1:E1")]) range.format = { fill: "#1F4E78", font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", verticalAlignment: "center" };
controlSheet.getRange(`B2:B${controls.length + 1}`).format.numberFormat = "$#,##0.00;($#,##0.00);-";
summary.tabColor = "#1F4E78"; controlSheet.tabColor = "#4472C4"; manifestSheet.tabColor = "#A5A5A5";
workbook.recalculate();
const inspect = await workbook.inspect({ kind: "workbook,sheet,table", maxChars: 12000, tableMaxRows: 25, tableMaxCols: 6 });
console.log(inspect.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!", options: { useRegex: true, maxResults: 100 }, summary: "formula errors" });
console.log(errors.ndjson);
const preview = await workbook.render({ sheetName: "Summary", autoCrop: "all", scale: 1, format: "png" });
await fs.writeFile(new URL(".summary-preview.png", outputDir), new Uint8Array(await preview.arrayBuffer()));
const xlsx = await SpreadsheetFile.exportXlsx(workbook);
await xlsx.save(new URL("AruaDemoTrading.xlsx", outputDir).pathname.replace(/^\/(.:)/, "$1"));

console.log(JSON.stringify(validationReport));
