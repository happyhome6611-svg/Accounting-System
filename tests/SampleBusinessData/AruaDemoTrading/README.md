# Arua Demo Trading Ltd

Realistic fictional sample business data for manual testing of Arua Accounting System v0.8.

## Manual setup

1. Create **Arua Demo Trading Ltd** as a Company in New Zealand with NZD, Pacific/Auckland, and financial year 1 April 2025 to 31 March 2026.
2. The application creates branch `HO` and system accounts 1000, 1100, 2000, 3000, 4000, and 5000. Create active branches `AKL` (Auckland) and `WLG` (Wellington).
3. Configure the generic v0.7 tax registration using `02_tax_setup_reference.csv`: STANDARD 15%, ZERO zero-rated, output account 2100, input account 1200. This is generic test configuration, not NZ GST-return logic.
4. Create an active NZD bank account linked to ledger account 1010 before importing the bank statement.

## Import order

1. Import `03_chart_of_accounts.csv` as Chart of Accounts.
2. Import `04_customers.csv` as Customers.
3. Import `05_suppliers.csv` as Suppliers.
4. Import `06_products_services.csv` as Products / Services.
5. Import `07_sales_invoices.csv` as Sales Invoices. Use Draft first; review and post through Arua.
6. Import `09_supplier_bills.csv` as Supplier Bills. Use Draft first; review and post through Arua.
7. Enter `08_customer_receipts_reference.csv` through the existing Customer Receipt workflow; direct CSV receipt import is not available in v0.8.
8. Enter `10_supplier_payments_reference.csv` through the existing Supplier Payment workflow; direct CSV payment import is not available in v0.8.
9. Import `11_manual_journals.csv` as Manual Journals, review, then post.
10. Import `12_bank_statement.csv` through Banking as evidence only. Match receipt/payment/journal rows; use the existing create-from-statement workflow for BANK rows. Do not create duplicate accounting.
11. Import `13_opening_balances.csv` only to validate balanced staging. v0.8 intentionally does not post opening balances.
12. Compare reports with `expected_control_totals.md` after completing the applicable manual workflows.

## File manifest

| File | Rows | Directly importable | Importer/workflow | Purpose |
| --- | ---: | --- | --- | --- |
| 01_company_setup_reference.csv | 8 | No | Reference/manual workflow | Manual entity setup reference |
| 02_branches_reference.csv | 2 | No | Reference/manual workflow | Manual branch setup reference |
| 02_tax_setup_reference.csv | 12 | No | Reference/manual workflow | Generic v0.7 tax setup reference; not an NZ return configuration |
| 03_chart_of_accounts.csv | 34 | Yes | chart_of_accounts | Adds 34 accounts to the six system accounts created with the entity |
| 04_customers.csv | 50 | Yes | customers | Fifty fictional customers |
| 05_suppliers.csv | 25 | Yes | suppliers | Twenty-five fictional suppliers |
| 06_products_services.csv | 40 | Yes | products | Twenty-four products and sixteen services |
| 07_sales_invoices.csv | 900 | Yes | sales_invoices | Three hundred sixty grouped sales invoices |
| 07_sales_invoice_summary_reference.csv | 360 | No | Reference/manual workflow | Reference totals for validating imported grouped invoices |
| 08_customer_receipts_reference.csv | 240 | No | Reference/manual workflow | REFERENCE DATA – CURRENTLY NOT DIRECTLY IMPORTABLE; enter through Sales receipts workflow |
| 09_supplier_bills.csv | 381 | Yes | supplier_bills | One hundred ninety grouped supplier bills |
| 09_supplier_bill_summary_reference.csv | 190 | No | Reference/manual workflow | Reference totals for validating imported grouped bills |
| 10_supplier_payments_reference.csv | 140 | No | Reference/manual workflow | REFERENCE DATA – CURRENTLY NOT DIRECTLY IMPORTABLE; enter through Purchases payments workflow |
| 11_manual_journals.csv | 80 | Yes | manual_journals | Forty balanced adjusting journals |
| 12_bank_statement.csv | 542 | Yes | existing v0.5 bank statement importer | Evidence-only statement; import creates no journals |
| 13_opening_balances.csv | 9 | Yes | opening_balances | Balanced staging only; v0.8 cannot post this batch |

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
