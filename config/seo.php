<?php

/*
|--------------------------------------------------------------------------
| Server-rendered page meta
|--------------------------------------------------------------------------
|
| The SPA sets its own title/OG tags through @unhead once Vue has booted, which
| covers Google (it renders JS) but not the crawlers that decide what a shared
| link looks like — Facebook, LINE, Twitter and every chat app read the raw HTML
| and never run a line of script. Those tags therefore have to exist in the
| response body, which means PHP needs to know the same page meta the router
| knows.
|
| Trip and place pages are resolved from the database (see App\Support\SeoMeta);
| the static pages below are the ones whose copy lives only in the router, so
| they are mirrored here. SeoPageMetaSyncTest fails if the two drift apart —
| when it does, copy the router's value into this file.
|
*/

return [

    // Fallback for any path not listed below (and for the SPA's private pages).
    'default' => [
        'title' => 'แพลตฟอร์มจองและจัดทริปเที่ยว ทั้งในไทยและต่างประเทศ เดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว',
        'description' => 'ลุยเลเขา แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทยและต่างประเทศ บริการเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว ตอบโจทย์คนรักธรรมชาติ จองง่าย ปลอดภัย',
        'robots' => 'index, follow, max-image-preview:large, max-snippet:-1',
        'type' => 'website',
    ],

    // Appended to every page title, matching the SPA's titleTemplate.
    'title_suffix' => ' | ลุยเลเขา Luilaykhao',

    // Suffix for og:title / twitter:title, which the SPA keeps shorter.
    'og_title_suffix' => ' | ลุยเลเขา',

    'site_name' => 'ลุยเลเขา Luilaykhao',

    // Shown when a page has no image of its own.
    'fallback_image' => 'images/logo.png?v=2',
    'fallback_image_alt' => 'ลุยเลเขา Luilaykhao - แพลตฟอร์มจองทริปท่องเที่ยวในไทยและต่างประเทศ',

    /*
     * Anything under these prefixes is a signed-in customer's own page. They are
     * unreachable without a token, but a crawler that follows a pasted link
     * should be told not to index the URL rather than left to guess.
     */
    'noindex_prefixes' => [
        '/booking/',
        '/payment/',
        '/confirmation/',
        '/installment-payment/',
        '/chat/',
        '/recap/',
        '/group/',
        '/join/',
    ],

    'pages' => [
        '/' => [
            'title' => 'แพลตฟอร์มจองและจัดทริปเที่ยว ทั้งในไทยและต่างประเทศ เดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว',
            'description' => 'ลุยเลเขา แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทยและต่างประเทศ บริการเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว ตอบโจทย์คนรักธรรมชาติ จองง่าย ปลอดภัย ใบอนุญาตนำเที่ยว :licence',
        ],
        '/trips' => [
            'title' => 'ค้นหาทริปทั้งหมด | เดินป่า ดำน้ำตื้น เช่ารถตู้',
            'description' => 'รวมทริปท่องเที่ยวทั่วประเทศไทยและต่างประเทศ ทริปเดินป่าภูกระดึง ภูสอยดาว เขาช้างเผือก ทริปเทรกกิ้งต่างประเทศ ทริปดำน้ำตื้นดูปะการัง และบริการเช่ารถตู้นำเที่ยว VIP พร้อมคนขับ จองออนไลน์ได้เลย',
        ],
        '/find' => [
            'title' => 'ค้นหาทริปที่ใช่ | ตอบไม่กี่ข้อ เจอทริปที่ชอบ',
            'description' => 'ตอบคำถามสั้นๆ เรื่องประเภทกิจกรรม ระดับความท้าทาย และจำนวนวัน แล้วให้เราแนะนำทริปที่ใช่สำหรับคุณ',
        ],
        '/assistant' => [
            'title' => 'ถามหาทริปที่ใช่ | ผู้ช่วยวางทริปลุยเลเขา',
            'description' => 'บอกงบ จำนวนวัน และระดับที่ไหว แล้วให้ผู้ช่วยหาทริปที่เปิดจองอยู่จริงมาให้',
        ],
        '/explore' => [
            'title' => 'สำรวจทริปบนแผนที่ | ดูว่าทริปไหนอยู่ตรงไหนของไทย',
            'description' => 'เลือกทริปจากตำแหน่งจริงบนแผนที่ประเทศไทย กรองตามภูมิภาค ระดับความยาก และเดือนที่อยากไป',
        ],
        '/login' => [
            'title' => 'เข้าสู่ระบบ',
            'description' => 'เข้าสู่ระบบลุยเลเขา เพื่อจองทริปเดินป่า ดำน้ำตื้น และเช่ารถตู้นำเที่ยว',
            'robots' => 'noindex, follow',
        ],
        '/register' => [
            'title' => 'สมัครสมาชิก',
            'description' => 'สมัครสมาชิกลุยเลเขา เพื่อรับสิทธิพิเศษและจองทริปได้ง่ายขึ้น',
            'robots' => 'noindex, follow',
        ],
        '/forgot-password' => [
            'title' => 'ลืมรหัสผ่าน',
            'description' => 'ขอลิงก์ตั้งรหัสผ่านใหม่สำหรับบัญชีลุยเลเขา',
            'robots' => 'noindex, follow',
        ],
        '/reset-password' => [
            'title' => 'ตั้งรหัสผ่านใหม่',
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/my-bookings' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/my-reviews' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/my-staff-trips' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/loyalty' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/referral' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/notifications' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/about' => [
            'title' => 'เกี่ยวกับเรา',
            'description' => 'ทำความรู้จักกับลุยเลเขา ทีมงานผู้อยู่เบื้องหลังการจัดทริปเที่ยวทั่วไทยและต่างประเทศ เดินป่า ดำน้ำตื้น เช่ารถตู้ ที่ใส่ใจในทุกรายละเอียด ใบอนุญาตนำเที่ยว :licence นำเที่ยวได้ทั้งในและต่างประเทศ',
        ],
        '/goal' => [
            'title' => 'จุดมุ่งหมายของเรา',
            'description' => 'เป้าหมายของลุยเลเขาคือการทำให้ทุกคนออกไปเที่ยวธรรมชาติได้ง่ายขึ้น ปลอดภัย และมีความสุขในทุกการเดินทาง',
        ],
        '/problem' => [
            'title' => 'แจ้งปัญหาการใช้งาน',
            'description' => 'แจ้งปัญหาเกี่ยวกับการจองทริป การชำระเงิน หรือการใช้งานเว็บไซต์ลุยเลเขา ทีมงานพร้อมช่วยเหลือ',
            'robots' => 'noindex, follow',
        ],
        '/privacy' => [
            'title' => 'นโยบายความเป็นส่วนตัว',
            'description' => 'นโยบายความเป็นส่วนตัวของลุยเลเขา เราให้ความสำคัญกับการปกป้องข้อมูลส่วนบุคคลของผู้ใช้งานทุกท่าน',
        ],
        '/terms' => [
            'title' => 'เงื่อนไขการให้บริการ',
            'description' => 'เงื่อนไขการให้บริการของลุยเลเขา ข้อกำหนดการจองทริป การยกเลิก การคืนเงิน และข้อตกลงในการใช้บริการ',
        ],
        '/reviews' => [
            'title' => 'รีวิวจากนักเดินทาง',
            'description' => 'อ่านรีวิวจากนักเดินทางที่เคยไปทริปกับลุยเลเขา ประสบการณ์จริงจากผู้ใช้งาน ทริปเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว',
        ],
        '/gallery' => [
            'title' => 'รูปจากคนที่ไปมาแล้ว | ลุยเลเขา',
            'description' => 'รูปที่ผู้ร่วมทริปถ่ายเองและแนบมากับรีวิว ทั้งเดินป่า ดำน้ำตื้น และทริปธรรมชาติทั่วไทย ไม่ผ่านการคัดของทีมงาน',
        ],
        '/how-to-book' => [
            'title' => 'วิธีการจองทริป',
            'description' => 'คู่มือวิธีการจองทริปกับลุยเลเขา ขั้นตอนง่ายๆ เลือกทริป เลือกวัน จ่ายเงิน รอรับการยืนยัน พร้อมชำระผ่าน PromptPay',
            'type' => 'article',
        ],
        '/faq' => [
            'title' => 'คำถามที่พบบ่อย (FAQ)',
            'description' => 'รวมคำถามที่พบบ่อยเกี่ยวกับการจองทริปลุยเลเขา การยกเลิก การคืนเงิน สิ่งที่ต้องเตรียม ความปลอดภัย และอื่นๆ',
            'type' => 'article',
        ],
        '/contact' => [
            'title' => 'ติดต่อเรา',
            'description' => 'ติดต่อลุยเลเขา สอบถามเรื่องจองทริปเดินป่า ดำน้ำตื้น เช่ารถตู้ โทร 062-612-6006 LINE @luilaykhao อีเมล luilaykhao.info@gmail.com',
        ],
        '/places' => [
            'title' => 'สถานที่ธรรมชาติในไทย | ภูเขา เกาะ อุทยาน',
            'description' => 'ข้อมูลภูเขา เกาะ น้ำตก และอุทยานทั่วไทย ความสูง ระยะเดิน ระดับความยาก ช่วงที่ควรไปและช่วงที่ปิด อ่านได้แม้ยังไม่มีรอบเปิดจอง',
        ],
        '/seasons' => [
            'title' => 'เดือนไหนไปไหนดี | ปฏิทินธรรมชาติไทยทั้งปี',
            'description' => 'ปฏิทิน 12 เดือนของธรรมชาติไทย เดือนนี้ที่ไหนอยู่ในช่วงพีค ที่ไหนปิดฟื้นฟู ใช้วางแผนเที่ยวล่วงหน้าได้ทั้งปี',
            'type' => 'article',
        ],
        '/difficulty' => [
            'title' => 'ระดับความยากเดินป่าหมายถึงอะไร',
            'description' => 'อธิบายเกณฑ์ระดับความยากของทริปเดินป่า สายชิล ปานกลาง สายโหด ต่างกันยังไง ใช้ระยะทางและความสูงที่ต้องไต่เท่าไหร่ และจะรู้ได้ยังไงว่าเราไหว',
            'type' => 'article',
        ],
        '/checklist' => [
            'title' => 'เช็คลิสต์ของที่ต้องเตรียม | เดินป่า ทะเล แคมป์ปิ้ง',
            'description' => 'เช็คลิสต์อุปกรณ์เดินป่า ดำน้ำตื้น และแคมป์ปิ้ง แยกตามฤดูและจำนวนวัน ติ๊กได้ ปรินต์ได้ ใช้ฟรีแม้ไม่ได้จองทริปกับเรา',
            'type' => 'article',
        ],
        '/feed' => [
            'title' => 'ฟีดจากนักเดินทาง | รูปจริงจากคนที่ไปมาแล้ว',
            'description' => 'รูปที่ผู้ร่วมทริปโพสต์เองหลังกลับจากทริป ไม่ผ่านการคัดของทีมงาน ดูบรรยากาศจริงก่อนตัดสินใจ',
        ],
        '/passport' => [
            'title' => 'สมุดสะสมการเดินทาง',
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/my-tracks' => [
            'title' => 'บันทึกการเดินของฉัน',
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/group-plans' => [
            'title' => 'กลุ่มไปด้วยกัน',
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/profile' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/support' => [
            'title' => 'ศูนย์ช่วยเหลือ',
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
        '/auth/social/callback' => [
            'title' => null,
            'description' => null,
            'robots' => 'noindex, nofollow',
        ],
    ],

];
