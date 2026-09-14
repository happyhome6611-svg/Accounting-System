# Synthetic Import and Export Fixtures

These fixtures contain fictional data for automated and manual testing of the Arua v0.8 Import & Export framework. They are independent of the repository-root `Sample_Data/` directory.

## Fixture assumptions

- Transaction dates use the test financial year ending 31 December 2027.
- `MAIN` represents an active branch belonging to the selected Company or Sole Trader.
- Accounts `1000`, `2000`, `3000`, `4000`, and `5000` are expected from the standard seeded chart.
- Transaction fixtures expect `CUST001`, `SUP001`, `SERV001`, and `PROD001` to exist in the selected entity.
- Tax-enabled fixtures expect active `STANDARD` (10%) and `ZERO` (zero-rated) tax codes for the transaction date.
- The supplier adapter does not expose an AP-account mapping field. The invalid supplier fixture therefore tests the validation rules that the adapter actually supports; AP account ownership remains enforced when the service assigns the entity default.
- “Duplicate” means either an existing entity record/reference or a repeated normalized row/document within the same import.

## Manifest

| Filename | Data type | Intended entity | Headers | Expected result | Purpose |
| --- | --- | --- | --- | --- | --- |
| `customers-valid.csv` | Customers | Company, Sole Trader, Individual | Customer Code; Customer Name; Legal Name; Email; Phone; Billing Address; Payment Terms Days; Credit Limit; Active | 3 valid rows, 0 invalid, 0 duplicates, 0 warnings | Deterministic CUST001–CUST003 import |
| `customers-invalid-duplicates.csv` | Customers | Company, Sole Trader, Individual | Customer Code; Customer Name; Email; Extra Source Note | With CUST001 pre-existing: 0 valid rows, 4 non-importable rows/documents; CUST001 and repeated CUST005 are duplicate signals; missing name and malformed email are errors; extra column is intentionally unmapped | Required fields, email validation, existing/same-file duplicates, ignored source column |
| `suppliers-valid.csv` | Suppliers | Company, Sole Trader | Supplier Code; Supplier Name; Legal Name; Email; Phone; Address; Payment Terms Days; Credit Limit; Active | 3 valid rows, 0 invalid, 0 duplicates, 0 warnings | Deterministic supplier import |
| `suppliers-invalid.csv` | Suppliers | Company, Sole Trader | Supplier Code; Supplier Name; Email | With SUP001 pre-existing: 0 valid rows, 4 non-importable rows; SUP001 and repeated SUP005 are duplicate signals; missing name and malformed email are errors | Supplier validation and duplicate detection |
| `products-valid.csv` | Products / Services | Company, Sole Trader | Code / SKU; Name; Description; Type; Unit; Sales Price; Purchase Price; Sales Account; Purchase Account; Default Sales Tax Code; Default Purchase Tax Code; Active | 3 valid rows, 0 invalid, 0 duplicates; tax-code rows require the fixture tax setup | Product/service fields, account lookup, optional tax codes |
| `products-invalid.csv` | Products / Services | Company, Sole Trader | Code / SKU; Name; Type; Sales Price; Purchase Price; Sales Account; Purchase Account; Default Sales Tax Code; Default Purchase Tax Code | With SERV001 pre-existing: 0 valid rows, 5 non-importable rows; duplicate, missing name, unsupported type, negative price, and unknown tax codes | Product validation failures |
| `chart-of-accounts-valid.csv` | Chart of Accounts | Company, Sole Trader, Individual | Account Code; Account Name; Account Type; Normal Balance; Parent Account Code; Active | 5 valid rows, 0 invalid, 0 duplicates, 0 warnings | Asset, liability, equity, income, and expense classifications |
| `chart-of-accounts-invalid.csv` | Chart of Accounts | Company, Sole Trader, Individual | Account Code; Account Name; Account Type; Normal Balance | With 1010 pre-existing: 0 valid rows, 4 non-importable rows; existing/repeated code signals plus missing name and unsupported `revenue` classification | Account validation and duplicates |
| `sales-invoices-valid.csv` | Sales Invoices | Company, Sole Trader | Invoice Number / Source ID; Customer Code; Invoice Date; Due Date; Branch Code; Product / Service Code; Revenue Account; Description; Quantity; Unit Price; Discount Amount; Tax Code; Tax Inclusive; Source Tax Amount | 1 valid two-line document, 0 invalid; net 150.00, tax 10.00, gross 160.00 | Grouping, tax resolution, source-tax comparison, atomic import |
| `sales-invoices-invalid.csv` | Sales Invoices | Company, Sole Trader | Same as valid sales invoice | 0 importable documents; errors cover unknown customer/item/account/tax, duplicate reference, malformed date, and one invalid row in INV-ATOMIC | Document-level atomicity and validation |
| `supplier-bills-valid.csv` | Supplier Bills | Company, Sole Trader | Bill Number / Source ID; Supplier Code; Bill Date; Due Date; Branch Code; Product / Service Code; Expense / Asset Account; Description; Quantity; Unit Price; Discount Amount; Tax Code; Tax Inclusive; Source Tax Amount | 1 valid two-line document, 0 invalid; net 250.00, tax 20.00, gross 270.00 | Grouping, tax resolution, and atomic supplier bill import |
| `supplier-bills-invalid.csv` | Supplier Bills | Company, Sole Trader | Same as valid supplier bill | 0 importable documents; errors cover unknown supplier/account/tax, malformed date, and one invalid row in BILL-ATOMIC | Bill validation and atomicity |
| `manual-journals-valid.csv` | Manual Journals | Company, Sole Trader | Journal Reference; Date; Account Code; Debit; Credit; Description; Branch Code | 1 valid balanced journal with debit 1,000.00 and credit 1,000.00 | Fixed-scale balanced grouping |
| `manual-journals-invalid.csv` | Manual Journals | Company, Sole Trader | Journal Reference; Date; Account Code; Debit; Credit; Description; Branch Code | 0 valid documents, 1 invalid unbalanced document; debit 1,000.00 and credit 900.00 | Unbalanced-journal rejection with no partial journal |
| `branch-company-valid.csv` | Sales Invoices | Company, Sole Trader | Invoice Number / Source ID; Customer Code; Invoice Date; Due Date; Branch Code; Revenue Account; Description; Quantity; Unit Price | 1 valid document when MAIN belongs to the selected entity | Branch mapping |
| `branch-invalid.csv` | Sales Invoices | Company, Sole Trader | Same as branch-company-valid | 0 imported documents; `OTHER-ENTITY` must fail service-level branch ownership checks | Invalid/cross-entity branch rejection |
| `individual-customers-valid.csv` | Customers | Individual | Customer Code; Customer Name; Email; Phone; Active | 1 valid row, 0 invalid, no branch column | Branchless Individual import |
| `customers-alternate-headers.csv` | Customers | Company, Sole Trader, Individual | CustCode; CustomerName; Telephone; EmailAddress | 1 valid row after reviewing mapping suggestions | Conservative automatic mapping and reusable profile |
| `xlsx-multi-sheet.xlsx` | Customers, Suppliers, Products | Company or Sole Trader | Each worksheet uses the corresponding valid adapter headers | Three worksheets; user must explicitly select one; Customers has 3 rows, Suppliers 3, Products 3 | XLSX parsing and worksheet selection |
| `empty.csv` | Edge case | Any | None | File rejected as empty | Empty-file handling |
| `headers-only.csv` | Customers | Any | Customer Code; Customer Name; Email | 0 data rows; rejected as having no import rows | Headers-only handling |
| `unexpected-extra-columns.csv` | Customers | Any | Customer Code; Customer Name; Unmapped Legacy Value | 1 valid row when extra column is ignored | Explicit unmapped-column behavior |
| `missing-required-columns.csv` | Customers | Any | Email; Phone | Mapping cannot satisfy Customer Code and Customer Name requirements | Missing required mappings |
| `duplicate-headers.csv` | Customers | Any | Customer Code; Customer Name; Customer Name | File rejected before mapping | Duplicate-header rejection |
| `blank-rows.csv` | Customers | Any | Customer Code; Customer Name | 2 valid rows; blank physical row is ignored | Blank-row handling and source row numbering |
| `repeated-customers.csv` | Customers | Any | Customer Code; Customer Name | 1 importable row and 1 exact same-file duplicate | Deterministic row fingerprinting |
| `formula-injection-text.csv` | Customers | Any | Customer Code; Customer Name; Legal Name; Billing Address | 1 valid row; `=SUM(1,1)`, `+TEST`, and `@VALUE` remain literal untrusted text | Formula-style input must never execute |

## Financial expectations

- `sales-invoices-valid.csv`: one invoice, two lines, net 150.00, tax 10.00, gross 160.00. If posted, debit Accounts Receivable 160.00, credit Revenue 150.00, credit Output Tax 10.00.
- `supplier-bills-valid.csv`: one bill, two lines, net 250.00, tax 20.00, gross 270.00. If posted, debit Expense 250.00, debit Input Tax 20.00, credit Accounts Payable 270.00.
- `manual-journals-valid.csv`: one balanced journal, debit Bank 1,000.00 and credit Equity 1,000.00.
- `manual-journals-invalid.csv`: rejected as a complete group; no journal or journal lines may be created.

All amounts are synthetic and use fixed decimal source values. Arua’s database precision and existing posting services remain authoritative.
