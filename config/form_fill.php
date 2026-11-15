<?php

/*
|--------------------------------------------------------------------------
| ส่งออก data.json ให้สคริปต์กรอกแบบฟอร์มออนไลน์ (Playwright)
|--------------------------------------------------------------------------
|
| หน้าแอดมิน → ตารางที่นั่งว่าง → "เอกสาร" ของรอบ → "ข้อมูลกรอกฟอร์ม (data.json)"
| ได้ ZIP ที่มี data.json (แถวละคน) + รูปบัตรประชาชน id_card_N.* ของแต่ละคน
| ดู App\Services\FormFillExportService
|
| ฟอร์มเปลี่ยนคำถาม = แก้ไฟล์นี้ไฟล์เดียว (โครง JSON ไม่เปลี่ยน สคริปต์ไม่ต้องแก้)
|
|   keyword  ข้อความที่สคริปต์ใช้หาคำถามบนฟอร์ม — ใส่ส่วนที่ไม่ซ้ำกับคำถามอื่น
|   source   ดึงค่าจากข้อมูลผู้เดินทาง: ทุก key ของ PermitFormFields::values()
|            (first_name, last_name, full_name, title, nickname, age, id_card,
|            passport_no, name_en, nationality, blood_group, health_notes,
|            allergies, email, emergency_name, addr_* …) และของไฟล์นี้เอง:
|            gender (เดาจากคำนำหน้า), birth_date_iso (YYYY-MM-DD)
|   value    ค่าคงที่ทุกคน (ใช้แทน source) เช่น "ยอมรับ"
|   map      แปลงค่าให้ตรงตัวเลือกบนฟอร์ม เช่น ['นางสาว' => 'น.ส.']
|
*/

return [

    'text_fields' => [
        ['keyword' => 'ชื่อจริง', 'source' => 'first_name'],
        ['keyword' => 'นามสกุล', 'source' => 'last_name'],
        ['keyword' => 'อายุ', 'source' => 'age'],
        ['keyword' => 'เลขบัตรประชาชน', 'source' => 'id_card'],
    ],

    // ส่งเป็นตัวเลขล้วน 10 หลัก (ตัดขีด/ช่องว่าง) — ช่องเบอร์ของ Google Form มักตรวจรูปแบบ
    'phone_fields' => [
        'applicantPhone' => 'phone',
        'emergencyPhone' => 'emergency_phone',
    ],

    'choice_fields' => [
        ['keyword' => 'คำนำหน้า', 'source' => 'title'],
        ['keyword' => 'เพศ', 'source' => 'gender'],
    ],

    // รูปบัตรประชาชนเอาจากไฟล์ที่ลูกค้าแนบ — ตรงรหัสรายการเอกสาร (requirement key) ก่อน
    // ไม่เจอค่อยหาจากชื่อรายการที่มีคำเหล่านี้ ไม่มีไฟล์ = idCardFilePath เป็น null
    'id_card_documents' => [
        'keys' => ['border-pass-id-front', 'id-card', 'id_card'],
        'label_contains' => ['บัตรประชาชน', 'บัตรประจำตัวประชาชน'],
    ],

];
