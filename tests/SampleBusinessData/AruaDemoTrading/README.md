# Arua Demo Trading Ltd

Realistic fictional sample business data for manual testing of Arua Accounting System v0.8.

## Before importing transactions

- [ ] Accounting Entity created with financial year 1 April 2025 to 31 March 2026
- [ ] Chart of Accounts imported, including 1200 Input Tax Recoverable and 2100 Output Tax Payable
- [ ] Active branches AKL and WLG created
- [ ] Generic active Tax Registration `DEMO-NZ-001` configured
- [ ] Output Tax control account set to 2100
- [ ] Input Tax control account set to 1200
- [ ] STANDARD taxable code and 15% effective rate configured
- [ ] ZERO zero-rated code configured
- [ ] Open Tax Periods generated before posting tax-bearing documents
- [ ] Customers imported
- [ ] Suppliers imported
- [ ] Products / Services imported

## Required setup and import order

1. Import `00_accounting_entity_import.csv` at **Import & Export → New Zealand → + Import New Accounting Entity**. This creates the Company, Head Office branch, financial year, accounting periods, ownership, and six system accounts.
2. Review `01_company_setup_reference.csv`. It is reference-only. The entity import supplies all operational fields; its Legal Name is `Arua Demo Trading Ltd`, while the optional reference wording is `Arua Demo Trading Limited`. Change it at **Accounting Entities → Arua Demo Trading Ltd → Edit** only if that wording is wanted.
3. Import `03_chart_of_accounts.csv` at **Import & Export → New Zealand → Arua Demo Trading Ltd → Manage Import & Export → New Import → Chart of Accounts**. This must happen before tax control accounts are selected.
4. Create `AKL` and `WLG` from `02_branches_reference.csv` at **Accounting Entities → Arua Demo Trading Ltd → Manage Branches → Add Branch**. The CSV is reference-only and is not uploaded.
5. Open **Tax → New Zealand → Arua Demo Trading Ltd → Configuration** and add this active registration:
   - Name: `Generic GST`
   - Registration Number: `DEMO-NZ-001`
   - Registration Name: `Arua Demo Trading Ltd`
   - Tax Type: `GST`
   - Effective From: `2025-04-01`
   - Effective To: `2026-03-31`
   - Frequency: `Two Monthly`
   - Accounting Basis: `Accrual`
6. On the same Tax Configuration page, create these entity-scoped codes under that registration:
   - `STANDARD`: Name `Standard`, treatment `Taxable`, full recoverability, effective from `2025-04-01`. Add a `15.00%` rate effective `2025-04-01` to `2026-03-31`.
   - `ZERO`: Name `Zero-rated`, treatment `Zero Rated`, full recoverability, effective from `2025-04-01`. No rate row is required; v0.7 resolves non-taxable treatments at 0%.
   - v0.7 has no separate Sales/Purchases applicability switches. Both active codes may be used by Sales and Purchases.
7. Under **Tax → New Zealand → Arua Demo Trading Ltd → Configuration → Tax Settings**, select `2100 — Output Tax Payable` and `1200 — Input Tax Recoverable`, then save.
8. Click **Generate Periods** for `DEMO-NZ-001`. Tax Periods are not required merely to validate a draft import, but an open period covering the transaction date is required before posting.
9. Import `04_customers.csv` as Customers, then `05_suppliers.csv` as Suppliers.
10. Import `06_products_services.csv` as Products / Services. Its `STANDARD` and `ZERO` defaults now resolve against the entity tax configuration.
11. Import `07_sales_invoices.csv` as Sales Invoices in Draft mode. Review and post through Sales.
12. Import `09_supplier_bills.csv` as Supplier Bills in Draft mode. Review and post through Purchases.
13. Enter `08_customer_receipts_reference.csv` through **Sales → Customer Receipts** after the relevant invoices are posted. It is not directly importable.
14. Enter `10_supplier_payments_reference.csv` through **Purchases → Supplier Payments** after the relevant bills are posted. It is not directly importable.
15. Import `11_manual_journals.csv` as Manual Journals, review, then post.
16. Create an active NZD bank account linked to account 1010, then import `12_bank_statement.csv` through **Banking → Bank Accounts → Import Statement** as evidence. Do not duplicate transactions already created elsewhere.
17. Import `13_opening_balances.csv` only for balanced staging validation. v0.8 does not post opening-balance batches.
18. Compare reports with `expected_control_totals.md` after completing the applicable workflows.

## File manifest

