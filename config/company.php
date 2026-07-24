<?php

// ข้อมูลผู้ออกเอกสาร (ใบเสร็จรับเงิน / Digital Travel Receipt)
// ค่าจริงตั้งผ่าน .env ได้ เพื่อไม่ผูกข้อมูลผู้ออกเอกสารไว้ในโค้ด
// หมายเหตุ: ออกในนามบุคคลธรรมดา (ยังไม่ได้จดทะเบียนนิติบุคคล)
// จึงเว้น legal_name/tax_id ว่างไว้ — บรรทัดในใบเสร็จจะซ่อนเองเมื่อไม่มีค่า
return [
    'name' => env('COMPANY_NAME', 'ลุยเลเขา'),
    'legal_name' => env('COMPANY_LEGAL_NAME', ''),  // เว้นว่างสำหรับบุคคลธรรมดา
    'tax_id' => env('COMPANY_TAX_ID', ''),          // เลขประจำตัวผู้เสียภาษี 13 หลัก
    'address' => env('COMPANY_ADDRESS', ''),         // ที่อยู่เต็มบรรทัดเดียว
    'phone' => env('COMPANY_PHONE', '062-612-6006'),
    'email' => env('COMPANY_EMAIL', env('MAIL_FROM_ADDRESS', 'hello@luilaykhao.com')),
    'website' => env('COMPANY_WEBSITE', env('APP_URL', 'https://luilaykhao.com')),

    // โลโก้บนหัวเอกสาร — path ใต้ public/ (dompdf อ่านไฟล์ในเครื่องได้)
    'logo_path' => env('COMPANY_LOGO_PATH', 'images/logo.png'),
];
