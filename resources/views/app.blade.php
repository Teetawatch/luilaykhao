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
            "telephone": "+66-62-612-6006",
            "contactType": "customer service",
            "areaServed": "TH",
            "serviceArea": { "@@type": "Place", "name": "Worldwide" },
            "availableLanguage": ["Thai"]
        },
        "sameAs": []
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
        "telephone": "+66-62-612-6006",
        "email": "luilaykhao.info@@gmail.com",
        "address": {
            "@@type": "PostalAddress",
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
            "opens": "08:00",
            "closes": "22:00"
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

    <!-- JSON-LD BreadcrumbList -->
    <script type="application/ld+json">
    {
        "@@context": "https://schema.org",
        "@@type": "BreadcrumbList",
        "itemListElement": [
            {
                "@@type": "ListItem",
                "position": 1,
                "name": "หน้าแรก",
                "item": "{{ url('/') }}"
            },
            {
                "@@type": "ListItem",
                "position": 2,
                "name": "ค้นหาทริปทั้งหมด",
                "item": "{{ url('/trips') }}"
            },
            {
                "@@type": "ListItem",
                "position": 3,
                "name": "เกี่ยวกับเรา",
                "item": "{{ url('/about') }}"
            },
            {
                "@@type": "ListItem",
                "position": 4,
                "name": "ติดต่อเรา",
                "item": "{{ url('/contact') }}"
            }
        ]
    }
    </script>

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

    @include('partials.analytics')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-[var(--color-white)] text-[var(--color-text-mid)] antialiased" style="font-family: 'DB Heavent', 'Anuphan', sans-serif;">
    <div id="app"></div>



</body>
</html>
