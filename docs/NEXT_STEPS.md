# Next Steps

## Recommended Next Step
Create the first real accounts in the browser (/admin/accounts/create) using the new "الرصيد الافتتاحي" section: one account without an opening balance, one with, and one in a second currency. Confirm the OPB transaction number, the "أرصدة افتتاحية" clearing account per currency, the account's current_balance, and that Trial Balance shows متوازن for each currency. Then continue entering real partners, projects, and transactions.

## Pending Items
- Comprehensive Financial Transactions: consider pagination or a row cap if very wide date ranges become slow — the page and exports render all rows.
- Word detail table uses 7pt font with short headers to fit 11 columns on A4 portrait; if accountants find it cramped, consider a landscape section for the detail table only (no existing project pattern for landscape yet).
- Trial Balance Phase 3: opening/closing balance columns, reusing `AccountStatementReportService::calculateOpeningBalance`'s per-account logic (both exports and the on-screen table would need the extra columns).
- Decide what to do with files under storage referenced by the now-purged `attachments` rows — they were intentionally left on disk per the user's instruction ("do not delete storage files yet") and should be reviewed/cleaned up separately once confirmed unneeded.
- Consider moving `storage/app/private/backups/oms_backup_2026-07-06.sql` off the app server to durable/offsite storage, since it's the only copy of the pre-reset data.
- Opening balances: negative opening balances are not supported (form enforces min 0) — if the accounting team ever needs credit-side openings (e.g. liabilities), the account-nature question must be revisited first.
- Deleting an account that has an opening transaction leaves the OPB transaction/lines in place (existing delete behavior untouched, per task constraint) — decide later whether account deletion should be blocked when transaction lines exist.

## Risks To Review
- Comprehensive report: per-currency status can legitimately read "غير متوازن" for periods containing cross-currency transactions (debit leg in one currency, credit leg in another). Expected, but may need a UI/export footnote if accountants find it alarming.
- Comprehensive report excludes lines whose account is soft-deleted (inner join on `accounts` with `deleted_at IS NULL`, per task spec) — same caveat as Trial Balance: raw ledger sums could differ if soft-deleted accounts hold in-range lines.
- Soft-deleted `accounts` that still have in-range `transaction_lines` are silently excluded from the Trial Balance report (since the account list is driven by `Account::query()`, which applies the SoftDeletes scope). This matches the account-driven design but means a raw ledger sum across all lines regardless of account soft-delete status could differ from the report's grand totals — worth a footnote if it ever causes confusion.
- No currency is guaranteed to be flagged `is_base` (the toggle has no uniqueness constraint in `CurrencyForm`) — the Trial Balance page falls back to the first currency by id, which is a reasonable default but worth confirming with the accounting team if multiple currencies could plausibly be marked base.
