<?php

/*
|--------------------------------------------------------------------------
| ส่งออก data.json ให้สคริปต์กรอกแบบฟอร์มออนไลน์ (Playwright)
|--------------------------------------------------------------------------
|
| หน้าแอดมิน → ตารางที่นั่งว่าง → "เอกสาร" ของรอบ → "ข้อมูลกรอกฟอร์ม (data.json)"
| ทุกช่องในไฟล์นี้ขึ้นเป็นช่องติ๊กให้แอดมินเลือกก่อนดาวน์โหลด (`default` = ติ๊กไว้ให้)
| ดู App\Services\FormFillExportService
|
| ฟอร์มเปลี่ยนคำถาม = แก้ไฟล์นี้ไฟล์เดียว (โครง JSON ไม่เปลี่ยน สคริปต์ไม่ต้องแก้)
| key ของแต่ละช่อง (เช่น 'first_name') คือรหัสที่หน้าแอดมินจำว่าติ๊กอะไรไว้ — ห้ามซ้ำกัน
| ทั้งไฟล์ และอย่าเปลี่ยนโดยไม่จำเป็น
|
|   label    ชื่อที่แอดมินเห็นข้างช่องติ๊ก
|   keyword  ข้อความที่สคริปต์ใช้หาคำถามบนฟอร์ม (ดูเฉพาะหัวข้อคำถาม) — ใส่ส่วนที่
|            ไม่ซ้ำกับคำถามอื่น
|   source   ดึงค่าจากข้อมูลผู้เดินทาง: ทุก key ของ PermitFormFields::values()
|            และของ FormFillExportService เอง: gender (เดาจากคำนำหน้า)
|   value    ค่าคงที่ทุกคน (ใช้แทน source) เช่น "ยอมรับ"
|   map      แปลงค่าให้ตรงตัวเลือกบนฟอร์ม เช่น ['นางสาว' => 'น.ส.']
|   exclude  ข้ามคำถามที่หัวข้อมีคำเหล่านี้ — กันกรอกผิดข้อ เช่น "นามสกุล" ต้องไม่ไป
|            ลงช่อง "ชื่อ-นามสกุลผู้ติดต่อฉุกเฉิน" ตอนที่ฟอร์มไม่มีช่องนามสกุลแยก
|
| ช่องข้อความกับตัวเลือกต่างกันแค่สคริปต์ลองแบบไหนก่อน — ถ้าบนฟอร์มเป็นอีกแบบ
| สคริปต์ลองอีกแบบให้เอง
|
*/

