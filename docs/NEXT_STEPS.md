# Next Steps

## Recommended Next Step
Have a real user exercise the "تصدير Excel"/"تصدير Word" buttons on "ميزان المراجعة" (/admin/trial-balance) against production-like data, then decide whether Phase 3 (opening/closing balance columns) is needed next.

## Pending Items
- Phase 3: opening/closing balance columns, reusing `AccountStatementReportService::calculateOpeningBalance`'s per-account logic (both exports and the on-screen table would need the extra columns).

## Risks To Review
- Soft-deleted `accounts` that still have in-range `transaction_lines` are silently excluded from the report (since the account list is driven by `Account::query()`, which applies the SoftDeletes scope). This matches the account-driven design but means a raw ledger sum across all lines regardless of account soft-delete status could differ from the report's grand totals — worth a footnote if it ever causes confusion.
- No currency is guaranteed to be flagged `is_base` (the toggle has no uniqueness constraint in `CurrencyForm`) — the page falls back to the first currency by id, which is a reasonable default but worth confirming with the accounting team if multiple currencies could plausibly be marked base.

