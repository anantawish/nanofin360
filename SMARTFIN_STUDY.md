# Smartfin Code Study (Source: `C:\xampp\htdocs\snartfin`)

Date: 2026-05-03  
Workspace: `C:\xampp\htdocs\smartfin`

## 1) Scope

Study source code from `C:\xampp\htdocs\snartfin` and prepare a practical baseline for continued development in the new `smartfin` project.

## 2) System Summary

- Stack: PHP 8+, MySQL, Bootstrap, jQuery
- Architecture style: metadata-driven modules
- Core flow:
  - `bootstrap.php`: config/session/db/security bootstrap
  - `lib/modules.php`: module metadata (fields, types, options)
  - `lib/module_engine.php`: shared CRUD, versioning, maker-checker, soft delete, audit/event
  - `modules/*.php`: module pages that use shared engine
  - `database/schema.sql` (in `nanofinance`): main database structure

## 3) Functional Coverage

The system currently defines 20 modules:

1. `customer_360`
2. `credit_scoring`
3. `hire_purchase`
4. `installments`
5. `affordability_dti`
6. `collections_workflow`
7. `portfolio_branch`
8. `delinquency_dpd`
9. `npl_recovery`
10. `credit_policy`
11. `early_warning`
12. `pricing_limit`
13. `legal_enforcement`
14. `accounting_gl`
15. `compliance_audit`
16. `risk_lab`
17. `scenario_stress`
18. `executive_bi_api`
19. `local_economy_lei`
20. `loan_payment_history`

## 4) Data Model Highlights

Key tables in `database/schema.sql`:

- `workflow_records` (versioned records, `is_latest`, `is_deleted`, `record_status`)
- `action_logs` (before/after snapshot logging)
- `event_ledger` (event stream)
- `notification_logs`
- `system_users`
- Master tables (`master_customer`, `master_contract`, `master_branch`, `master_product`, etc.)
- OCR and attitude assessment tables

## 5) Source Comparison (`snartfin` vs `nanofinance`)

Hash-based comparison of core files:

- Shared core files are identical (`diff_count=0`).
- `nanofinance` contains 9 additional files under `database/`, including `schema.sql` and seed scripts.
- Baseline decision (user instruction): use `smartfin` as the baseline code line.

## 6) Risks Identified Before New Feature Work

1. Security risk: `config.php` contains production credential fallback values.
2. Secret management risk: service account key files are present in project directories.
3. Encoding risk: Thai mojibake appears in multiple files and UI strings.
4. Maintainability risk: no automated test suite; large shared logic in `lib/module_engine.php`.
5. Repo hygiene risk: many backup/tmp artifacts in runtime tree.

## 7) Recommended Development Order

1. Baseline hardening
   - Move DB secrets to env-only configuration.
   - Relocate keys outside web root.
   - Freeze a clean baseline copy.
2. Encoding stabilization
   - Normalize UTF-8 in request/output and static labels.
   - Remove obsolete backup/fix artifacts from runtime paths.
3. Delivery foundation
   - Add minimum smoke tests (login, dashboard, 2-3 critical modules).
   - Add pre-deploy checks for config and permissions.
4. Feature implementation
   - Start new `smartfin` features after baseline is stable and repeatable.

## 8) Immediate Conclusion

Continue development on `C:\xampp\htdocs\smartfin` as the active baseline and use `C:\xampp\htdocs\snartfin` as the source reference when needed.
