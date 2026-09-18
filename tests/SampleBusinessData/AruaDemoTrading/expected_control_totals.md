# Expected Control Totals

All amounts are NZD. These controls describe the complete operational dataset after directly importable documents are posted and reference-only receipts, payments, and direct bank entries are entered through their existing Arua workflows. Bank statement rows are evidence and must not be posted twice.

Opening balances are excluded from the operational totals because v0.8 supports balanced staging but not posting. The opening batch separately balances at NZD 97,000.00 debit and credit.

| Control | Expected NZD |
| --- | ---: |
| Total Sales Net | 369458.83 |
| Total Sales Tax | 51689.85 |
| Total Sales Gross | 421148.68 |
| Total Purchase Net | 56425.83 |
| Total Purchase Tax | 7479.56 |
| Total Purchase Gross | 63905.39 |
| Total Customer Receipts | 244651.09 |
| Outstanding AR | 176497.59 |
| Total Supplier Payments | 39764.08 |
| Outstanding AP | 24141.31 |
| Total Bank Movement | 209754.01 |
| Total Manual Journal Debits | 25330.00 |
| Total Manual Journal Credits | 25330.00 |
| Expected Trial Balance Debits | 471516.49 |
| Expected Trial Balance Credits | 471516.49 |
| Expected P&L Revenue | 370580.33 |
| Expected P&L Expenses | 69690.33 |
| Expected Profit | 300890.00 |
| Expected Balance Sheet Assets | 397451.16 |
| Expected Balance Sheet Liabilities | 81561.16 |
| Expected Balance Sheet Equity Before Profit | 15000.00 |

- Direct bank income included in P&L: NZD 1121.50
- Direct bank expenses included in P&L: NZD 11029.50
- Opening balance staging debit: NZD 97000.00
- Opening balance staging credit: NZD 97000.00
- Balance Sheet equation verified: Assets = Liabilities + Equity + current-year Profit.
