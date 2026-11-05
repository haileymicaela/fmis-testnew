# Consolidation and matching sample data

These four CSV files use October 2026 transaction dates. Import each file from **Financial Data Consolidation**, selecting the matching source for each file. Select **October 2026** first. Then open **Transaction Matching** for October and click **Run Matching**.

Expected bank-to-ledger results after matching:

- `MATCH-001`: matched; the dates are one day apart and the references and amounts agree.
- `MATCH-002`: possible; the dates are four days apart while the references and amounts agree. This should also create a `date_gap` item in Discrepancy Review.
- `MATCH-003`: unmatched; the references agree but the amounts differ.
- `DUP-004`: duplicate; two bank records share the same cross-reference as one ledger record.
- `BANK-ONLY-006` and `LEDGER-ONLY-005`: unmatched because they have no counterpart.

Expected supporting-document checks after matching:

- `LEDGER-TEST-001` links to `SIAS-TEST-001`: matched.
- `LEDGER-TEST-002` links to `VOUCHER-TEST-001`: possible because their dates are four days apart.
- `LEDGER-TEST-003` links to `SIAS-TEST-002`: unmatched because the amounts differ.
- `LEDGER-TEST-004` links to `VOUCHER-TEST-002`: matched as a supporting document; the duplicate flag is on the bank-to-ledger side.
- `LEDGER-TEST-005` references missing `SIAS-TEST-003`: unmatched for missing support.

SIAS and voucher records remain visible in Financial Data Consolidation. Their support-match outcomes are shown separately from bank-to-ledger matches.

Import each file once. Reimporting it will be treated as duplicate references and skipped.