return [

    'text_fields' => [
        'first_name' => ['label' => 'ชื่อจริง', 'keyword' => 'ชื่อจริง', 'exclude' => ['ฉุกเฉิน', 'ผู้ติดต่อ'], 'source' => 'first_name', 'default' => true],
        'last_name' => ['label' => 'นามสกุล', 'keyword' => 'นามสกุล', 'exclude' => ['ชื่อ-นามสกุล', 'ชื่อ - นามสกุล', 'ชื่อและนามสกุล', 'ฉุกเฉิน', 'ผู้ติดต่อ', 'อังกฤษ'], 'source' => 'last_name', 'default' => true],
        'age' => ['label' => 'อายุ (ณ วันเดินทาง)', 'keyword' => 'อายุ', 'exclude' => ['หมดอายุ', 'บัตร'], 'source' => 'age', 'default' => true],
        'id_card' => ['label' => 'เลขบัตรประชาชน', 'keyword' => 'เลขบัตรประชาชน', 'source' => 'id_card', 'default' => true],
        'full_name' => ['label' => 'ชื่อ-นามสกุล (ช่องเดียว)', 'keyword' => 'ชื่อ-นามสกุล', 'exclude' => ['ฉุกเฉิน', 'ผู้ติดต่อ', 'อังกฤษ'], 'source' => 'full_name', 'default' => false],
        'nickname' => ['label' => 'ชื่อเล่น', 'keyword' => 'ชื่อเล่น', 'source' => 'nickname', 'default' => false],
        'name_en' => ['label' => 'ชื่อภาษาอังกฤษ', 'keyword' => 'ภาษาอังกฤษ', 'source' => 'name_en', 'default' => false],
        'passport_no' => ['label' => 'เลขหนังสือเดินทาง', 'keyword' => 'หนังสือเดินทาง', 'exclude' => ['หมดอายุ'], 'source' => 'passport_no', 'default' => false],
        'email' => ['label' => 'อีเมล', 'keyword' => 'อีเมล', 'source' => 'email', 'default' => false],
        'health_notes' => ['label' => 'โรคประจำตัว', 'keyword' => 'โรคประจำตัว', 'source' => 'health_notes', 'default' => false],
        'allergies' => ['label' => 'แพ้ยา/อาหาร', 'keyword' => 'แพ้', 'source' => 'allergies', 'default' => false],
        'emergency_name' => ['label' => 'ชื่อผู้ติดต่อฉุกเฉิน', 'keyword' => 'ผู้ติดต่อฉุกเฉิน', 'exclude' => ['เบอร์', 'โทร'], 'source' => 'emergency_name', 'default' => false],
        'addr_full' => ['label' => 'ที่อยู่เต็ม (ลูกค้ากรอกตอนเซ็น)', 'keyword' => 'ที่อยู่', 'exclude' => ['อีเมล', 'email'], 'source' => 'addr_full', 'default' => false],
        'addr_province' => ['label' => 'จังหวัด (ลูกค้ากรอกตอนเซ็น)', 'keyword' => 'จังหวัด', 'source' => 'addr_province', 'default' => false],
    ],

    // ส่งเป็นตัวเลขล้วน (ตัดขีด/ช่องว่าง) — ช่องเบอร์ของ Google Form มักตรวจรูปแบบ
    // สคริปต์หาคำถามเบอร์เอง ("เบอร์โทร/โทรศัพท์/มือถือ" และ "ฉุกเฉิน") จึงไม่มี keyword
    'phone_fields' => [
        'applicant_phone' => ['label' => 'เบอร์โทรผู้สมัคร', 'json_key' => 'applicantPhone', 'source' => 'phone', 'default' => true],
        'emergency_phone' => ['label' => 'เบอร์ผู้ติดต่อฉุกเฉิน', 'json_key' => 'emergencyPhone', 'source' => 'emergency_phone', 'default' => true],
    ],

    'choice_fields' => [
        'title' => ['label' => 'คำนำหน้า', 'keyword' => 'คำนำหน้า', 'exclude' => ['ฉุกเฉิน', 'อังกฤษ'], 'source' => 'title', 'default' => true],
        'gender' => ['label' => 'เพศ (เดาจากคำนำหน้า)', 'keyword' => 'เพศ', 'source' => 'gender', 'default' => true],
        'nationality' => ['label' => 'สัญชาติ', 'keyword' => 'สัญชาติ', 'source' => 'nationality', 'default' => false],
        'blood_group' => ['label' => 'กรุ๊ปเลือด', 'keyword' => 'กรุ๊ปเลือด', 'source' => 'blood_group', 'default' => false],
    ],

    // ช่องพิเศษที่ไม่ได้อยู่ในรายการ keyword
    'extras' => [
        'birth_date' => ['label' => 'วันเกิด', 'default' => true],
        'id_card_file' => ['label' => 'รูปบัตรประชาชน (ไฟล์)', 'default' => true],
        'permit_file' => ['label' => 'แบบฟอร์มอุทยาน (PDF)', 'default' => true],
    ],

    // รูปบัตรประชาชนเอาจากไฟล์ที่ลูกค้าแนบ — ตรงรหัสรายการเอกสาร (requirement key) ก่อน
    // ไม่เจอค่อยหาจากชื่อรายการที่มีคำเหล่านี้ ไม่มีไฟล์ = idCardFilePath เป็น null
    'id_card_documents' => [
        'keys' => ['border-pass-id-front', 'id-card', 'id_card'],
        'label_contains' => ['บัตรประชาชน', 'บัตรประจำตัวประชาชน'],
    ],

];
