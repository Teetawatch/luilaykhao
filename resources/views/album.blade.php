<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#044C4D" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#08201E" media="(prefers-color-scheme: dark)">
    <title>อัลบั้มรูปทริป | ลุยเลเขา</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}?v={{ filemtime(public_path('fonts/db-heavent/db-heavent.css')) }}">

    <style>
        /* ดีไซน์แบน: ไม่มีเงา ไม่มีไล่สี ใช้เส้นขอบบางแยกพื้นที่แทนทั้งหน้า */
        :root {
            color-scheme: light dark;

            --brand: #087C68;
            --brand-ink: #ffffff;
            --header-bg: #044C4D;
            --header-line: rgba(255,255,255,0.14);
            --header-soft: rgba(255,255,255,0.08);
            --header-text: rgba(255,255,255,0.72);

            --ink: #101828;
            --ink-2: #344054;
            --muted: #667085;
            --line: #E4E7E6;
            --bg: #F6F7F7;
            --surface: #FFFFFF;
            --tile-bg: #E9ECEB;

            --notice-bg: #FFFBF2;
            --notice-line: #EFE0C0;
            --notice-ink: #6B4A08;
            --notice-icon: #B0791F;

            --radius: 14px;
            --radius-pill: 999px;
            --bar-h: 58px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --brand: #14A187;
                --brand-ink: #04201B;
                --header-bg: #08201E;
                --header-line: rgba(255,255,255,0.10);
                --header-soft: rgba(255,255,255,0.06);
                --header-text: rgba(255,255,255,0.66);

                --ink: #F1F4F3;
                --ink-2: #C6CFCC;
                --muted: #8D9995;
                --line: #232A28;
                --bg: #0D110F;
                --surface: #141917;
                --tile-bg: #1B211F;

                --notice-bg: #241D10;
                --notice-line: #3F3320;
                --notice-ink: #E7CE9C;
                --notice-icon: #D3A44A;
            }
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html { scroll-behavior: smooth; }
        html, body {
            font-family: 'DB Heavent', 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100dvh;
            -webkit-font-smoothing: antialiased;
        }
        body.no-scroll { overflow: hidden; }

        svg { display: block; }
        .icon { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; fill: none; }
        .hidden { display: none !important; }
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }

        .wrap { max-width: 1240px; margin: 0 auto; padding-inline: clamp(14px, 3.4vw, 28px); }

        /* ── Header ───────────────────────────────────── */
        header {
            background: var(--header-bg);
            color: #fff;
            padding-top: max(18px, calc(env(safe-area-inset-top) + 12px));
            padding-bottom: clamp(18px, 3.6vw, 26px);
        }
        header .brand {
            display: inline-flex; align-items: center; gap: 8px;
            color: var(--header-text);
            font-size: 12.5px; font-weight: 700; letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        header .brand .mark {
            width: 26px; height: 26px; border-radius: 8px;
            background: var(--header-soft); border: 1px solid var(--header-line);
            display: flex; align-items: center; justify-content: center; color: #fff;
        }
        header .brand .mark .icon { width: 15px; height: 15px; }

        header h1 {
            margin-top: 12px;
            font-size: clamp(22px, 5.6vw, 34px);
            font-weight: 800; letter-spacing: -0.02em; line-height: 1.2;
            overflow-wrap: anywhere;
        }

        /* ชิปข้อมูลรอบ: วันเดินทาง / จำนวนรูป / จำนวนคนเข้าดู */
        .chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--header-soft); border: 1px solid var(--header-line);
            color: rgba(255,255,255,0.92);
            border-radius: var(--radius-pill);
            padding: 6px 12px; font-size: 13px; font-weight: 600;
            white-space: nowrap;
        }
        .chip .icon { width: 15px; height: 15px; color: rgba(255,255,255,0.72); }
        .chip b { font-weight: 800; }
        .chip.skeleton { color: transparent; background: var(--header-soft); width: 108px; height: 30px; }

        /* ── Sticky toolbar ───────────────────────────── */
        .toolbar {
            position: sticky; top: 0; z-index: 20;
            background: color-mix(in srgb, var(--bg) 88%, transparent);
            backdrop-filter: saturate(1.6) blur(12px);
            -webkit-backdrop-filter: saturate(1.6) blur(12px);
            border-bottom: 1px solid var(--line);
        }
        @supports not (background: color-mix(in srgb, red 50%, blue)) {
            .toolbar { background: var(--bg); }
        }
        .toolbar .wrap {
            min-height: var(--bar-h);
            display: flex; align-items: center; gap: 12px;
            padding-block: 10px;
        }
        .toolbar .count {
            font-size: 14px; color: var(--ink-2); font-weight: 700;
            display: inline-flex; align-items: center; gap: 7px; flex-wrap: wrap;
        }
        .toolbar .count .icon { width: 17px; height: 17px; color: var(--muted); }
        .toolbar .count .dim { color: var(--muted); font-weight: 600; }
        /* ── Buttons ──────────────────────────────────── */
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            border: 1px solid transparent; border-radius: var(--radius-pill);
            padding: 10px 16px; font-size: 13.5px; font-weight: 700;
            font-family: inherit; cursor: pointer; text-decoration: none;
            transition: background 0.14s, border-color 0.14s, color 0.14s, transform 0.14s;
            white-space: nowrap;
        }
        .btn:active { transform: scale(0.97); }
        .btn .icon { width: 17px; height: 17px; }
        .btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .btn-primary { background: var(--brand); color: var(--brand-ink); }
        .btn-primary:hover { background: color-mix(in srgb, var(--brand) 86%, #000); }

        /* ── Layout ───────────────────────────────────── */
        main { padding-block: clamp(16px, 3vw, 24px) clamp(40px, 8vw, 72px); }

        .notice {
            display: flex; align-items: flex-start; gap: 10px;
            background: var(--notice-bg); border: 1px solid var(--notice-line); border-radius: var(--radius);
            padding: 13px 15px; margin-bottom: 16px;
            font-size: 13.5px; line-height: 1.6; color: var(--notice-ink);
        }
        .notice .icon { width: 17px; height: 17px; color: var(--notice-icon); flex: none; margin-top: 2px; }
        .notice strong { font-weight: 800; }

        /* ── Grid ─────────────────────────────────────── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 156px), 1fr));
            gap: clamp(7px, 1.4vw, 12px);
        }
        @media (min-width: 700px) {
            .grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
        }

        .tile {
            position: relative;
            aspect-ratio: 1;
            border-radius: var(--radius);
            overflow: hidden;
            background: var(--tile-bg);
            border: 1px solid var(--line);
            /* ค่อย ๆ ไล่ขึ้นมาเมื่อรูปโหลดเสร็จ แทนที่จะกระตุกทีละใบ */
            animation: tileIn 0.28s ease both;
        }
        @keyframes tileIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) {
            .tile { animation: none; }
            html { scroll-behavior: auto; }
        }
        .tile img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
            cursor: zoom-in;
            transition: transform 0.36s cubic-bezier(0.2, 0.7, 0.3, 1), opacity 0.24s;
        }
        .tile:hover img { transform: scale(1.05); }

        .tile .dl {
            position: absolute; bottom: 8px; right: 8px;
            width: 34px; height: 34px;
            border-radius: var(--radius-pill);
            background: rgba(12,15,14,0.72);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            color: #fff; text-decoration: none;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.14s, opacity 0.14s, transform 0.14s;
            opacity: 0;
        }
        .tile:hover .dl, .tile:focus-within .dl { opacity: 1; }
        .tile .dl:hover { background: var(--brand); color: var(--brand-ink); }
        .tile .dl .icon { width: 17px; height: 17px; }
        @media (hover: none) { .tile .dl { opacity: 1; } }

        /* ── States ───────────────────────────────────── */
        .state { text-align: center; padding: clamp(48px, 12vw, 80px) 20px; color: var(--muted); }
        .state .badge {
            width: 60px; height: 60px; margin: 0 auto 16px;
            border-radius: var(--radius); background: var(--surface);
            border: 1px solid var(--line);
            display: flex; align-items: center; justify-content: center;
            color: var(--brand);
        }
        .state .badge .icon { width: 27px; height: 27px; stroke-width: 1.75; }
        .state h2 { font-size: 18px; color: var(--ink); margin-bottom: 6px; font-weight: 800; }
        .state p { font-size: 14px; max-width: 380px; margin: 0 auto; line-height: 1.65; }

        /* โครงรูปตอนกำลังโหลด — เห็นหน้าตาอัลบั้มทันทีแทนวงกลมหมุน */
        .skel {
            border-radius: var(--radius); background: var(--tile-bg);
            border: 1px solid var(--line); aspect-ratio: 1;
            position: relative; overflow: hidden;
        }
        .skel::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(90deg, transparent, color-mix(in srgb, var(--surface) 70%, transparent), transparent);
            transform: translateX(-100%);
            animation: shimmer 1.25s infinite;
        }
        @keyframes shimmer { to { transform: translateX(100%); } }
        @media (prefers-reduced-motion: reduce) { .skel::after { animation: none; } }

        /* ── Lightbox ─────────────────────────────────── */
        .lightbox {
            position: fixed; inset: 0; z-index: 50;
            background: #0A0D0C;
            display: flex; align-items: center; justify-content: center;
            animation: fade 0.16s ease;
            touch-action: pan-y;
            padding: clamp(56px, 9vh, 84px) clamp(12px, 6vw, 76px);
        }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
        .lightbox img {
            max-width: min(100%, 1400px); max-height: 100%;
            object-fit: contain;
            user-select: none; -webkit-user-drag: none;
        }
        .lb-top, .lb-bottom {
            position: absolute; left: 0; right: 0; z-index: 2;
            display: flex; align-items: center; gap: 10px;
            padding: 14px clamp(12px, 3vw, 20px);
        }
        .lb-top { top: 0; padding-top: max(14px, env(safe-area-inset-top)); }
        .lb-bottom { bottom: 0; padding-bottom: max(14px, env(safe-area-inset-bottom)); justify-content: center; }
        .lb-counter {
            color: rgba(255,255,255,0.9); font-size: 13px; font-weight: 700; letter-spacing: 0.02em;
            background: rgba(255,255,255,0.10); border: 1px solid rgba(255,255,255,0.16);
            padding: 6px 13px; border-radius: var(--radius-pill);
        }
        .lb-btn {
            cursor: pointer;
            background: rgba(255,255,255,0.10); color: #fff;
            border: 1px solid rgba(255,255,255,0.18);
            display: flex; align-items: center; justify-content: center;
            border-radius: var(--radius-pill);
            transition: background 0.14s;
        }
        .lb-btn:hover { background: rgba(255,255,255,0.2); }
        .lb-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        .lb-btn .icon { width: 21px; height: 21px; }
        .lb-close { width: 42px; height: 42px; margin-left: auto; }
        .lb-nav { position: absolute; top: 50%; transform: translateY(-50%); width: 44px; height: 48px; z-index: 2; }
        .lb-prev { left: clamp(8px, 2vw, 20px); }
        .lb-next { right: clamp(8px, 2vw, 20px); }
        @media (max-width: 560px) {
            .lb-nav { width: 38px; height: 42px; }
        }

        footer {
            border-top: 1px solid var(--line);
            text-align: center; padding: 24px 16px calc(32px + env(safe-area-inset-bottom));
            color: var(--muted); font-size: 12.5px;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        footer .icon { width: 14px; height: 14px; color: var(--muted); }
    </style>
</head>
<body>
    {{-- Inline SVG icon symbols (Lucide-style) reused across the page --}}
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-camera" viewBox="0 0 24 24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3.5"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></symbol>
        <symbol id="i-images" viewBox="0 0 24 24"><rect x="3" y="3" width="13" height="13" rx="2"/><path d="m7 11 2-2 3 3M21 8v11a2 2 0 0 1-2 2H8"/></symbol>
        <symbol id="i-eye" viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol>
        <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
        <symbol id="i-chevron-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
        <symbol id="i-chevron-right" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
        <symbol id="i-image-off" viewBox="0 0 24 24"><path d="M10.4 4H19a2 2 0 0 1 2 2v8.6M21 16v3a2 2 0 0 1-2 2H6.4M3 3l18 18M3 7.8V18a2 2 0 0 0 2 2h10.2M3 16l4-4 2 2"/></symbol>
        <symbol id="i-unplug" viewBox="0 0 24 24"><path d="m19 5 3-3M2 22l3-3M6.3 20.3a2.4 2.4 0 0 1-3.4 0l-1.2-1.2a2.4 2.4 0 0 1 0-3.4L5 12.4l4.6 4.6ZM18.7 3.7a2.4 2.4 0 0 1 3.4 0l1.2 1.2a2.4 2.4 0 0 1 0 3.4L19 11.6 14.4 7Z"/></symbol>
        <symbol id="i-mountain" viewBox="0 0 24 24"><path d="m8 3 4 8 5-5 5 14H2L8 3z"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    </svg>

    <header>
        <div class="wrap">
            <div class="brand">
                <span class="mark"><svg class="icon"><use href="#i-camera"/></svg></span>
                ลุยเลเขา
            </div>
            <h1 id="albumTitle">อัลบั้มรูปทริป</h1>
            <div class="chips" id="albumChips">
                <span class="chip skeleton" id="chipLoading"></span>
            </div>
        </div>
    </header>

    <div class="toolbar hidden" id="toolbar">
        <div class="wrap">
            <span class="count" id="photoCount"></span>
        </div>
    </div>

    <main class="wrap">
        <div id="loadingState">
            <span class="sr-only">กำลังโหลดรูปภาพ…</span>
            <div class="grid" id="skeletonGrid" aria-hidden="true"></div>
        </div>

        <div id="errorState" class="state hidden">
            <div class="badge"><svg class="icon"><use href="#i-unplug"/></svg></div>
            <h2 id="errorTitle">ไม่พบอัลบั้มนี้</h2>
            <p id="errorMsg">ลิงก์อาจหมดอายุหรือถูกปิดการแชร์แล้ว</p>
        </div>

        <div id="emptyState" class="state hidden">
            <div class="badge"><svg class="icon"><use href="#i-image-off"/></svg></div>
            <h2>ยังไม่มีรูปในอัลบั้มนี้</h2>
            <p>ทีมงานกำลังทยอยอัปโหลด ลองกลับมาใหม่อีกครั้งนะครับ</p>
        </div>

        <div id="content" class="hidden">
            <div id="expiryNotice" class="notice hidden">
                <svg class="icon"><use href="#i-clock"/></svg>
                <span id="expiryText"></span>
            </div>

            <div class="grid" id="grid"></div>
        </div>
    </main>

    <div id="lightbox" class="lightbox hidden" role="dialog" aria-modal="true" aria-label="ดูรูปขนาดใหญ่">
        <div class="lb-top">
            <span class="lb-counter" id="lbCounter"></span>
            <button class="lb-btn lb-close" id="lbClose" aria-label="ปิด"><svg class="icon"><use href="#i-x"/></svg></button>
        </div>
        <button class="lb-btn lb-nav lb-prev" id="lbPrev" aria-label="รูปก่อนหน้า"><svg class="icon"><use href="#i-chevron-left"/></svg></button>
        <img id="lbImg" src="" alt="">
        <button class="lb-btn lb-nav lb-next" id="lbNext" aria-label="รูปถัดไป"><svg class="icon"><use href="#i-chevron-right"/></svg></button>
        <div class="lb-bottom">
            <a class="btn btn-primary" id="lbDownload" href="#">
                <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดรูปนี้
            </a>
        </div>
    </div>

    <footer>
        <svg class="icon"><use href="#i-mountain"/></svg>
        ลุยเลเขา · ภาพกิจกรรมประจำทริป
    </footer>

    <script>
        const TOKEN = @json($token);
        const API_URL = '/api/v1/album/' + encodeURIComponent(TOKEN) + '/photos';
        const DL_ONE = (id) => '/album/' + encodeURIComponent(TOKEN) + '/download/' + id;

        const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        function thaiDate(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d)) return '';
            return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543);
        }

        // เวลาไทยแบบ "12 ก.ค. 2569 เวลา 14:30 น." สำหรับบอกเส้นตายดาวน์โหลด
        function thaiDateTime(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d)) return '';
            const hh = String(d.getHours()).padStart(2, '0');
            const mm = String(d.getMinutes()).padStart(2, '0');
            return thaiDate(iso) + ' เวลา ' + hh + ':' + mm + ' น.';
        }

        const nf = (n) => Number(n || 0).toLocaleString('th-TH');
        function show(id) { document.getElementById(id).classList.remove('hidden'); }
        function hide(id) { document.getElementById(id).classList.add('hidden'); }

        // โครงรูปตอนกำลังโหลด — จำนวนพอดีหนึ่งหน้าจอ ไม่ต้องรู้ว่ามีกี่รูปจริง
        document.getElementById('skeletonGrid').innerHTML =
            Array.from({ length: 12 }, () => '<div class="skel"></div>').join('');

        /* ══════════════════════════════════════════════════
           Lightbox
           ══════════════════════════════════════════════════ */
        let viewList = [];    // ทุกรูปในอัลบั้ม ตามลำดับที่แสดง
        let lbIndex = 0;
        const lightbox = document.getElementById('lightbox');
        const lbImg = document.getElementById('lbImg');
        const lbCounter = document.getElementById('lbCounter');
        const lbDownload = document.getElementById('lbDownload');

        function renderLightbox() {
            const p = viewList[lbIndex];
            if (!p) return;
            lbImg.src = p.url;
            lbImg.alt = 'รูปที่ ' + (lbIndex + 1);
            lbDownload.href = DL_ONE(p.id);
            lbCounter.textContent = (lbIndex + 1) + ' / ' + viewList.length;
            // โหลดรูปข้าง ๆ ไว้ล่วงหน้า กดลูกศรแล้วมาทันที
            [lbIndex - 1, lbIndex + 1].forEach((i) => {
                const n = viewList[(i + viewList.length) % viewList.length];
                if (n && n !== p) new Image().src = n.url;
            });
        }
        function openLightbox(index) {
            lbIndex = index;
            renderLightbox();
            document.body.classList.add('no-scroll');
            show('lightbox');
        }
        function closeLightbox() {
            hide('lightbox');
            document.body.classList.remove('no-scroll');
        }
        function step(delta) {
            lbIndex = (lbIndex + delta + viewList.length) % viewList.length;
            renderLightbox();
        }

        document.getElementById('lbClose').addEventListener('click', closeLightbox);
        document.getElementById('lbPrev').addEventListener('click', (e) => { e.stopPropagation(); step(-1); });
        document.getElementById('lbNext').addEventListener('click', (e) => { e.stopPropagation(); step(1); });
        lightbox.addEventListener('click', (e) => {
            // กดพื้นที่ว่างรอบรูปเพื่อปิด (ตัวรูปเองไม่ปิด จะได้กดค้างเซฟรูปได้)
            if (e.target === lightbox) closeLightbox();
        });
        document.addEventListener('keydown', (e) => {
            if (lightbox.classList.contains('hidden')) return;
            if (e.key === 'Escape') closeLightbox();
            else if (e.key === 'ArrowLeft') step(-1);
            else if (e.key === 'ArrowRight') step(1);
        });
        // Swipe navigation on touch screens.
        let touchX = null;
        lbImg.addEventListener('touchstart', (e) => { touchX = e.changedTouches[0].clientX; }, { passive: true });
        lbImg.addEventListener('touchend', (e) => {
            if (touchX === null) return;
            const dx = e.changedTouches[0].clientX - touchX;
            if (Math.abs(dx) > 40) step(dx < 0 ? 1 : -1);
            touchX = null;
        }, { passive: true });

        /* ══════════════════════════════════════════════════
           ตารางรูป
           ══════════════════════════════════════════════════ */
        const grid = document.getElementById('grid');

        function renderGrid(list) {
            viewList = list;
            grid.innerHTML = list.map((p, i) => `
                <div class="tile" style="animation-delay:${Math.min(i, 11) * 18}ms">
                    <img src="${p.thumb_url || p.url}" alt="" loading="lazy" decoding="async" data-index="${i}">
                    <a class="dl" href="${DL_ONE(p.id)}" aria-label="ดาวน์โหลดรูปนี้">
                        <svg class="icon"><use href="#i-download"/></svg>
                    </a>
                </div>
            `).join('');

            grid.querySelectorAll('img').forEach((img) => {
                img.addEventListener('click', () => openLightbox(Number(img.dataset.index)));
            });
        }

        async function load() {
            try {
                const res = await fetch(API_URL, { headers: { 'Accept': 'application/json' } });
                if (res.status === 404) { failAlbum(); return; }
                if (!res.ok) throw new Error('http ' + res.status);

                const body = await res.json();
                const data = body.data ?? body;

                document.getElementById('albumTitle').textContent = data.trip_title || 'อัลบั้มรูปทริป';
                const photos = data.photos || [];
                hide('loadingState');

                renderChips(data);

                if (!photos.length) { show('emptyState'); return; }

                document.getElementById('photoCount').innerHTML =
                    '<svg class="icon"><use href="#i-images"/></svg> ทั้งหมด ' + nf(photos.length) +
                    ' รูป <span class="dim">· กดที่รูปเพื่อดูเต็มจอและดาวน์โหลดทีละรูป</span>';

                // รูปถูกลบอัตโนมัติหลังอัปโหลดครบกำหนด — บอกเส้นตายให้ชัดก่อนของหาย
                const deadline = thaiDateTime(data.expires_at);
                if (deadline) {
                    const days = data.retention_days || 0;
                    document.getElementById('expiryText').innerHTML =
                        'ระบบจะลบรูปอัตโนมัติหลังอัปโหลด ' + days + ' วัน — ' +
                        'อัลบั้มนี้จะเริ่มถูกลบ <strong>' + deadline + '</strong> ' +
                        'กรุณาดาวน์โหลดรูปของคุณเก็บไว้ก่อนถึงเวลาดังกล่าวนะครับ';
                    show('expiryNotice');
                }

                renderGrid(photos);
                show('toolbar');
                show('content');
            } catch (e) {
                failAlbum('โหลดอัลบั้มไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง');
            }
        }

        // ชิปใต้ชื่ออัลบั้ม: วันเดินทาง / จำนวนรูป / จำนวนคนเข้าดู
        function renderChips(data) {
            const chips = [];
            const dep = thaiDate(data.departure_date);
            const ret = thaiDate(data.return_date);
            if (dep) {
                const range = (ret && ret !== dep) ? dep + ' – ' + ret : dep;
                chips.push('<span class="chip"><svg class="icon"><use href="#i-calendar"/></svg> ' + range + '</span>');
            }
            const count = (data.photos || []).length;
            if (count) {
                chips.push('<span class="chip"><svg class="icon"><use href="#i-images"/></svg> <b>' + nf(count) + '</b> รูป</span>');
            }
            const views = Number(data.views_count || 0);
            if (views > 0) {
                chips.push('<span class="chip"><svg class="icon"><use href="#i-eye"/></svg> <b>' + nf(views) + '</b> คนเข้าดูแล้ว</span>');
            }
            document.getElementById('albumChips').innerHTML = chips.join('');
        }

        // ล้างชิป "กำลังโหลด" ด้วย ไม่ให้ค้างอยู่บนหน้าที่โหลดไม่ขึ้น
        function failAlbum(title, message) {
            hide('loadingState');
            document.getElementById('albumChips').innerHTML = '';
            if (title) document.getElementById('errorTitle').textContent = title;
            if (message) document.getElementById('errorMsg').textContent = message;
            show('errorState');
        }

        load();
    </script>
</body>
</html>
