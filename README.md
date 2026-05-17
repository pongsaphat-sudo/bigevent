# Bigevent Organizer Website

เว็บออแกไนเซอร์พร้อมหน้าบ้านและหลังบ้าน ใช้ PHP + SQLite/MySQL + Tailwind CSS

## สิ่งที่มีในระบบ

- หน้าบ้าน: หน้าแรก, เกี่ยวกับบริษัท, ผลงาน, บทความ, ติดต่อ
- แสดงแบนเนอร์ Hero, ผลงานบริษัท, โลโก้ลูกค้า, บทความ
- หลังบ้าน: ล็อกอินแอดมิน, จัดการแบนเนอร์, ผลงาน, โลโก้ลูกค้า, บทความ
- อัปโหลดรูปภาพลงโฟลเดอร์ `uploads/`
- ฐานข้อมูล SQLite สำหรับ local หรือ MySQL/MariaDB สำหรับ production

## วิธีรัน

```bash
php -d auto_prepend_file= -S localhost:8000 index.php
```

เปิดเว็บที่ `http://localhost:8000`

## ใช้ MySQL/MariaDB บนเซิร์ฟเวอร์

1. สร้างฐานข้อมูลและ user ใน Plesk
2. คัดลอก `config.example.php` เป็น `config.php`
3. แก้ค่า `db_name`, `db_user`, `db_password` ให้ตรงกับ Plesk
4. เปิดเว็บ 1 ครั้ง ระบบจะสร้างตารางอัตโนมัติ
5. ถ้าต้องย้ายข้อมูลจาก SQLite เดิม ให้นำไฟล์ `storage/mysql-import.sql` ไป import ผ่าน phpMyAdmin หลังตารางถูกสร้างแล้ว

ตัวอย่าง `config.php` จะไม่ถูก commit ขึ้น Git เพื่อไม่ให้รหัสผ่านฐานข้อมูลหลุด

## บัญชีแอดมิน

- URL: `http://localhost:8000/admin/login`
- ระบบจะสร้างบัญชีเริ่มต้นเมื่อยังไม่มีผู้ใช้ในฐานข้อมูล
- หลังนำไปใช้งานจริงให้สร้าง/เปลี่ยนรหัสผ่าน Super Admin ทันที และอย่า commit ฐานข้อมูล production ขึ้น GitHub
