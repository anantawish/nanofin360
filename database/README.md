# Database Export Policy

- `schema.sql` is **structure-only** (no sample records, no user rows).
- Safe for public repository use.
- Import with:

```bash
C:\xampp\mysql\bin\mysql.exe -u root nanfinance < C:\xampp\htdocs\EngNano360\database\schema.sql
```
