# NanoFin360
Efficient Financial and Business Management System (PHP + MySQL) for multi-branch operations.

## Overview
NanoFin360 is a modular finance/leasing platform built with pragmatic PHP + MySQL architecture.
It is designed for branch operations, maker-checker workflows, and operational reporting.

## Public Edition and Commercial Services
This public repository is the free/open demonstration layer. It is intended to
help prospects inspect the product, install a clean baseline, and understand the
implementation approach before requesting paid customization.

Commercial work should be sold around:
- installation and production deployment
- data migration and branch rollout
- custom finance/leasing workflows
- maintenance, backup, and monitoring
- RAG AI assistant for policy, portfolio, collection, and management questions

See:
- `docs/PUBLIC_EDITION.md`
- `docs/SERVICE_OFFER.md`
- `docs/RAG_AI_BLUEPRINT.md`
- `ai/README.md`

## Key Modules
- Customer 360 + KYC
- Credit Scoring
- Affordability / DTI
- Hire Purchase
- Installments
- Collections, NPL, Legal, Portfolio, Compliance, BI, LEI

## Tech Stack
- PHP
- MySQL
- Bootstrap + jQuery

## Prerequisites
- PHP 8.1+ (XAMPP is supported)
- MySQL 8+ or MariaDB compatible version
- Apache/Nginx web server

## Installation (Clean Setup)
1. Clone repository into your web root.
2. Create database (example: `nanfinance`).
3. Import schema only (no user data included):
   - `database/schema.sql`
4. Configure environment variables (recommended):
   - `NANFIN_DB_HOST`
   - `NANFIN_DB_PORT`
   - `NANFIN_DB_NAME`
   - `NANFIN_DB_USER`
   - `NANFIN_DB_PASS`
   - `SF360_DEPLOY_KEY` (optional, for `_deploy/deploy_sync.php`)
5. Start Apache + MySQL and open the app.

## Local URL (XAMPP example)
- `http://localhost:888/EngNano360/`

## Security Notes
- This repository is sanitized for public sharing.
- Real credential files are ignored via `.gitignore`.
- No production database dump is included.
- Only schema is shipped (`database/schema.sql`).

Ignored sensitive paths include:
- `keys/*.json`
- `statment-ocr/keys/*.json`
- `*.sqlite`
- backup/deploy artifacts and temporary files

## Utility Scripts
- Schema-only export helper: `ops_dump_schema_nodata.ps1`
- UTF-8 safety check: `utf8_guard.php`

## Contribution and Publishing Rules
Before pushing to a public repo:
1. Run UTF-8 and secret checks.
2. Confirm no customer/user dump is staged.
3. Keep credentials in environment variables only.

## License
Custom Proprietary License (see `LICENSE`).
Internal use and modification are allowed.
Commercial resale/sub-licensing is prohibited.
