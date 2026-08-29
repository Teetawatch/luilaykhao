{{--
    เลย์เอาต์ของหน้าสาธารณะ "ให้ลูกค้ากรอกข้อมูลเอง" (/r/ และ /g/)

    แยกจาก passenger-fill.layout โดยตั้งใจ — หน้านั้นออกให้ทีละคนหลังมีการจองแล้ว
    ส่วนหน้านี้คือหน้าแรกที่ลูกค้าใหม่เห็นก่อนตัดสินใจฝากข้อมูล หน้าตาจึงต้องทำให้
    เชื่อใจได้ในสามวินาทีแรก และใช้โทนสีเดียวกับเว็บหลัก (Deep Forest / Canopy)

    ทุกอย่างอยู่ในไฟล์เดียว ไม่พึ่ง Vite/CDN เพราะหน้านี้ถูกเปิดจากลิงก์ในแชท
    ผ่านเบราว์เซอร์ในแอปไลน์/เฟส/ไอจี ที่บางเครื่องโหลดของข้ามโดเมนไม่ผ่าน
--}}
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- ลิงก์นี้ถูกส่งต่อในแชท ไม่ควรถูกจัดทำดัชนี --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0D2B1E">
    <meta name="color-scheme" content="light only">
    <title>@yield('title', 'กรอกข้อมูลผู้เดินทาง') · ลุยเลเขา</title>

    {{-- การ์ดพรีวิวตอนวางลิงก์ในแชท — ลิงก์นี้เดินทางด้วยการวางในไลน์/เมสเซนเจอร์
         เป็นหลัก ถ้าไม่มีการ์ด ลูกค้าเห็นแค่ URL สุ่มยาว ๆ แล้วลังเลที่จะกด
         และตอนลูกค้าส่งลิงก์กลุ่มต่อให้เพื่อน การ์ดคือสิ่งที่อธิบายแทนเขา --}}
    @php
        $ogTitle = ($ogTitle ?? null) ?: (($heroTitle ?? 'กรอกข้อมูลผู้เดินทาง').' · ลุยเลเขา');
        $ogDescription = ($ogDescription ?? null) ?: ($heroSub ?? 'กรอกข้อมูลผู้เดินทางล่วงหน้า ใช้เวลาประมาณ 2 นาที');
        $ogImage = ($ogImage ?? null) ?: ($heroImage ?? null);
    @endphp
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ลุยเลเขา">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
        <meta property="og:image:alt" content="{{ $heroTitle ?? 'ลุยเลเขา' }}">
    @endif
    <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    @if ($ogImage)
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon-32x32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/logo.png') }}">
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}?v={{ filemtime(public_path('fonts/db-heavent/db-heavent.css')) }}">

    <style>
        :root {
            --forest: #0D2B1E;
            --forest-mid: #1A3A2E;
            --canopy: #2D7A4F;
            --canopy-dark: #1A5732;
            --leaf: #4CAF7D;
            --ink: #0D2B1E;
            --body: #2C4A3A;
            --muted: #6B8F7A;
            --line: #E4EBE6;
            --line-mid: #CDDAD2;
            --tint: #F1F7F3;
            --tint-line: #D7E7DE;
            --bg: #F3F6F3;
            --amber: #8A5A00;
            --amber-bg: #FFF9EC;
            --amber-line: #F1DCAB;
            --danger: #A32318;
            --danger-bg: #FDF4F3;
            --danger-line: #F2C8C3;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: 'DB Heavent', 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--body);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -webkit-tap-highlight-color: transparent;
        }

        /* แถบสีเข้มด้านบน — ทำให้การ์ดขาวลอยอยู่บนพื้นแบรนด์ แทนการใช้เงา */
        body::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0;
            height: 300px;
            background: linear-gradient(160deg, #0D2B1E 0%, #16382A 55%, #24523C 100%);
            z-index: -1;
        }

        .shell {
            max-width: 560px;
            margin: 0 auto;
            padding: 18px 16px calc(40px + env(safe-area-inset-bottom));
        }

        /* ── หัวกระดาษ ─────────────────────────────────────────────── */
        .masthead { display: flex; align-items: center; gap: 10px; padding: 4px 2px 16px; color: #fff; }
        .masthead img { width: 34px; height: 34px; border-radius: 10px; object-fit: cover; background: rgba(255,255,255,.12); }
        .masthead .name { font-size: 18px; font-weight: 700; letter-spacing: .2px; }
        .masthead .tag {
            margin-left: auto; font-size: 14px; color: rgba(255,255,255,.72);
            display: inline-flex; align-items: center; gap: 6px;
        }

        /* ── การ์ดหลัก ─────────────────────────────────────────────── */
        .card { background: #fff; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; }

        .hero { position: relative; background: var(--forest); color: #fff; padding: 24px 22px 26px; overflow: hidden; }
        .hero-media { position: absolute; inset: 0; background-size: cover; background-position: center; }
        .hero-media::after {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(13,43,30,.72) 0%, rgba(13,43,30,.94) 100%);
        }
        .hero-inner { position: relative; }
        .hero .eyebrow {
            font-size: 14px; font-weight: 500; color: var(--leaf); letter-spacing: .5px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .hero h1 { font-size: 27px; font-weight: 700; line-height: 1.25; margin-top: 6px; }
        .hero .sub { margin-top: 8px; font-size: 16px; color: rgba(255,255,255,.8); line-height: 1.55; }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 15px; }
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: 14.5px; padding: 6px 12px; border-radius: 999px;
            background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.2); color: #fff;
        }
        .chip .ic { opacity: .85; }

        .card-body { padding: 22px; }

        /* ── พื้นฐานตัวอักษร ────────────────────────────────────────── */
        .lead { font-size: 16.5px; color: var(--body); line-height: 1.65; margin-bottom: 18px; }
        .lead strong { color: var(--ink); font-weight: 700; }
        .ic { width: 18px; height: 18px; flex: 0 0 auto; display: block; }

        /* ── กล่องข้อความ ──────────────────────────────────────────── */
        .callout {
            display: flex; gap: 11px; align-items: flex-start;
            border: 1px solid; border-radius: 14px; padding: 13px 14px;
            font-size: 15.5px; line-height: 1.55; margin-bottom: 16px;
        }
        .callout strong { display: block; font-weight: 700; margin-bottom: 1px; }
        .callout .ic { width: 20px; height: 20px; margin-top: 1px; }
        .callout ul { margin: 5px 0 0 18px; }
        .callout--warn { background: var(--amber-bg); border-color: var(--amber-line); color: var(--amber); }
        .callout--ok { background: var(--tint); border-color: var(--tint-line); color: var(--canopy-dark); }
        .callout--error { background: var(--danger-bg); border-color: var(--danger-line); color: var(--danger); }

        /* ── หัวข้อย่อยแบบมีลำดับ ──────────────────────────────────── */
        .step { display: flex; align-items: center; gap: 10px; margin: 26px 0 14px; }
        .step:first-child { margin-top: 4px; }
        .step .n {
            flex: 0 0 26px; height: 26px; border-radius: 9px;
            background: var(--tint); border: 1px solid var(--tint-line); color: var(--canopy-dark);
            font-size: 15px; font-weight: 700; display: grid; place-items: center;
        }
        .step .n .ic { width: 16px; height: 16px; }
        .step h2 { font-size: 17px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .step .rule { flex: 1; height: 1px; background: var(--line); }
        .step-note { font-size: 14.5px; color: var(--muted); line-height: 1.55; margin: -6px 0 14px; }
        .step-note strong { color: var(--ink); font-weight: 700; }

        /* ── รอบเดินทางที่กำลังกรอกอยู่ ─────────────────────────────── */
        .round {
            display: flex; gap: 12px; align-items: flex-start;
            border: 1px solid var(--tint-line); background: var(--tint);
            border-radius: 14px; padding: 13px 14px; margin-bottom: 16px;
        }
        .round-ic {
            flex: 0 0 34px; height: 34px; border-radius: 11px;
            background: #fff; border: 1px solid var(--tint-line); color: var(--canopy-dark);
            display: grid; place-items: center;
        }
        .round-text { display: flex; flex-direction: column; gap: 2px; }
        .round-label { font-size: 13.5px; color: var(--canopy-dark); }
        .round-text strong { font-size: 18px; font-weight: 700; color: var(--ink); line-height: 1.3; }
        .round-meta { font-size: 14.5px; color: var(--body); line-height: 1.5; }
        .round--closed { border-color: var(--amber-line); background: var(--amber-bg); }
        .round--closed .round-ic { border-color: var(--amber-line); color: var(--amber); }
        .round--closed .round-label { color: var(--amber); }
        .round-flag {
            align-self: flex-start; margin-top: 5px;
            border-radius: 999px; padding: 3px 11px;
            background: var(--amber); color: #fff; font-size: 13.5px; font-weight: 700;
        }

        /* ── จุดขึ้นรถ ─────────────────────────────────────────────── */
        .pickups { display: grid; gap: 10px; margin-bottom: 15px; }
        .pickup { position: relative; display: block; }
        .pickup input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .pickup-card {
            display: flex; align-items: center; gap: 12px;
            border: 1px solid var(--line-mid); border-radius: 14px; background: #fff;
            padding: 10px; transition: border-color .15s ease, background-color .15s ease;
        }
        .pickup-photo {
            width: 74px; height: 74px; flex: 0 0 74px; border-radius: 11px;
            object-fit: cover; background: var(--tint);
        }
        .pickup-photo--blank {
            display: grid; place-items: center; color: var(--muted);
            border: 1px dashed var(--line-mid);
        }
        .pickup-photo--blank .ic { width: 22px; height: 22px; }
        .pickup-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .pickup-body strong { font-size: 16.5px; color: var(--ink); line-height: 1.35; }
        .pickup-meta { font-size: 14px; color: var(--muted); }
        .pickup-note { font-size: 13.5px; color: var(--muted); line-height: 1.45; }
        .pickup-price { font-size: 14.5px; font-weight: 700; color: var(--canopy-dark); margin-top: 2px; }
        .pickup-tick {
            flex: 0 0 24px; width: 24px; height: 24px; border-radius: 50%;
            border: 1px solid var(--line-mid); color: transparent;
            display: grid; place-items: center;
        }
        .pickup-tick .ic { width: 14px; height: 14px; }
        .pickup input:checked + .pickup-card { border-color: var(--canopy); background: var(--tint); }
        .pickup input:checked + .pickup-card .pickup-tick {
            background: var(--canopy); border-color: var(--canopy); color: #fff;
        }
        .pickup input:focus-visible + .pickup-card { outline: 2px solid rgba(45,122,79,.35); outline-offset: 1px; }

        /* ── ฟิลด์ ─────────────────────────────────────────────────── */
        .f { margin-bottom: 15px; }
        .f:last-child { margin-bottom: 0; }
        label.field { display: block; font-size: 15px; font-weight: 500; color: var(--body); margin-bottom: 6px; }
        .req { color: #C0392B; font-weight: 700; }
        .opt { color: var(--muted); font-weight: 400; font-size: 14px; }

        input[type=text], input[type=tel], input[type=email], input[type=date],
        input[type=number], select, textarea {
            width: 100%; border: 1px solid var(--line-mid); border-radius: 12px;
            padding: 12px 13px; font-family: inherit; font-size: 16px; font-weight: 400;
            background: #fff; color: var(--ink); line-height: 1.4;
            -webkit-appearance: none; appearance: none;
            transition: border-color .15s ease, background-color .15s ease;
        }
        input::placeholder, textarea::placeholder { color: #A9BDB1; }
        input:focus, select:focus, textarea:focus {
            border-color: var(--canopy); outline: 2px solid rgba(45,122,79,.28); outline-offset: 1px;
        }
        input[type=date] { min-height: 46px; }
        textarea { min-height: 82px; resize: vertical; line-height: 1.55; }
        select {
            padding-right: 40px; cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%232C4A3A' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 13px center; background-size: 17px;
        }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grid2--tight { grid-template-columns: 118px 1fr; }
        @media (max-width: 359px) { .grid2, .grid2--tight { grid-template-columns: 1fr; } }
        .hint { font-size: 14px; color: var(--muted); margin-top: 6px; line-height: 1.5; }

        /* ── ตัวเลือกแบบปุ่ม — แตะง่ายกว่า dropdown บนมือถือ ─────────── */
        .pills { display: flex; flex-wrap: wrap; gap: 8px; }
        .pill { position: relative; display: block; }
        .pill input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .pill span {
            display: block; padding: 10px 15px; border-radius: 11px;
            border: 1px solid var(--line-mid); background: #fff;
            font-size: 15.5px; color: var(--body); user-select: none;
            transition: border-color .15s ease, background-color .15s ease, color .15s ease;
        }
        .pill input:checked + span {
            border-color: var(--canopy); background: var(--tint);
            color: var(--canopy-dark); font-weight: 700;
        }
        .pill input:focus-visible + span { outline: 2px solid rgba(45,122,79,.35); outline-offset: 1px; }

        /* ── ตัวนับจำนวนคน ─────────────────────────────────────────── */
        .stepper { display: flex; align-items: center; gap: 12px; }
        .stepper-box {
            display: inline-flex; align-items: center;
            border: 1px solid var(--line-mid); border-radius: 12px; background: #fff; overflow: hidden;
        }
        .stepper-box button {
            width: 48px; height: 48px; border: none; background: #fff; color: var(--canopy-dark);
            display: grid; place-items: center; cursor: pointer; font-family: inherit;
            transition: background-color .15s ease;
        }
        .stepper-box button:hover { background: var(--tint); }
        .stepper-box button:disabled { color: #C3D3CA; cursor: default; background: #fff; }
        .stepper-box button .ic { width: 20px; height: 20px; }
        .stepper-box input {
            width: 62px; text-align: center; border: none;
            border-left: 1px solid var(--line); border-right: 1px solid var(--line);
            border-radius: 0; font-size: 17px; font-weight: 700; padding: 13px 0;
            -moz-appearance: textfield;
        }
        .stepper-box input::-webkit-outer-spin-button,
        .stepper-box input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .stepper-box input:focus { outline-offset: -2px; }
        .stepper .unit { font-size: 16px; color: var(--muted); }

        /* ── ช่องติ๊ก ───────────────────────────────────────────────── */
        .check {
            display: flex; gap: 11px; align-items: flex-start;
            border: 1px solid var(--line-mid); border-radius: 12px; padding: 13px 14px;
            font-size: 15.5px; color: var(--body); line-height: 1.5; cursor: pointer;
            transition: border-color .15s ease, background-color .15s ease;
        }
        .check input {
            width: 20px; height: 20px; flex: 0 0 20px; margin: 1px 0 0;
            accent-color: var(--canopy); cursor: pointer;
        }
        .check:has(input:checked) { border-color: var(--canopy); background: var(--tint); }
        .check--consent { background: var(--tint); border-color: var(--tint-line); }

        /* ── ปุ่ม ───────────────────────────────────────────────────── */
        .btn {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; border: none; border-radius: 14px; padding: 15px;
            background: var(--canopy); color: #fff; font-family: inherit;
            font-size: 17.5px; font-weight: 700; cursor: pointer; text-decoration: none;
            transition: background-color .15s ease;
        }
        .btn:hover { background: var(--canopy-dark); }
        .btn:active { transform: translateY(1px); }
        .btn[disabled] { background: #A2C4B0; cursor: default; }
        .btn .ic { width: 19px; height: 19px; }
        .btn--ghost { background: #fff; color: var(--canopy-dark); border: 1px solid var(--canopy); }
        .btn--ghost:hover { background: var(--tint); }
        .form-actions { margin-top: 20px; }

        /* ── ท้ายหน้า ───────────────────────────────────────────────── */
        .privacy {
            display: flex; gap: 9px; align-items: flex-start;
            margin-top: 18px; padding-top: 16px; border-top: 1px solid var(--line);
            font-size: 14px; color: var(--muted); line-height: 1.6;
        }
        .privacy .ic { width: 17px; height: 17px; margin-top: 2px; }
        .site-foot {
            text-align: center; margin-top: 18px;
            font-size: 14px; color: var(--muted); line-height: 1.6;
        }
        .site-foot a { color: var(--canopy-dark); text-decoration: none; font-weight: 500; }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="shell">
        <header class="masthead">
            <img src="{{ asset('images/logo.png') }}" alt="" width="34" height="34">
            <span class="name">ลุยเลเขา</span>
            <span class="tag">@include('intake.icon', ['name' => 'lock']) ข้อมูลเข้ารหัส</span>
        </header>

        <main class="card">
            <div class="hero">
                @if (! empty($heroImage))
                    <div class="hero-media" style="background-image:url('{{ $heroImage }}')"></div>
                @endif
                <div class="hero-inner">
                    @if (! empty($heroEyebrow))
                        <div class="eyebrow">@include('intake.icon', ['name' => $heroEyebrowIcon ?? 'sparkle']) {{ $heroEyebrow }}</div>
                    @endif
                    <h1>{{ $heroTitle ?? 'กรอกข้อมูลผู้เดินทาง' }}</h1>
                    @if (! empty($heroSub))
                        <p class="sub">{{ $heroSub }}</p>
                    @endif
                    @if (! empty($heroChips))
                        <div class="chips">
                            @foreach ($heroChips as $chip)
                                <span class="chip">@include('intake.icon', ['name' => $chip['icon']]) {{ $chip['text'] }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="card-body">
                @yield('content')
            </div>
        </main>

        <p class="site-foot">
            &copy; {{ date('Y') }} ลุยเลเขา · <a href="{{ url('/') }}">luilaykhao.com</a>
        </p>
    </div>
    <script>
        // ── ตัวนับจำนวนคน ──────────────────────────────────────────
        document.querySelectorAll('[data-stepper]').forEach(function (box) {
            var input = box.querySelector('input');
            var minus = box.querySelector('[data-step="-1"]');
            var plus = box.querySelector('[data-step="1"]');
            var min = parseInt(input.min || '1', 10);
            var max = parseInt(input.max || '20', 10);

            function sync() {
                var value = parseInt(input.value || min, 10);
                if (isNaN(value)) { value = min; }
                value = Math.min(max, Math.max(min, value));
                input.value = value;
                minus.disabled = value <= min;
                plus.disabled = value >= max;
            }

            box.addEventListener('click', function (event) {
                var button = event.target.closest('[data-step]');
                if (!button) { return; }
                input.value = (parseInt(input.value || min, 10) || min) + parseInt(button.dataset.step, 10);
                sync();
            });
            input.addEventListener('change', sync);
            sync();
        });

        // ── ใส่ขีดให้เลขบัตรประชาชนระหว่างพิมพ์ (เซิร์ฟเวอร์ตัดขีดทิ้งอยู่แล้ว) ──
        document.querySelectorAll('[data-id-card]').forEach(function (input) {
            input.addEventListener('input', function () {
                var digits = input.value.replace(/\D/g, '').slice(0, 13);
                var groups = [digits.slice(0, 1), digits.slice(1, 5), digits.slice(5, 10), digits.slice(10, 12), digits.slice(12, 13)];
                input.value = groups.filter(function (part) { return part !== ''; }).join('-');
            });
        });

        // text-transform เป็นแค่ภาพ — ค่าที่ส่งไปต้องเป็นตัวใหญ่จริงด้วย ไม่งั้นชื่อบนตั๋วไม่ตรง
        document.querySelectorAll('[data-uppercase]').forEach(function (input) {
            input.addEventListener('blur', function () { input.value = input.value.toUpperCase(); });
        });

        // ── กันกดส่งซ้ำ ────────────────────────────────────────────
        document.querySelectorAll('form[data-guard]').forEach(function (form) {
            form.addEventListener('submit', function () {
                var button = form.querySelector('button[type="submit"]');
                if (!button) { return; }
                setTimeout(function () {
                    button.disabled = true;
                    button.textContent = 'กำลังส่งข้อมูล...';
                }, 0);
            });
        });
    </script>
    @stack('scripts')
</body>
</html>
