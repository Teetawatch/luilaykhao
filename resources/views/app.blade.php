<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    {{-- Per-page title / description / share card. Resolved server-side because
         LINE, Facebook and Twitter never run the SPA's JavaScript — see
         App\Support\SeoMeta. --}}
    <title>{{ $seo['title'] }}</title>
    <meta name="title" content="{{ $seo['title'] }}">
    <meta name="description" content="{{ $seo['description'] }}">
    <meta name="keywords" content="ลุยเลเขา, Luilaykhao, จองทริป, จัดทริป, เที่ยวไทย, เดินป่า, ดำน้ำตื้น, เช่ารถตู้นำเที่ยว, ทริปเดินป่า, ทริปดำน้ำ, ทริปภูเขา, ทริปทะเล, เที่ยวธรรมชาติ, แพลตฟอร์มจองทริป, ทริปผจญภัย, ท่องเที่ยวทั่วไทย, จองทริปเที่ยว, รถตู้ VIP, ทริปภูกระดึง, ทริปภูสอยดาว, ทริปดำน้ำตื้น, ทริปเขาช้างเผือก, บริษัทนำเที่ยว, ทัวร์ธรรมชาติ, ทริปต่างประเทศ, เทรกกิ้งต่างประเทศ, ทัวร์ต่างประเทศ, outdoor activities thailand">
    <meta name="author" content="ลุยเลเขา Luilaykhao">
    <meta name="robots" content="{{ $seo['robots'] }}">
    <meta name="google-site-verification" content="6E_H_ur05qV8VIU5BXFa3-4sCSv-C9nQGDcMceZLVc8" />
    <meta name="language" content="Thai">
    <meta name="rating" content="general">
    <meta name="geo.region" content="TH">
    <meta name="geo.placename" content="Thailand">

    {{-- ใบอนุญาตส่งมากับ shell ไม่ใช่ยิง API แยก — แถบบนสุดของ Navbar แสดง
         เลขนี้ทุกหน้า ถ้ารอ API จะเห็นแถบว่างวาบหนึ่งก่อนทุกครั้ง --}}
    <meta name="llk:licence-no" content="{{ \App\Support\SiteSettings::licenceNo() }}">
    <meta name="llk:licence-image" content="{{ \App\Support\SiteSettings::licenceImageUrl() }}">

    {{-- ข้อมูลติดต่อมาทางเดียวกับใบอนุญาต ด้วยเหตุผลเดียวกัน — เบอร์โทรอยู่บน
         Navbar ทุกหน้า ถ้ารอ API จะเห็นช่องว่างวาบหนึ่งก่อนทุกครั้งที่เปลี่ยนหน้า --}}
    <meta name="llk:support-phone" content="{{ \App\Support\SiteSettings::supportPhone() }}">
    <meta name="llk:support-line" content="{{ \App\Support\SiteSettings::supportLine() }}">
    <meta name="llk:support-line-url" content="{{ \App\Support\SiteSettings::supportLineUrl() }}">
    <meta name="llk:support-email" content="{{ \App\Support\SiteSettings::supportEmail() }}">
    <meta name="llk:support-hours" content="{{ \App\Support\SiteSettings::supportHours() }}">

    {{-- ผู้ประกอบการตามใบอนุญาต — ว่างได้ ฝั่งเว็บซ่อนบรรทัดที่ไม่มีค่าเอง --}}
    <meta name="llk:operator-name" content="{{ \App\Support\SiteSettings::operatorName() }}">
    <meta name="llk:operator-address" content="{{ \App\Support\SiteSettings::operatorAddress() }}">

    {{-- เวอร์ชันเอกสารเงื่อนไข — หน้า /terms แสดงเลขนี้ และใบจองบันทึกไว้ว่า
         ลูกค้ากดยอมรับฉบับไหน (ดู config/legal.php) --}}
    <meta name="llk:terms-version" content="{{ config('legal.terms_version') }}">
    <meta name="llk:privacy-version" content="{{ config('legal.privacy_version') }}">

    {{-- แถบแนะนำแอปของ Safari บน iPhone — Apple วาดให้เอง ไม่กินพื้นที่หน้าเว็บ
         และผู้ใช้ปิดได้ถาวรด้วยตัวเอง จึงเป็นการชวนโหลดที่รบกวนน้อยที่สุดที่มี
         ไม่พิมพ์แท็กเลยเมื่อแกะเลขแอปไม่ได้ ดีกว่าชี้ไปแอปผิดตัว --}}
    @if ($appStoreId = \App\Support\AppLinks::appleAppStoreId())
        <meta name="apple-itunes-app" content="app-id={{ $appStoreId }}">
    @endif

    {{-- ลิงก์ร้านแอปส่งมากับ shell แบบเดียวกับข้อมูลติดต่อ (ดู lib/appLinks.js) --}}
    <meta name="llk:app-ios-url" content="{{ \App\Support\AppLinks::ios() }}">
    <meta name="llk:app-android-url" content="{{ \App\Support\AppLinks::android() }}">

    <!-- Canonical URL -->
    <link rel="canonical" href="{{ $seo['canonical'] }}">

    <!-- Language Alternatives -->
    <link rel="alternate" hreflang="th" href="{{ url('/') }}">
    <link rel="alternate" hreflang="x-default" href="{{ url('/') }}">

    <!-- Open Graph / Facebook -->
    <meta property="og:type" content="{{ $seo['type'] }}">
    <meta property="og:site_name" content="{{ config('seo.site_name') }}">
    <meta property="og:title" content="{{ $seo['og_title'] }}">
    <meta property="og:description" content="{{ $seo['description'] }}">
    <meta property="og:url" content="{{ $seo['canonical'] }}">
    <meta property="og:image" content="{{ $seo['image'] }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $seo['image_alt'] }}">
    <meta property="og:locale" content="th_TH">
    @foreach($seo['extra'] as $property => $content)
    <meta property="{{ $property }}" content="{{ $content }}">
    @endforeach

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $seo['og_title'] }}">
    <meta name="twitter:description" content="{{ $seo['description'] }}">
    <meta name="twitter:image" content="{{ $seo['image'] }}">
    <meta name="twitter:image:alt" content="{{ $seo['image_alt'] }}">

    {{-- Page-specific structured data (trip offer, place, breadcrumb, FAQ). The
         site-wide Organization / TravelAgency / WebSite blocks stay below. --}}
    {{-- JSON_HEX_TAG is load-bearing, not cosmetic: without it a trip title
         containing "</script>" would close this tag and everything after it
         would be parsed as markup. --}}
    @foreach($seo['json_ld'] as $block)
    <script type="application/ld+json">{!! json_encode($block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
    @endforeach

    <!-- JSON-LD Structured Data: Organization -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@type": "Organization",
        "name": "ลุยเลเขา",
        "alternateName": "Luilaykhao",
        "url": "{{ url('/') }}",
        "logo": "{{ asset('images/logo.png').'?v=2' }}",
        "description": "แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทยและต่างประเทศ บริการเดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว",
        "contactPoint": {
            "@@type": "ContactPoint",
            "telephone": "{{ \App\Support\PhoneNumber::international(\App\Support\SiteSettings::supportPhone()) }}",
            "email": "{{ \App\Support\SiteSettings::supportEmail() }}",
            "contactType": "customer service",
            "areaServed": "TH",
            "serviceArea": { "@@type": "Place", "name": "Worldwide" },
            "availableLanguage": ["Thai"]
        },
        @if(\App\Support\SiteSettings::operatorName())
        "legalName": "{{ \App\Support\SiteSettings::operatorName() }}",
        @endif
        {{-- ใบอนุญาตประกอบธุรกิจนำเที่ยว — สิ่งที่แยกผู้ประกอบการจริงออกจาก
             เพจขายทริป ประกาศไว้ให้เครื่องอ่านได้ ไม่ใช่แค่ตาคนอ่าน --}}
        "hasCredential": {
            "@@type": "EducationalOccupationalCredential",
            "credentialCategory": "ใบอนุญาตประกอบธุรกิจนำเที่ยว",
            "identifier": "{{ \App\Support\SiteSettings::licenceNo() }}",
            "recognizedBy": {
                "@@type": "GovernmentOrganization",
                "name": "กรมการท่องเที่ยว กระทรวงการท่องเที่ยวและกีฬา"
            }
        },
        "sameAs": {!! json_encode(array_values(array_filter(config('company.social', []))), JSON_UNESCAPED_SLASHES) !!}
    }
    </script>

    <!-- JSON-LD Structured Data: LocalBusiness (TravelAgency) -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@type": "TravelAgency",
        "name": "ลุยเลเขา Luilaykhao",
        "url": "{{ url('/') }}",
        "logo": "{{ asset('images/logo.png').'?v=2' }}",
        "image": "{{ asset('images/logo.png').'?v=2' }}",
        "description": "แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทยและต่างประเทศ เดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว ใบอนุญาตนำเที่ยวเลขที่ {{ \App\Support\SiteSettings::licenceNo() }} นำเที่ยวได้ทั้งในและต่างประเทศ",
        "telephone": "{{ \App\Support\PhoneNumber::international(\App\Support\SiteSettings::supportPhone()) }}",
        "email": "{{ \App\Support\SiteSettings::supportEmail() }}",
        "sameAs": {!! json_encode(array_values(array_filter(config('company.social', []))), JSON_UNESCAPED_SLASHES) !!},
        "address": {
            "@@type": "PostalAddress",
            @if(\App\Support\SiteSettings::operatorAddress())
            "streetAddress": "{{ \App\Support\SiteSettings::operatorAddress() }}",
            @endif
            "addressCountry": "TH"
        },
        "areaServed": [
            { "@@type": "Country", "name": "Thailand" },
            { "@@type": "Place", "name": "Worldwide" }
        ],
        "priceRange": "฿฿",
        "openingHoursSpecification": {
            "@@type": "OpeningHoursSpecification",
            "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
            "opens": "{{ \App\Support\SiteSettings::supportOpens() }}",
            "closes": "{{ \App\Support\SiteSettings::supportCloses() }}"
        },
        "hasOfferCatalog": {
            "@@type": "OfferCatalog",
            "name": "ทริปท่องเที่ยว",
            "itemListElement": [
                {
                    "@@type": "OfferCatalog",
                    "name": "ทริปเดินป่า",
                    "description": "ทริปเดินป่าสำรวจธรรมชาติทั่วประเทศไทย ภูกระดึง ภูสอยดาว เขาช้างเผือก"
                },
                {
                    "@@type": "OfferCatalog",
                    "name": "ทริปดำน้ำตื้น",
                    "description": "ดำน้ำดูปะการังและสัตว์ทะเลในทะเลไทย"
                },
                {
                    "@@type": "OfferCatalog",
                    "name": "เช่ารถตู้นำเที่ยว",
                    "description": "บริการรถตู้ VIP พร้อมคนขับนำเที่ยวทั่วประเทศไทย"
                }
            ]
        }
    }
    </script>

    <!-- JSON-LD Structured Data: WebSite with SearchAction -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@type": "WebSite",
        "name": "ลุยเลเขา Luilaykhao",
        "alternateName": "Luilaykhao",
        "url": "{{ url('/') }}",
        "description": "แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทย",
        "inLanguage": "th",
        "potentialAction": {
            "@@type": "SearchAction",
            "target": {
                "@@type": "EntryPoint",
                "urlTemplate": "{{ url('/trips') }}?search={search_term_string}"
            },
            "query-input": "required name=search_term_string"
        }
    }
    </script>

    {{-- The breadcrumb used to be hardcoded here, listing the whole top menu
         (หน้าแรก › ทริป › เกี่ยวกับเรา › ติดต่อเรา) on every single page. Google
         read it literally, so even the home page's search result showed a trail
         ending at /contact. A breadcrumb is the path to *this* page and nothing
         else, so it is built per page now — see App\Support\SeoMeta. --}}

    <!-- Preconnect for Performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@300;400;500;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet">
    {{-- Primary site font: DB Heavent (licensed, self-hosted, same-origin only) --}}
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}?v={{ filemtime(public_path('fonts/db-heavent/db-heavent.css')) }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Favicons & PWA -->
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('images/logo.png').'?v=2' }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('images/favicon-16x16.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/favicon.ico') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#0D2B1E">
    <meta name="msapplication-TileColor" content="#0D2B1E">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="ลุยเลเขา">

    {{-- Styles for the pre-boot markup in partials/boot-shell.blade.php. They
         live here, inline, because the Vite CSS bundle is exactly the thing
         that has not arrived yet at the moment this is on screen. --}}
    <style>
        .llk-boot { max-width: 1100px; margin: 0 auto; padding: 48px 20px 64px; }
        .llk-boot__brand { display: inline-block; font-size: 30px; font-weight: 800; color: #0D2B1E; text-decoration: none; }
        .llk-boot__tagline { margin: 8px 0 32px; max-width: 62ch; font-size: 18px; line-height: 1.6; color: #5b6660; }
        .llk-boot__nav { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 28px; }
        .llk-boot__heading { margin: 0 0 10px; font-size: 15px; font-weight: 700; color: #0D2B1E; }
        .llk-boot__list { margin: 0; padding: 0; list-style: none; }
        .llk-boot__list li { margin-bottom: 7px; }
        .llk-boot__list a { font-size: 17px; color: #3f4a45; text-decoration: none; }
        .llk-boot__list a:hover { text-decoration: underline; }

        /* The trip itself, on a trip page (partials/boot-trip). */
        .llk-trip { margin: 20px 0 40px; }
        .llk-trip__kicker { margin: 0 0 6px; font-size: 15px; font-weight: 700; color: #087C68; }
        .llk-trip__title { margin: 0 0 10px; font-size: 32px; font-weight: 800; line-height: 1.25; color: #0D2B1E; }
        .llk-trip__price { margin: 0 0 18px; font-size: 19px; font-weight: 700; color: #0D2B1E; }
        .llk-trip__cover { display: block; width: 100%; max-width: 760px; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 16px; margin-bottom: 20px; background: #eef1ef; }
        .llk-trip__lede { margin: 0 0 12px; max-width: 70ch; font-size: 18px; line-height: 1.7; color: #3f4a45; }
        .llk-trip__facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px 24px; margin: 22px 0; padding: 0; }
        .llk-trip__facts div { margin: 0; }
        .llk-trip__facts dt { font-size: 14px; font-weight: 700; color: #5b6660; }
        .llk-trip__facts dd { margin: 2px 0 0; font-size: 17px; color: #0D2B1E; }
        .llk-trip__h2 { margin: 30px 0 12px; font-size: 22px; font-weight: 800; color: #0D2B1E; }
        .llk-trip__h3 { margin: 18px 0 6px; font-size: 18px; font-weight: 700; color: #0D2B1E; }
        .llk-trip__body { margin: 0 0 10px; max-width: 70ch; font-size: 17px; line-height: 1.7; color: #3f4a45; white-space: pre-line; }
        .llk-trip__list { margin: 0; padding-left: 1.3em; }
        .llk-trip__list li { margin-bottom: 6px; font-size: 17px; line-height: 1.6; color: #3f4a45; }
        .llk-trip__rounds { margin: 0; padding: 0; list-style: none; }
        .llk-trip__rounds li { display: flex; flex-wrap: wrap; gap: 4px 12px; padding: 9px 0; border-bottom: 1px solid #e7ebe9; }
        .llk-trip__round-date { font-size: 17px; font-weight: 700; color: #0D2B1E; }
        .llk-trip__round-meta { font-size: 16px; color: #5b6660; }
    </style>

    @include('partials.analytics')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[var(--color-white)] text-[var(--color-text-mid)] antialiased" style="font-family: 'DB Heavent', 'Anuphan', sans-serif;">
    <div id="app">@include('partials.boot-shell')</div>



</body>
</html>
