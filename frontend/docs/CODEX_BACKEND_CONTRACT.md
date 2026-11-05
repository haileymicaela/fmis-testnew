# Codex implementation contract — PHP/MySQL

Use the user's existing **cspc_accounting.sql** as the schema. Do not replace it with newly imagined columns. Return `Content-Type: application/json` and the common envelope: success `{ "success": true, "data": <payload> }` or failure `{ "success": false, "message": "..." }`. Use `PHP PDO`, prepared SQL statements, `password_verify`, secure HTTP-only sessions, CSRF protection for mutations, consistent UTC/local timestamps, transactions for coupled writes, and upload restrictions. Base path is `backend/api` relative to the frontend.

## Required endpoints

| Path | Method | Request | Response `data` |
|---|---|---|---|
| `/auth/login.php` | POST | `{email,password}` | `{user:{name,email}}` |
| `/auth/logout.php` | POST | none | `{ok:true}` |
| `/auth/session.php` | GET | none | `{user:string|null}` |
| `/dashboard.php` | GET | `period=YYYY-MM` | `{stats:{total,matched,possible,unmatched,duplicate,pending,needsReview,overdue,rate},activities:[...]}` |
| `/records.php` | GET | `period=YYYY-MM` | array of `records` rows |
| `/source-files.php` | GET | `period=YYYY-MM` | array of `source_files` rows |
| `/matches.php` | GET | `period=YYYY-MM` | array: `{id,bank_id,ledger_id,ref_no,bank_ref,ledger_ref,amount,a_ok,r_ok,d_ok,result,confirmed}` |
| `/discrepancies.php` | GET | `period=YYYY-MM` | array of `discrepancies` rows plus `days_open` and `assigned_to` display name |
| `/monitoring.php` | GET | `period=YYYY-MM` | `{stats,discrepancies}` |
| `/reports.php` | GET | `period=YYYY-MM` | array of `reports` rows plus `generated_by` display name |
| `/audit.php` | GET | filters | array of `audit_log` rows |
| `/imports.php` | POST | multipart `source`, `file`, `period` | `{staged:number,duplicates:number,rejected:number,errors:[]}` |
| `/staged-imports.php` | GET | `period=YYYY-MM` | staged file and row counts for confirmation |
| `/consolidate.php` | POST | `{period}` | `{files:number,imported:number,duplicates:number,rejected:number}` |
| `/matching/run.php` | POST | `{period}` | `{processed:number,support_processed:number,stats}` |
| `/matching/confirm.php` | POST | `{id}` | updated match row |
| `/support-matches.php` | GET | `period=YYYY-MM` | ledger-to-SIAS/voucher support comparisons |
| `/matching/confirm-support.php` | POST | `{id}` | confirmed supporting-document match |
| `/discrepancies/resolve.php` | POST | `{id,status,note}` | updated discrepancy row |
| `/reports/generate.php` | POST | `{type,format,period}` | report metadata row |

## Data field mapping

`records` uses SQL `id, source, ref_no, cross_ref, support_ref, txn_date, period, amount, description, rec_status, merge_status`. `matches` contains bank/ledger foreign keys and match result. `support_matches` contains one ledger-to-SIAS/voucher comparison per ledger record, keyed by the ledger `support_ref` and supporting record `ref_no`. `discrepancies` has `issue_type, status, variance, flagged_on, flagged_at, assigned_to, resolution_note` and source references; calculate `days_open` from `flagged_at`. `audit_log` uses `event_id, occurred_at, user_label, action, module, reference, result, note`.

### Critical rules

1. Before implementing, **import the existing SQL to a disposable database**, verify all tables/triggers and count status totals; compare seed records against aggregate widgets. The SQL contains `DROP TABLE` statements: never apply it over valuable existing data.
2. Authenticate through the `users` hash and enforce the configured 5-attempt / 15-minute lockout server-side.
3. Validate CSV/XLSX uploads, header mapping, row dates, amounts, file size, MIME type and duplicates. Stage accepted rows first. Only `/consolidate.php` writes records and source-file provenance transactionally, with an `audit_log` event.
4. Match by cross-reference, exact decimal amount, and dates within ±2 days; produce exact/possible/unmatched/duplicate states. Preserve already-confirmed matches and avoid attaching a record to two confirmed matches. Run as a DB transaction, dedupe repeated runs, and log outcomes.
5. Separately match each ledger `support_ref` to one SIAS or voucher `ref_no`; compare amount and date, record support results in `support_matches`, and flag missing, duplicate, amount-mismatched, or out-of-tolerance references in discrepancies.
5. Resolve/escalate discrepancies only with an explanatory note; update status and any affected reconciliation records consistently; append audit events.
6. Dashboard and monitoring metrics must come from aggregate queries over the DB and be period-aware. Do not hardcode figures from Figma.
7. Regenerate report downloads with the requested period; provide real PDF/XLSX generation, not a CSV named `.xlsx` or plain text named `.pdf`.
8. If the contract conflicts with the provided schema or financial rules, adjust the adapter with minimal, documented migrations rather than silently inventing fields.
9. Test authorization, SQL injection, upload safety, CSRF, pagination, invalid dates, duplicate imports, reruns, manual confirms and discrepancy resolution.
10. For frontend integration, update `js/api.js` real adapter only if necessary, and keep mock mode operational.

## Known limitation

The current frontend exports locally in mock mode. For full real mode, implement server report files/streaming and either wire endpoints through the real adapter or return download URLs. Generated histories are metadata only in the existing SQL.