| File | Purpose | Direct import? | Where to use it |
| --- | --- | --- | --- |
| 00_accounting_entity_import.csv | Create the Accounting Entity | Yes | Import & Export → New Zealand → + Import New Accounting Entity |
| 01_company_setup_reference.csv | Entity/profile reference; optional Legal Name wording | No | Accounting Entities → Arua Demo Trading Ltd → Edit |
| 02_branches_reference.csv | Values for AKL and WLG branches | No | Accounting Entities → Arua Demo Trading Ltd → Manage Branches → Add Branch |
| 02_tax_setup_reference.csv | Generic v0.7 registration, codes, rate, and control-account values | No | Tax → New Zealand → Arua Demo Trading Ltd → Configuration |
| 03_chart_of_accounts.csv | Add 34 accounts, including tax control accounts | Yes | Entity Import & Export → New Import → Chart of Accounts |
| 04_customers.csv | Create 50 customers | Yes | Entity Import & Export → New Import → Customers |
| 05_suppliers.csv | Create 25 suppliers | Yes | Entity Import & Export → New Import → Suppliers |
| 06_products_services.csv | Create 40 products/services after tax setup | Yes | Entity Import & Export → New Import → Products / Services |
| 07_sales_invoices.csv | Create 360 grouped draft invoices | Yes | Entity Import & Export → New Import → Sales Invoices |
| 07_sales_invoice_summary_reference.csv | Invoice control totals | No | Reference only after Sales Invoice import |
| 08_customer_receipts_reference.csv | Values for 240 customer receipts | No | Sales → Customer Receipts |
| 09_supplier_bills.csv | Create 190 grouped draft bills | Yes | Entity Import & Export → New Import → Supplier Bills |
| 09_supplier_bill_summary_reference.csv | Supplier Bill control totals | No | Reference only after Supplier Bill import |
| 10_supplier_payments_reference.csv | Values for 140 supplier payments | No | Purchases → Supplier Payments |
| 11_manual_journals.csv | Create 40 balanced draft journals | Yes | Entity Import & Export → New Import → Manual Journals |
| 12_bank_statement.csv | Import 542 statement-evidence rows | Yes | Banking → Bank Accounts → Import Statement |
| 13_opening_balances.csv | Balanced staging validation only | Yes | Entity Import & Export → New Import → Opening Balances |

## File prerequisites and expected results

| File | Required prerequisites | Expected result | Usage |
| --- | --- | --- | --- |
| 01_company_setup_reference.csv | None | Manually create the correctly scoped NZD company and financial year | Reference only |
| 02_branches_reference.csv | Company exists | Create AKL and WLG branches | Reference only |
| 02_tax_setup_reference.csv | Company and tax control accounts exist | Configure the generic v0.7 registration, codes, and 15%/zero rates | Reference only |
| 03_chart_of_accounts.csv | Company exists with its six system accounts | Add 34 accounts; resulting chart contains 40 accounts | Direct import |
| 04_customers.csv | Company and account 1100 exist | Create 50 active customers | Direct import |
| 05_suppliers.csv | Company and account 2000 exist | Create 25 active suppliers | Direct import |
| 06_products_services.csv | Accounts and generic tax codes exist | Create 24 products and 16 services | Direct import |
| 07_sales_invoices.csv | Customers, items, accounts, branches, tax codes, and open FY exist | Stage 360 draft invoices containing 900 lines | Direct import |
| 07_sales_invoice_summary_reference.csv | Sales invoice import completed | Verify invoice-level net, tax, gross, dates, and line counts | Reference only |
| 08_customer_receipts_reference.csv | Relevant invoices are posted and bank account exists | Enter 240 full/partial allocations through Sales; leave the remainder outstanding | Manual workflow |
| 09_supplier_bills.csv | Suppliers, items, accounts, branches, tax codes, and open FY exist | Stage 190 draft bills containing 381 lines | Direct import |
| 09_supplier_bill_summary_reference.csv | Supplier bill import completed | Verify bill-level net, tax, gross, dates, and line counts | Reference only |
| 10_supplier_payments_reference.csv | Relevant bills are posted and bank account exists | Enter 140 full/partial allocations through Purchases; leave the remainder outstanding | Manual workflow |
| 11_manual_journals.csv | Accounts, branches, and open FY exist | Stage 40 balanced journals containing 80 lines | Direct import |
| 12_bank_statement.csv | NZD bank account linked to account 1010 exists | Import 542 evidence rows; match existing entries and create only the 150 BANK entries | Direct evidence import |
| 13_opening_balances.csv | Accounts and FY exist | Validate one NZD 97,000 debit/credit batch without posting it | Direct staging only |

## Dataset size

- Resulting Chart of Accounts: 40 accounts (6 system-created + 34 imported)
- Customers: 50
- Suppliers: 25
- Products / Services: 40
- Sales invoices: 360 documents / 900 lines
- Customer receipts: 240
- Supplier bills: 190 documents / 381 lines
- Supplier payments: 140
- Manual journals: 40 journals / 80 lines
- Bank statement: 542 evidence rows
- Opening balances: 9 staging rows
- Meaningful data rows, including reference summaries: 2441

All names, addresses, phone numbers, email addresses, references, and monetary activity are synthetic.
