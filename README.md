# NanoFin360
Efficient Financial and Business Management System (PHP + MySQL) for multi-branch operations.

## Project Summary
NanoFin360 is a streamlined finance and leasing platform built with a simple OOP structure.
It focuses on practical workflows, maintainability, and branch-level operations.

## Tech Stack
- PHP
- MySQL
- Bootstrap + jQuery

## Quick Start
1. Clone this repository into your web root.
2. Import database schema only:
   - `database/schema.sql`
3. Configure environment variables using:
   - `.env.example`
4. Start Apache and MySQL, then open the application in your browser.

## Security and Safe Publishing Notes
- Database credentials are read from environment variables (`NANFIN_DB_*`).
- Deploy key is read from environment variable (`SF360_DEPLOY_KEY`).
- No real user dump is included in this repository.
- Runtime and local sensitive files are ignored by `.gitignore`, including:
  - `keys/*.json`
  - `statment-ocr/keys/*.json`
  - `nanofin_users.sqlite`
  - backup and deploy artifacts

## Useful Scripts
- Schema-only dump helper: `ops_dump_schema_nodata.ps1`
- UTF-8 safety check: `utf8_guard.php`

## License and Terms
Custom Proprietary License.
Use within one organization (up to 150 concurrent users), modification allowed for internal use, and commercial resale/sub-licensing is prohibited.