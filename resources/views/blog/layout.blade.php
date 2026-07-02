<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">

    <title>@yield('title', 'บทความ | ลุยเลเขา')</title>
    <meta name="description" content="@yield('meta_description', 'บทความและคำแนะนำเที่ยวธรรมชาติทั่วไทยจากลุยเลเขา')">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
    <meta name="author" content="ลุยเลเขา Luilaykhao">
    <meta name="geo.region" content="TH">
    <link rel="canonical" href="@yield('canonical', url()->current())">
    <link rel="alternate" hreflang="th" href="@yield('canonical', url()->current())">

    {{-- Open Graph / Twitter --}}
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:site_name" content="ลุยเลเขา Luilaykhao">
    <meta property="og:title" content="@yield('og_title', 'บทความ | ลุยเลเขา')">
    <meta property="og:description" content="@yield('meta_description', 'บทความและคำแนะนำเที่ยวธรรมชาติทั่วไทย')">
    <meta property="og:url" content="@yield('canonical', url()->current())">
    <meta property="og:image" content="@yield('og_image', asset('images/logo.png').'?v=2')">
    <meta property="og:locale" content="th_TH">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('og_title', 'บทความ | ลุยเลเขา')">
    <meta name="twitter:description" content="@yield('meta_description', 'บทความและคำแนะนำเที่ยวธรรมชาติทั่วไทย')">
    <meta name="twitter:image" content="@yield('og_image', asset('images/logo.png').'?v=2')">

    @stack('jsonld')

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&family=Playfair+Display:wght@700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}?v={{ filemtime(public_path('fonts/db-heavent/db-heavent.css')) }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="shortcut icon" href="{{ asset('images/favicon.ico') }}">
    <meta name="theme-color" content="#0D2B1E">

    <style>
        :root {
            --brand: #0D2B1E;
            --brand-2: #087C68;
            --accent: #E8A33D;
            --ink: #16201B;
            --muted: #5B6B63;
            --line: #E6EAE7;
            --bg: #F6F8F6;
            --surface: #FFFFFF;
            --radius: 16px;
            --shadow: 0 1px 2px rgba(16,24,40,.05), 0 10px 30px rgba(16,24,40,.06);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'DB Heavent', 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg); color: var(--ink); line-height: 1.65;
            -webkit-font-smoothing: antialiased; min-height: 100dvh;
        }
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; }
        .wrap { max-width: 1080px; margin: 0 auto; padding: 0 20px; }
        .article-wrap { max-width: 760px; }

        /* Header */
        .site-header {
            position: sticky; top: 0; z-index: 30;
            background: rgba(255,255,255,.92); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--line);
        }
        .site-header .bar { display: flex; align-items: center; justify-content: space-between; height: 60px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 22px; color: var(--brand); }
        .brand img { width: 34px; height: 34px; border-radius: 9px; }
        .nav { display: flex; gap: 22px; align-items: center; font-size: 19px; font-weight: 600; color: var(--muted); }
        .nav a:hover { color: var(--brand); }
        .nav .cta {
            background: var(--brand); color: #fff; padding: 9px 18px; border-radius: 999px; font-weight: 700;
        }
        .nav .cta:hover { background: var(--brand-2); color: #fff; }

        /* Footer */
        .site-footer { margin-top: 64px; border-top: 1px solid var(--line); background: var(--surface); }
        .site-footer .wrap { padding-top: 28px; padding-bottom: 28px; display: flex; flex-wrap: wrap; gap: 16px; justify-content: space-between; color: var(--muted); font-size: 18px; }
        .site-footer .cta { color: var(--brand-2); font-weight: 700; }

        @media (max-width: 640px) {
            .nav { gap: 14px; font-size: 17px; }
            .nav .ghost { display: none; }
        }
    </style>
    @stack('head')
</head>
<body>
    <header class="site-header">
        <div class="wrap bar">
            <a href="{{ url('/') }}" class="brand">
                <img src="{{ asset('images/logo.png').'?v=2' }}" alt="ลุยเลเขา">
                <span>ลุยเลเขา</span>
            </a>
            <nav class="nav">
                <a href="{{ url('/blog') }}" class="ghost">บทความ</a>
                <a href="{{ url('/trips') }}" class="cta">จองทริป</a>
            </nav>
        </div>
    </header>

    <main>
        @yield('content')
    </main>

    <footer class="site-footer">
        <div class="wrap">
            <div>© {{ date('Y') }} ลุยเลเขา · ใบอนุญาตนำเที่ยว 12/03773</div>
            <a href="{{ url('/trips') }}" class="cta">ดูทริปทั้งหมด →</a>
        </div>
    </footer>
</body>
</html>
