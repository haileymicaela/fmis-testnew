# CSPC Accounting Reconciliation & Monitoring

Desktop-first accounting reconciliation module based on the CSPC Figma designs. The frontend is connected to the PHP/MySQL backend; Mock API mode remains available for offline demonstrations.

## Run with XAMPP

1. Start Apache and MySQL in the XAMPP Control Panel.
2. Serve this project root with Apache. Either place the project under `C:\xampp\htdocs` or configure an Apache virtual host/alias for the project directory. This workspace copy is outside XAMPP's default document root. Open `frontend/index.html` through that Apache URL.
3. The backend database configuration reads `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, and `DB_PASS`. Defaults are `127.0.0.1:3306`, database `cspc_accounting`, user `root`, and an empty password. Change the environment variables if your local MySQL credentials differ.
4. Ensure XAMPP's PHP has `pdo_mysql` enabled. The current XAMPP MySQL connection and API authentication have been verified.
5. Real API mode is enabled by default in `frontend/js/config.js`. To run an offline demo instead, set `window.CSPC_API_MODE` to `'mock'`.

The login screen uses the demo accountant account in the local database. To check the authenticated API session, request `backend/api/auth/session.php` through Apache.

## Import CSV format

Select the reporting month and source (Bank statement, General ledger, SIAS, or Disbursement voucher) before importing. A CSV must contain these columns:

```csv
ref_no,txn_date,amount
```

`ref_no`, `txn_date`, and `amount` are required. Dates may be ISO `YYYY-MM-DD` or `MM/DD/YYYY`; each transaction date must be in the selected reporting month. Amounts may have up to two decimal places and optional correctly grouped thousands separators (for example `1250.00` or `1,250.00`).

Supported required-column aliases are `reference`, `reference_no`, `reference_number`, `transaction_ref`, or `txn_ref` for `ref_no`; `date`, `transaction_date`, or `value_date` for `txn_date`; and `transaction_amount` or `total_amount` for `amount`. Optional columns are `cross_ref` (`cross_reference`, `bank_reference`, `deposit_reference`, or `check_reference`), `support_ref` (`support_reference` or `source_reference`), `payee` (`beneficiary` or `recipient`), `description` (`details` or `particulars`), and `account_code` (`gl_code`).

References are unique across all source types. Re-uploaded references are reported as duplicates and are not inserted again; invalid rows return row/field validation details. A synthetic 12-row bank CSV is available at [database/samples/october_2026_bank_import_test.csv](./database/samples/october_2026_bank_import_test.csv).

### Database safety

The existing database is already initialized. **Do not import `database/cspc_accounting.sql` over it**: that setup script drops and recreates its tables. Use it only to initialize a new, empty demo database after taking any required backups.

The optional monthly seeder is additive and supports a read-only preview:

```powershell
C:\xampp\php\php.exe database\seed_monthly_2026.php --dry-run
```

Run the same command without `--dry-run` only when intentionally adding missing synthetic monthly records.

## Included pages

- Dashboard with database-backed KPIs, monthly charts, and recent activity
- Financial Data Consolidation
- Transaction Matching
- Discrepancy Review
- Reconciliation Monitoring
- Financial Reports (PDF and XLSX)
- Audit Trail

## Notes

- Chart.js, SheetJS, jsPDF, and the Inter font are loaded from CDNs; internet access is required for those assets.
- Mock data is stored in the browser's `localStorage`; it is separate from the MySQL database.
