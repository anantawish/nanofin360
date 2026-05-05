# Smart Finance 360 (PHP + MySQL + Bootstrap + jQuery)

ตำแหน่งโปรเจกต์: `C:\xampp\htdocs\nanofinance`

## GitHub Safe Publish (No Credentials / No User Data)

- ใช้ไฟล์ `database/schema.sql` สำหรับ import โครงสร้างฐานข้อมูลเท่านั้น (ไม่มีข้อมูลตัวอย่าง/ไม่มี user rows)
- ถ้าต้องการสร้าง dump ใหม่ ให้รัน `powershell -ExecutionPolicy Bypass -File .\ops_dump_schema_nodata.ps1`
- ตั้งค่า DB ผ่าน environment variables เท่านั้น (`NANFIN_DB_*`) ดูตัวอย่างที่ `.env.example`
- ไฟล์ลับและข้อมูลรันไทม์ถูกกันไว้ใน `.gitignore` แล้ว (เช่น `keys/*.json`, `statment-ocr/keys/*.json`, `nanofin_users.sqlite`, ไฟล์ upload)
- ค่า deploy key ถูกย้ายไป env (`SF360_DEPLOY_KEY`) ไม่มี hardcoded key ในโค้ดแล้ว

ระบบนี้ประกอบด้วย:

- 16 โมดูล แยกหน้าใช้งานเป็น 16 ไฟล์
- ใช้ `head`, `menu`, `footer` ร่วมกันทุกไฟล์
- มี validation ทั้งฝั่ง server และ client ทุกครั้งที่บันทึก
- เก็บ notification log ทุกครั้งที่สร้าง แก้ไข อนุมัติ และลบแบบตรรกะ
- ใช้ soft delete (`is_deleted = 1`) ไม่ลบข้อมูลจริง
- ใช้ latest flag (`is_latest = 1`) และ versioning (`version_no`)
- เก็บผู้ใช้ บทบาท เวลา IP และอุปกรณ์ในทุกเวอร์ชันของ record
- รองรับ maker-checker ผ่าน `record_status` และปุ่มอนุมัติ
- มี event bus / ledger ใน `event_ledger`
- มี audit trail ใน `action_logs`
- ออกแบบแบบ denormalized เพื่อความเร็ว
- มีตารางฐานร่วมสำหรับ master data:
  - ลูกค้า
  - สัญญา
  - สาขา
  - ผลิตภัณฑ์
  - หลักประกัน

## ไฟล์หลัก

- `index.php` แดชบอร์ดผู้บริหาร
- `modules/01_customer_360.php` ถึง `modules/16_executive_bi_api.php`
- `lib/modules.php` กำหนดฟิลด์ของทั้ง 16 ระบบ
- `lib/module_engine.php` จัดการ validation, versioning, audit, event และ soft delete
- `database/schema.sql` โครงสร้างฐานข้อมูลทั้งหมด

## วิธีใช้งาน

1. นำเข้า schema:

```bash
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\nanofinance\database\schema.sql
```

2. เปิด XAMPP แล้วให้ Apache และ MySQL ทำงาน

3. เข้าใช้งาน:

- `http://localhost:888/nanofinance/`

## หมายเหตุ

- ข้อมูลของแต่ละโมดูลถูกเก็บใน `workflow_records` พร้อมคอลัมน์หลักและ payload JSON
- ทุกครั้งที่แก้ไข ระบบจะสร้างเวอร์ชันใหม่และปิด `is_latest` ของเวอร์ชันก่อนหน้า
- การลบจะเป็นการสร้างเวอร์ชันใหม่ที่มีสถานะลบแบบตรรกะ

## UTF-8 Guard (กันตัวหนังสือเพี้ยน)

ตรวจทั้งโค้ดก่อน deploy/อัปเดต:

```bash
C:\xampp\php\php.exe C:\xampp\htdocs\nanofinance\utf8_guard.php
```

ถ้าปกติจะขึ้น `UTF8_CHECK_OK` และถ้าพบไฟล์เสี่ยงเพี้ยนจะขึ้น `UTF8_CHECK_FAIL` พร้อมรายการไฟล์
