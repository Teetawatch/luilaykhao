<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#044C4D">
    <title>อัลบั้มรูปทริป | ลุยเลเขา</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}?v={{ filemtime(public_path('fonts/db-heavent/db-heavent.css')) }}">

    <style>
        /* ดีไซน์แบน: ไม่มีเงา ไม่มีไล่สี ใช้เส้นขอบบางแยกพื้นที่แทนทั้งหน้า */
        :root {
            --brand: #087C68;
            --brand-dark: #044C4D;
            --brand-soft: #EEF5F3;
            --ink: #101828;
            --ink-2: #344054;
            --muted: #667085;
            --line: #E4E7E6;
            --line-strong: #D3D9D7;
            --bg: #F6F7F7;
            --surface: #FFFFFF;
            --radius: 10px;
            --radius-sm: 8px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html, body {
            font-family: 'DB Heavent', 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100dvh;
            -webkit-font-smoothing: antialiased;
        }

        svg { display: block; }
        .icon { width: 20px; height: 20px; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; fill: none; }

        /* ── Header ───────────────────────────────────── */
        header {
            background: var(--brand-dark);
            color: #fff;
            padding: clamp(12px, 2.6vw, 15px) clamp(14px, 3vw, 22px);
            padding-top: max(clamp(12px, 2.6vw, 15px), env(safe-area-inset-top));
            position: sticky;
            top: 0;
            z-index: 10;
        }
        /* หัวเรื่องชิดคอลัมน์เดียวกับเนื้อหาด้านล่าง ไม่ลอยไปติดขอบจอ */
        header .head-inner {
            max-width: 1120px; margin: 0 auto;
            display: flex; align-items: center; gap: 12px;
        }
        header .logo {
            width: 36px; height: 36px;
            border-radius: var(--radius-sm);
            background: rgba(255,255,255,0.10);
            border: 1px solid rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        header .logo .icon { width: 19px; height: 19px; }
        header .title { flex: 1; min-width: 0; }
        header .title h1 {
            font-size: clamp(15px, 3.6vw, 17px); font-weight: 700; letter-spacing: -0.01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        header .title p {
            font-size: clamp(11px, 2.8vw, 12.5px); color: rgba(255,255,255,0.70); font-weight: 500;
            margin-top: 2px;
            display: flex; align-items: center; gap: 5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        header .title p .icon { width: 13px; height: 13px; flex-shrink: 0; }

        /* ── Layout ───────────────────────────────────── */
        main { max-width: 1120px; margin: 0 auto; padding: clamp(16px, 3vw, 26px) clamp(14px, 3vw, 22px); }

        .toolbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            padding-bottom: 14px; margin-bottom: 16px;
            border-bottom: 1px solid var(--line);
        }
        .toolbar .count {
            font-size: 13.5px; color: var(--ink-2); font-weight: 600;
            display: inline-flex; align-items: center; gap: 7px;
        }
        .toolbar .count .icon { width: 16px; height: 16px; color: var(--muted); }

        /* แจ้งเตือนวันหมดอายุของอัลบั้ม — รูปถูกลบอัตโนมัติหลังอัปโหลดครบกำหนด */
        .notice {
            display: flex; align-items: flex-start; gap: 10px;
            background: #FFFBF2; border: 1px solid #EFE0C0; border-radius: var(--radius);
            padding: 12px 14px; margin-bottom: 16px;
            font-size: 13.5px; line-height: 1.55; color: #6B4A08;
        }
        .notice .icon { width: 17px; height: 17px; color: #B0791F; flex: none; margin-top: 2px; }
        .notice strong { font-weight: 700; }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 7px;
            border: 1px solid transparent; border-radius: var(--radius-sm);
            padding: 10px 16px; font-size: 13.5px; font-weight: 700;
            font-family: inherit; cursor: pointer; text-decoration: none;
            transition: background 0.14s, border-color 0.14s, color 0.14s;
            white-space: nowrap;
        }
        .btn .icon { width: 17px; height: 17px; }
        .btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .btn-primary { background: var(--brand); color: #fff; }
        .btn-primary:hover, .btn-primary:active { background: var(--brand-dark); }

        /* ── Grid ─────────────────────────────────────── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 150px), 1fr));
            gap: clamp(6px, 1.6vw, 10px);
        }
        @media (min-width: 640px) {
            .grid { grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); }
        }

        .tile {
            position: relative;
            aspect-ratio: 1;
            border-radius: var(--radius);
            overflow: hidden;
            background: #EDEFEE;
            border: 1px solid var(--line);
        }
        .tile img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
            cursor: zoom-in;
        }

        .tile .dl {
            position: absolute; bottom: 8px; right: 8px;
            width: 32px; height: 32px;
            border-radius: var(--radius-sm);
            background: var(--ink);
            color: #fff; text-decoration: none;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.14s, opacity 0.14s;
            opacity: 0;
        }
        .tile:hover .dl, .tile:focus-within .dl { opacity: 1; }
        .tile .dl:hover { background: var(--brand); }
        .tile .dl .icon { width: 17px; height: 17px; }
        /* Touch devices can't hover — keep the button visible. */
        @media (hover: none) {
            .tile .dl { opacity: 1; }
        }

        /* ── States ───────────────────────────────────── */
        .state { text-align: center; padding: clamp(48px, 12vw, 76px) 20px; color: var(--muted); }
        .state .badge {
            width: 56px; height: 56px; margin: 0 auto 16px;
            border-radius: var(--radius); background: var(--surface);
            border: 1px solid var(--line);
            display: flex; align-items: center; justify-content: center;
            color: var(--brand);
        }
        .state .badge .icon { width: 26px; height: 26px; stroke-width: 1.75; }
        .state h2 { font-size: 17px; color: var(--ink); margin-bottom: 6px; font-weight: 700; }
        .state p { font-size: 13.5px; max-width: 360px; margin: 0 auto; line-height: 1.6; }

        .spinner {
            width: 30px; height: 30px; margin: 0 auto 16px;
            border: 2px solid var(--line-strong); border-top-color: var(--brand);
            border-radius: 50%; animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hidden { display: none !important; }

        /* ── Lightbox ─────────────────────────────────── */
        .lightbox {
            position: fixed; inset: 0; z-index: 50;
            background: #0C0F0E;
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            animation: fade 0.14s ease;
        }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
        .lightbox img {
            max-width: 100%; max-height: 82dvh;
            border-radius: 4px;
            user-select: none;
        }
        .lb-btn {
            position: absolute;
            cursor: pointer;
            background: rgba(255,255,255,0.08); color: #fff;
            border: 1px solid rgba(255,255,255,0.18);
            display: flex; align-items: center; justify-content: center;
            border-radius: var(--radius-sm);
            transition: background 0.14s;
        }
        .lb-btn:hover { background: rgba(255,255,255,0.16); }
        .lb-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
        .lb-btn .icon { width: 21px; height: 21px; }
        .lb-close { top: max(16px, env(safe-area-inset-top)); right: 16px; width: 40px; height: 40px; }
        .lb-nav { top: 50%; transform: translateY(-50%); width: 40px; height: 44px; }
        .lb-prev { left: 14px; }
        .lb-next { right: 14px; }
        .lb-counter {
            position: absolute; top: max(20px, env(safe-area-inset-top)); left: 50%; transform: translateX(-50%);
            color: rgba(255,255,255,0.86); font-size: 12.5px; font-weight: 600;
            letter-spacing: 0.02em;
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.16);
            padding: 5px 12px; border-radius: 999px;
        }
        .lb-download {
            position: absolute; bottom: max(20px, env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%);
        }
        @media (max-width: 540px) {
            .lb-nav { width: 36px; height: 40px; }
            .lb-prev { left: 8px; } .lb-next { right: 8px; }
        }

        /* ── ค้นหารูปของฉันด้วยใบหน้า ─────────────────── */
        .toolbar .actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        .btn-ghost { background: var(--surface); color: var(--ink); border-color: var(--line-strong); }
        .btn-ghost:hover { background: var(--bg); border-color: var(--muted); }
        .btn-quiet { background: transparent; color: var(--muted); padding: 9px 10px; }
        .btn-quiet:hover { color: var(--ink); background: var(--bg); }
        .btn:disabled { color: var(--muted); background: var(--bg); border-color: var(--line); cursor: not-allowed; }

        .face-panel {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 14px 16px; margin-bottom: 16px;
        }
        .face-panel .row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .face-panel .texts { flex: 1; min-width: 190px; }
        .face-panel .msg { font-size: 14.5px; font-weight: 700; letter-spacing: -0.005em; }
        .face-panel .sub { font-size: 13px; color: var(--muted); font-weight: 500; margin-top: 3px; line-height: 1.55; }
        .face-panel .selfie {
            width: 40px; height: 40px; border-radius: var(--radius-sm); object-fit: cover;
            border: 1px solid var(--line-strong); flex: none;
        }
        .bar { height: 4px; border-radius: 2px; background: var(--line); overflow: hidden; margin-top: 14px; }
        .bar > i { display: block; height: 100%; width: 0; background: var(--brand); transition: width 0.25s ease; }

        /* ป้าย "คุณ" บนรูปที่ตรงกับใบหน้าที่ค้นหา */
        .tile.match::before {
            content: "คุณ"; position: absolute; top: 8px; left: 8px; z-index: 2;
            background: var(--brand); color: #fff; font-size: 11.5px; font-weight: 700;
            padding: 2px 8px; border-radius: 4px; letter-spacing: 0.02em;
        }

        /* ── Consent modal (PDPA) ─────────────────────── */
        .modal {
            position: fixed; inset: 0; z-index: 60;
            background: rgba(16,24,40,0.55);
            display: flex; align-items: flex-end; justify-content: center;
            animation: fade 0.14s ease;
        }
        @media (min-width: 640px) { .modal { align-items: center; padding: 20px; } }
        .modal .box {
            background: var(--surface); width: 100%; max-width: 540px;
            border-radius: 12px 12px 0 0; padding: 0 0 env(safe-area-inset-bottom);
            max-height: 92dvh; overflow-y: auto;
        }
        @media (min-width: 640px) {
            .modal .box { border: 1px solid var(--line-strong); border-radius: 12px; padding-bottom: 0; }
        }
        /* ส่วนหัว/ท้ายของชีตแยกด้วยเส้นบาง ให้อ่านเป็นเอกสารยินยอมมากกว่าป็อปอัป */
        .modal .head {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 18px 20px; border-bottom: 1px solid var(--line);
        }
        .modal .head .badge {
            width: 36px; height: 36px; border-radius: var(--radius-sm); background: var(--brand-soft);
            border: 1px solid #DCE9E5;
            display: flex; align-items: center; justify-content: center; color: var(--brand); flex: none;
        }
        .modal .head .badge .icon { width: 19px; height: 19px; }
        .modal h3 { font-size: 16.5px; font-weight: 700; letter-spacing: -0.01em; }
        .modal .lead { font-size: 13.5px; color: var(--muted); line-height: 1.55; margin-top: 4px; }
        .modal .body { padding: 18px 20px; }

        .consent-list { list-style: none; display: grid; gap: 14px; margin-bottom: 18px; }
        .consent-list li { display: flex; gap: 10px; font-size: 13.5px; line-height: 1.65; color: var(--ink-2); }
        .consent-list li .icon { width: 17px; height: 17px; color: var(--brand); flex: none; margin-top: 3px; }
        .consent-list li b { font-weight: 700; color: var(--ink); }

        .agree {
            display: flex; gap: 11px; align-items: flex-start; cursor: pointer;
            background: var(--bg); border: 1px solid var(--line-strong); border-radius: var(--radius);
            padding: 13px 14px;
            font-size: 13.5px; line-height: 1.6; font-weight: 600;
        }
        .agree input { width: 18px; height: 18px; accent-color: var(--brand); flex: none; margin-top: 2px; }
        .modal .foot {
            display: flex; gap: 10px;
            padding: 14px 20px; border-top: 1px solid var(--line);
            position: sticky; bottom: 0; background: var(--surface);
        }
        .modal .foot .btn { flex: 1; }
        .legal {
            font-size: 12px; color: var(--muted); line-height: 1.6;
            margin-top: 14px;
        }
        .legal a { color: var(--brand); font-weight: 700; }

        footer {
            border-top: 1px solid var(--line);
            text-align: center; padding: 22px 16px calc(30px + env(safe-area-inset-bottom));
            color: var(--muted); font-size: 12px;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        footer .icon { width: 13px; height: 13px; color: var(--muted); }
    </style>
</head>
<body>
    {{-- Inline SVG icon symbols (Lucide-style) reused across the page --}}
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
        <symbol id="i-camera" viewBox="0 0 24 24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3Z"/><circle cx="12" cy="13" r="3.5"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/></symbol>
        <symbol id="i-images" viewBox="0 0 24 24"><rect x="3" y="3" width="13" height="13" rx="2"/><path d="m7 11 2-2 3 3M21 8v11a2 2 0 0 1-2 2H8"/></symbol>
        <symbol id="i-download" viewBox="0 0 24 24"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></symbol>
        <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></symbol>
        <symbol id="i-chevron-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></symbol>
        <symbol id="i-chevron-right" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></symbol>
        <symbol id="i-image-off" viewBox="0 0 24 24"><path d="M10.4 4H19a2 2 0 0 1 2 2v8.6M21 16v3a2 2 0 0 1-2 2H6.4M3 3l18 18M3 7.8V18a2 2 0 0 0 2 2h10.2M3 16l4-4 2 2"/></symbol>
        <symbol id="i-unplug" viewBox="0 0 24 24"><path d="m19 5 3-3M2 22l3-3M6.3 20.3a2.4 2.4 0 0 1-3.4 0l-1.2-1.2a2.4 2.4 0 0 1 0-3.4L5 12.4l4.6 4.6ZM18.7 3.7a2.4 2.4 0 0 1 3.4 0l1.2 1.2a2.4 2.4 0 0 1 0 3.4L19 11.6 14.4 7Z"/></symbol>
        <symbol id="i-mountain" viewBox="0 0 24 24"><path d="m8 3 4 8 5-5 5 14H2L8 3z"/></symbol>
        <symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
        <symbol id="i-scan-face" viewBox="0 0 24 24"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M9 10h.01M15 10h.01M9 15c.8.7 1.9 1 3 1s2.2-.3 3-1"/></symbol>
        <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 2 4 5.5V11c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5.5L12 2Z"/><path d="m9 12 2 2 4-4"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
        <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
        <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/></symbol>
        <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
        <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></symbol>
        <symbol id="i-user-search" viewBox="0 0 24 24"><circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 8.6-6.8"/><circle cx="17" cy="17" r="3.5"/><path d="m21 21-1.5-1.5"/></symbol>
    </svg>

    <header>
        <div class="head-inner">
            <div class="logo"><svg class="icon"><use href="#i-camera"/></svg></div>
            <div class="title">
                <h1 id="albumTitle">อัลบั้มรูปทริป</h1>
                <p id="albumMeta">กำลังโหลด…</p>
            </div>
        </div>
    </header>

    <main>
        <div id="loadingState" class="state">
            <div class="spinner"></div>
            <p>กำลังโหลดรูปภาพ…</p>
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
            <div class="toolbar">
                <span class="count" id="photoCount"><svg class="icon"><use href="#i-images"/></svg></span>
                <div class="actions">
                    <button class="btn btn-ghost" id="faceSearchBtn" type="button">
                        <svg class="icon"><use href="#i-scan-face"/></svg> ค้นหารูปของฉัน
                    </button>
                    <a class="btn btn-primary" id="downloadAll" href="#">
                        <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดทั้งหมด
                    </a>
                </div>
            </div>

            {{-- แถบสถานะ/ผลลัพธ์ของการค้นหาด้วยใบหน้า (ทำงานบนเครื่องลูกค้าทั้งหมด) --}}
            <div class="face-panel hidden" id="facePanel">
                <div class="row">
                    <img class="selfie hidden" id="selfiePreview" alt="">
                    <div class="texts">
                        <div class="msg" id="faceMsg"></div>
                        <div class="sub" id="faceSub"></div>
                    </div>
                    <div class="actions">
                        <button class="btn btn-quiet hidden" id="faceCancel" type="button">ยกเลิก</button>
                        <button class="btn btn-ghost hidden" id="faceShowAll" type="button">
                            <svg class="icon"><use href="#i-grid"/></svg> <span id="faceShowAllText">แสดงทุกรูป</span>
                        </button>
                        <a class="btn btn-primary hidden" id="faceDownload" href="#">
                            <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดรูปของฉัน
                        </a>
                        <button class="btn btn-quiet hidden" id="faceReset" type="button">
                            <svg class="icon"><use href="#i-trash"/></svg> ล้างข้อมูลใบหน้า
                        </button>
                    </div>
                </div>
                <div class="bar hidden" id="faceBar"><i id="faceBarFill"></i></div>
            </div>

            <div class="grid" id="grid"></div>
        </div>
    </main>

    <input type="file" id="selfieInput" accept="image/*" capture="user" class="hidden">

    {{-- ความยินยอมตาม PDPA ก่อนประมวลผลข้อมูลใบหน้า (ม.26 ข้อมูลชีวมาตร) --}}
    <div class="modal hidden" id="consentModal" role="dialog" aria-modal="true" aria-labelledby="consentTitle">
        <div class="box">
            <div class="head">
                <div class="badge"><svg class="icon"><use href="#i-user-search"/></svg></div>
                <div>
                    <h3 id="consentTitle">ค้นหารูปของคุณด้วยใบหน้า</h3>
                    <p class="lead">เลือกรูปหน้าตรงของคุณ 1 รูป ระบบจะคัดเฉพาะรูปในอัลบั้มนี้ที่มีคุณอยู่ให้อัตโนมัติ</p>
                </div>
            </div>

            <div class="body">
            <ul class="consent-list">
                <li>
                    <svg class="icon"><use href="#i-lock"/></svg>
                    <span><b>รูปใบหน้าของคุณไม่ถูกอัปโหลด</b> การเปรียบเทียบใบหน้าทั้งหมดเกิดขึ้นในเบราว์เซอร์บนเครื่องของคุณ ลุยเลเขาไม่ได้รับและไม่ได้เก็บภาพใบหน้าหรือข้อมูลชีวมาตรของคุณ</span>
                </li>
                <li>
                    <svg class="icon"><use href="#i-shield"/></svg>
                    <span>เพื่อให้ค้นหาครั้งถัดไปเร็วขึ้น ระบบจะเก็บ<b>ค่าตัวเลขของใบหน้าในรูปอัลบั้มนี้ไว้ในเบราว์เซอร์ของคุณเอง</b> กด “ล้างข้อมูลใบหน้า” เพื่อลบทิ้งได้ทุกเมื่อ ส่วนรูปใบหน้าที่คุณเลือกจะหายไปเมื่อปิดหน้านี้</span>
                </li>
                <li>
                    <svg class="icon"><use href="#i-info"/></svg>
                    <span>ผลการค้นหาเป็นเพียงตัวช่วยคัดกรอง <b>อาจคลาดเคลื่อนได้</b> กรุณาตรวจสอบรูปก่อนดาวน์โหลด และยังกด “แสดงทุกรูป” เพื่อดูทั้งอัลบั้มได้ตามปกติ</span>
                </li>
                <li>
                    <svg class="icon"><use href="#i-check-circle"/></svg>
                    <span>ข้อมูลใบหน้าเป็น<b>ข้อมูลชีวมาตรตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล (PDPA) มาตรา 26</b> จึงต้องขอความยินยอมโดยชัดแจ้ง คุณเลือกไม่ใช้ฟีเจอร์นี้ได้โดยไม่กระทบสิทธิ์ใด ๆ และยังดาวน์โหลดรูปทั้งอัลบั้มได้เหมือนเดิม</span>
                </li>
            </ul>

            <label class="agree" for="consentCheck">
                <input type="checkbox" id="consentCheck">
                <span>ข้าพเจ้าอ่านและเข้าใจข้อความข้างต้น และ<b>ยินยอม</b>ให้ประมวลผลข้อมูลใบหน้าของข้าพเจ้าบนเครื่องนี้ เพื่อค้นหารูปของข้าพเจ้าในอัลบั้มนี้</span>
            </label>

            <p class="legal">
                เราบันทึกเฉพาะหลักฐานการให้ความยินยอม (วัน-เวลา เวอร์ชันข้อความ และหมายเลข IP) ตามที่กฎหมายกำหนด โดยไม่มีภาพใบหน้าใด ๆ
                ถอนความยินยอมได้ทุกเมื่อด้วยปุ่ม “ล้างข้อมูลใบหน้า” ผู้ใช้ที่อายุต่ำกว่า 20 ปีควรได้รับความยินยอมจากผู้ปกครองก่อนใช้ฟีเจอร์นี้
                · <a href="/privacy" target="_blank" rel="noopener">นโยบายความเป็นส่วนตัว</a>
            </p>
            </div>

            <div class="foot">
                <button class="btn btn-ghost" id="consentCancel" type="button">ไม่ใช่ตอนนี้</button>
                <button class="btn btn-primary" id="consentAccept" type="button" disabled>
                    <svg class="icon"><use href="#i-scan-face"/></svg> ยินยอมและเลือกรูป
                </button>
            </div>
        </div>
    </div>

    <div id="lightbox" class="lightbox hidden">
        <span class="lb-counter" id="lbCounter"></span>
        <button class="lb-btn lb-close" id="lbClose" aria-label="ปิด"><svg class="icon"><use href="#i-x"/></svg></button>
        <button class="lb-btn lb-nav lb-prev" id="lbPrev" aria-label="รูปก่อนหน้า"><svg class="icon"><use href="#i-chevron-left"/></svg></button>
        <img id="lbImg" src="" alt="">
        <button class="lb-btn lb-nav lb-next" id="lbNext" aria-label="รูปถัดไป"><svg class="icon"><use href="#i-chevron-right"/></svg></button>
        <a class="btn btn-primary lb-download" id="lbDownload" href="#">
            <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดรูปนี้
        </a>
    </div>

    <footer>
        <svg class="icon"><use href="#i-mountain"/></svg>
        ลุยเลเขา · ภาพกิจกรรมประจำทริป
    </footer>

    <script>
        const TOKEN = @json($token);
        const API_URL = '/api/v1/album/' + encodeURIComponent(TOKEN) + '/photos';
        const CONSENT_URL = '/api/v1/album/' + encodeURIComponent(TOKEN) + '/face-consent';
        const DL_ALL = '/album/' + encodeURIComponent(TOKEN) + '/download';
        const DL_ONE = (id) => '/album/' + encodeURIComponent(TOKEN) + '/download/' + id;
        // รูปจากโดเมนเดียวกัน — ใช้เมื่ออ่านรูปจาก R2 ลง canvas ไม่ได้เพราะ CORS
        const PROXY_ONE = (id) => '/album/' + encodeURIComponent(TOKEN) + '/photo/' + id;

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

        function show(id) { document.getElementById(id).classList.remove('hidden'); }
        function hide(id) { document.getElementById(id).classList.add('hidden'); }

        // ── Lightbox with prev/next navigation ──────────
        let photoList = [];   // ทุกรูปในอัลบั้ม
        let viewList = [];    // ชุดที่กำลังแสดงอยู่ (ทั้งหมด หรือเฉพาะรูปที่เจอใบหน้าคุณ)
        let lbIndex = 0;
        const lbImg = document.getElementById('lbImg');
        const lbCounter = document.getElementById('lbCounter');
        const lbDownload = document.getElementById('lbDownload');

        function renderLightbox() {
            const p = viewList[lbIndex];
            if (!p) return;
            lbImg.src = p.url;
            lbDownload.href = DL_ONE(p.id);
            lbCounter.textContent = (lbIndex + 1) + ' / ' + viewList.length;
        }
        function openLightbox(index) {
            lbIndex = index;
            renderLightbox();
            show('lightbox');
        }
        function step(delta) {
            lbIndex = (lbIndex + delta + viewList.length) % viewList.length;
            renderLightbox();
        }

        document.getElementById('lbClose').addEventListener('click', () => hide('lightbox'));
        document.getElementById('lbPrev').addEventListener('click', (e) => { e.stopPropagation(); step(-1); });
        document.getElementById('lbNext').addEventListener('click', (e) => { e.stopPropagation(); step(1); });
        document.getElementById('lightbox').addEventListener('click', (e) => {
            if (e.target.id === 'lightbox') hide('lightbox');
        });
        document.addEventListener('keydown', (e) => {
            if (document.getElementById('lightbox').classList.contains('hidden')) return;
            if (e.key === 'Escape') hide('lightbox');
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

        // แสดงรูปตามชุดที่ส่งเข้ามา — ใช้ทั้งตอนโหลดครบทุกรูปและตอนกรองด้วยใบหน้า
        function renderGrid(list, matched = false) {
            viewList = list;
            const grid = document.getElementById('grid');
            grid.innerHTML = list.map((p, i) => `
                <div class="tile${matched ? ' match' : ''}">
                    <img src="${p.thumb_url || p.url}" alt="" loading="lazy" data-index="${i}">
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
                const dep = thaiDate(data.departure_date);
                const meta = document.getElementById('albumMeta');
                if (dep) {
                    meta.innerHTML = '<svg class="icon"><use href="#i-calendar"/></svg> เดินทาง ' + dep;
                } else {
                    meta.textContent = 'ภาพกิจกรรมประจำทริป';
                }

                photoList = data.photos || [];
                hide('loadingState');

                if (!photoList.length) { show('emptyState'); return; }

                document.getElementById('photoCount').innerHTML =
                    '<svg class="icon"><use href="#i-images"/></svg> ทั้งหมด ' + photoList.length + ' รูป';
                document.getElementById('downloadAll').href = DL_ALL;

                // รูปถูกลบอัตโนมัติหลังอัปโหลดครบกำหนด — บอกเส้นตายให้ชัดก่อนของหาย
                const deadline = thaiDateTime(data.expires_at);
                if (deadline) {
                    const days = data.retention_days || 0;
                    document.getElementById('expiryText').innerHTML =
                        'ระบบจะลบรูปอัตโนมัติหลังอัปโหลด ' + days + ' วัน — ' +
                        'อัลบั้มนี้จะเริ่มถูกลบ <strong>' + deadline + '</strong> ' +
                        'กรุณาดาวน์โหลดเก็บไว้ก่อนถึงเวลาดังกล่าวนะครับ';
                    show('expiryNotice');
                }

                renderGrid(photoList);
                show('content');
            } catch (e) {
                failAlbum('โหลดอัลบั้มไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง');
            }
        }

        // ล้างคำว่า "กำลังโหลด…" ใต้ชื่อเรื่องด้วย ไม่ให้ค้างอยู่บนหน้าที่โหลดไม่ขึ้น
        function failAlbum(title, message) {
            hide('loadingState');
            document.getElementById('albumMeta').textContent = '';
            if (title) document.getElementById('errorTitle').textContent = title;
            if (message) document.getElementById('errorMsg').textContent = message;
            show('errorState');
        }

        /* ══════════════════════════════════════════════════════════════════
           ค้นหารูปของฉันด้วยใบหน้า

           ทุกอย่างทำงานในเบราว์เซอร์: รูปใบหน้าที่ลูกค้าเลือกและเวกเตอร์ใบหน้า
           ไม่เคยถูกส่งขึ้นเซิร์ฟเวอร์ สิ่งเดียวที่ส่งไปคือ "หลักฐานการให้ความ
           ยินยอม" ตาม PDPA (ดู App\Models\FaceSearchConsent)
           ══════════════════════════════════════════════════════════════════ */
        const FACE_SCRIPT_URL = '/vendor/face-api/face-api.js';
        const FACE_MODEL_URL = '/vendor/face-api/model';
        // ระยะห่างของเวกเตอร์ใบหน้าที่ยังนับว่าเป็นคนเดียวกัน (ยิ่งน้อยยิ่งเข้มงวด)
        // วัดกับรูปจริง: คนเดียวกันคนละรูปอยู่ราว 0.41–0.62 (ห่างกัน 9 ปี/มุมต่างกันมาก)
        // ส่วนคนละคนอยู่ที่ 0.80 ขึ้นไป จึงใช้ 0.6 ตามค่ามาตรฐานของ face-api
        // ฝั่ง "หาไม่เจอ" เสียหายกว่า "เจอเกิน" เพราะลูกค้าเห็นทั้งอัลบั้มอยู่แล้ว
        const FACE_THRESHOLD = 0.6;
        const CONSENT_VERSION = @json($consentVersion);
        const CONSENT_STORE_KEY = 'llk_face_consent_' + TOKEN;
        const SUBJECT_STORE_KEY = 'llk_face_subject';
        const CACHE_DB = 'llk-face-cache';
        const CACHE_STORE = 'descriptors';

        const facePanel = document.getElementById('facePanel');
        const faceMsg = document.getElementById('faceMsg');
        const faceSub = document.getElementById('faceSub');
        const faceBar = document.getElementById('faceBar');
        const faceBarFill = document.getElementById('faceBarFill');
        const selfiePreview = document.getElementById('selfiePreview');
        const selfieInput = document.getElementById('selfieInput');
        const btnFaceSearch = document.getElementById('faceSearchBtn');
        const btnFaceCancel = document.getElementById('faceCancel');
        const btnFaceShowAll = document.getElementById('faceShowAll');
        const btnFaceDownload = document.getElementById('faceDownload');
        const btnFaceReset = document.getElementById('faceReset');
        const consentModal = document.getElementById('consentModal');
        const consentCheck = document.getElementById('consentCheck');
        const consentAccept = document.getElementById('consentAccept');
        const consentCancel = document.getElementById('consentCancel');

        let modelsReady = false;
        let selfieDescriptor = null;
        let selfieUrl = null;
        let matchedIds = [];
        let showingMatches = false;
        let cancelled = false;
        let cacheDb = null;

        /* ── localStorage แบบไม่พังในโหมดส่วนตัว ─────────── */
        function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
        function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
        function lsDel(k) { try { localStorage.removeItem(k); } catch (e) {} }

        function uuidv4() {
            if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
            return '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) =>
                (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));
        }

        // รหัสสุ่มประจำเบราว์เซอร์ ใช้จับคู่ตอนถอนความยินยอม — ไม่ผูกกับตัวตนลูกค้า
        function subjectKey() {
            let key = lsGet(SUBJECT_STORE_KEY);
            if (!key) { key = uuidv4(); lsSet(SUBJECT_STORE_KEY, key); }
            return key;
        }

        function hasConsent() { return lsGet(CONSENT_STORE_KEY) === CONSENT_VERSION; }

        /* ── แคชเวกเตอร์ใบหน้าของรูปในอัลบั้ม (เก็บในเครื่องลูกค้า) ── */
        function openCache() {
            return new Promise((resolve) => {
                if (!('indexedDB' in window)) return resolve(null);
                let req;
                try { req = indexedDB.open(CACHE_DB, 1); } catch (e) { return resolve(null); }
                req.onupgradeneeded = () => {
                    const db = req.result;
                    if (!db.objectStoreNames.contains(CACHE_STORE)) db.createObjectStore(CACHE_STORE);
                };
                req.onsuccess = () => resolve(req.result);
                req.onerror = () => resolve(null);
            });
        }
        const cacheKey = (id) => TOKEN + ':' + id;

        function cacheGet(id) {
            return new Promise((resolve) => {
                if (!cacheDb) return resolve(null);
                try {
                    const r = cacheDb.transaction(CACHE_STORE, 'readonly').objectStore(CACHE_STORE).get(cacheKey(id));
                    r.onsuccess = () => resolve(r.result || null);
                    r.onerror = () => resolve(null);
                } catch (e) { resolve(null); }
            });
        }

        function cachePut(id, faces) {
            return new Promise((resolve) => {
                if (!cacheDb) return resolve();
                try {
                    const tx = cacheDb.transaction(CACHE_STORE, 'readwrite');
                    tx.objectStore(CACHE_STORE).put({ faces: faces, at: Date.now() }, cacheKey(id));
                    tx.oncomplete = () => resolve();
                    tx.onerror = () => resolve();
                } catch (e) { resolve(); }
            });
        }

        // ลบเฉพาะข้อมูลของอัลบั้มนี้ — เรียกตอนลูกค้ากด "ล้างข้อมูลใบหน้า"
        function cacheClear() {
            return new Promise((resolve) => {
                if (!cacheDb) return resolve();
                try {
                    const tx = cacheDb.transaction(CACHE_STORE, 'readwrite');
                    const store = tx.objectStore(CACHE_STORE);
                    const upper = TOKEN + ':' + String.fromCharCode(0xFFFF);
                    const cursor = store.openCursor(IDBKeyRange.bound(TOKEN + ':', upper));
                    cursor.onsuccess = () => {
                        const c = cursor.result;
                        if (c) { c.delete(); c.continue(); }
                    };
                    tx.oncomplete = () => resolve();
                    tx.onerror = () => resolve();
                } catch (e) { resolve(); }
            });
        }

        /* ── สถานะบนแถบผลลัพธ์ ───────────────────────────── */
        function setStatus(msg, sub) {
            faceMsg.textContent = msg;
            faceSub.textContent = sub || '';
        }
        function setProgress(ratio) {
            faceBar.classList.remove('hidden');
            faceBarFill.style.width = Math.round(ratio * 100) + '%';
        }
        function setFaceButtons(opts) {
            const map = {
                cancel: btnFaceCancel, showAll: btnFaceShowAll,
                download: btnFaceDownload, reset: btnFaceReset,
            };
            Object.keys(map).forEach((k) => map[k].classList.toggle('hidden', !opts[k]));
        }
        function faceFail(msg, sub) {
            faceBar.classList.add('hidden');
            setStatus(msg, sub);
            setFaceButtons({ reset: true });
            btnFaceSearch.disabled = false;
        }

        /* ── โหลด face-api + โมเดล (ครั้งแรกเท่านั้น) ────── */
        function loadScript(src) {
            return new Promise((resolve, reject) => {
                if (window.faceapi) return resolve();
                const s = document.createElement('script');
                s.src = src;
                s.onload = () => resolve();
                s.onerror = () => reject(new Error('script'));
                document.head.appendChild(s);
            });
        }
        async function ensureModels() {
            if (modelsReady) return;
            await loadScript(FACE_SCRIPT_URL);
            await Promise.all([
                faceapi.nets.tinyFaceDetector.loadFromUri(FACE_MODEL_URL),
                faceapi.nets.faceLandmark68Net.loadFromUri(FACE_MODEL_URL),
                faceapi.nets.faceRecognitionNet.loadFromUri(FACE_MODEL_URL),
            ]);
            modelsReady = true;
        }
        const detectorOptions = (size) =>
            new faceapi.TinyFaceDetectorOptions({ inputSize: size, scoreThreshold: 0.4 });

        function euclidean(a, b) {
            let sum = 0;
            for (let i = 0; i < b.length; i++) { const d = a[i] - b[i]; sum += d * d; }
            return Math.sqrt(sum);
        }
        const yieldToUi = () => new Promise((r) => setTimeout(r, 0));

        // รูปอยู่บน R2 (คนละโดเมน) — ถ้าอ่านลง canvas ไม่ได้เพราะ CORS ให้ถอยไปใช้
        // เส้นทางเดียวกับหน้าอัลบั้มแทน
        function loadPhotoImage(p) {
            return new Promise((resolve) => {
                const attempt = (src, cors) => {
                    const img = new Image();
                    if (cors) img.crossOrigin = 'anonymous';
                    img.onload = () => resolve(img);
                    img.onerror = () => (cors ? attempt(PROXY_ONE(p.id), false) : resolve(null));
                    img.src = src;
                };
                attempt(p.thumb_url || p.url, true);
            });
        }

        function readLocalImage(file) {
            return new Promise((resolve, reject) => {
                const url = URL.createObjectURL(file);
                const img = new Image();
                img.onload = () => resolve({ img: img, url: url });
                img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('image')); };
                img.src = url;
            });
        }

        /* ── ขั้นตอนหลัก ────────────────────────────────── */
        async function runFaceSearch(file) {
            cancelled = false;
            btnFaceSearch.disabled = true;
            show('facePanel');
            setFaceButtons({ cancel: true });
            setStatus('กำลังเตรียมตัวช่วยค้นหา…', 'ครั้งแรกอาจใช้เวลาสักครู่ ตัวตรวจจับใบหน้าทำงานบนเครื่องคุณ');

            try {
                if (!cacheDb) cacheDb = await openCache();
                await ensureModels();
                if (cancelled) return stopSearch();

                setStatus('กำลังอ่านใบหน้าจากรูปที่คุณเลือก…', 'รูปนี้อยู่ในเครื่องคุณเท่านั้น ไม่ถูกอัปโหลด');
                if (selfieUrl) URL.revokeObjectURL(selfieUrl);
                const picked = await readLocalImage(file);
                selfieUrl = picked.url;
                selfiePreview.src = selfieUrl;
                selfiePreview.classList.remove('hidden');

                const me = await faceapi
                    .detectSingleFace(picked.img, detectorOptions(416))
                    .withFaceLandmarks()
                    .withFaceDescriptor();

                if (!me) {
                    faceFail('ไม่พบใบหน้าในรูปที่เลือก',
                        'ลองใช้รูปหน้าตรงที่เห็นใบหน้าชัด ไม่ใส่แว่นกันแดดหรือหน้ากาก แล้วกด “ค้นหารูปของฉัน” อีกครั้ง');
                    return;
                }
                selfieDescriptor = me.descriptor;
                if (cancelled) return stopSearch();

                await indexAndMatch();
            } catch (e) {
                faceFail('ค้นหาไม่สำเร็จ', 'กรุณาลองใหม่อีกครั้ง หรือเปิดหน้านี้บนเบราว์เซอร์รุ่นใหม่กว่านี้');
            } finally {
                btnFaceSearch.disabled = false;
                selfieInput.value = '';
            }
        }

        async function indexAndMatch() {
            const matches = [];
            setProgress(0);

            for (let i = 0; i < photoList.length; i++) {
                if (cancelled) break;
                const p = photoList[i];
                setStatus('กำลังค้นหารูปของคุณ ' + (i + 1) + ' / ' + photoList.length + ' รูป',
                    matches.length ? ('พบแล้ว ' + matches.length + ' รูป') : 'ยังไม่พบรูปที่ตรงกัน');
                setProgress((i + 1) / photoList.length);

                let faces = null;
                const cached = await cacheGet(p.id);
                if (cached && Array.isArray(cached.faces)) faces = cached.faces;

                if (!faces) {
                    const img = await loadPhotoImage(p);
                    faces = [];
                    if (img) {
                        try {
                            const found = await faceapi
                                .detectAllFaces(img, detectorOptions(512))
                                .withFaceLandmarks()
                                .withFaceDescriptors();
                            faces = found.map((f) => Array.from(f.descriptor));
                        } catch (e) { faces = []; }
                    }
                    await cachePut(p.id, faces);
                }

                if (faces.some((f) => euclidean(f, selfieDescriptor) <= FACE_THRESHOLD)) {
                    matches.push(p);
                    showingMatches = true;
                    renderGrid(matches, true); // ทยอยโชว์ผลระหว่างค้นหา
                }

                await yieldToUi(); // คืน main thread ให้หน้าเว็บไม่ค้าง
            }

            faceBar.classList.add('hidden');
            matchedIds = matches.map((p) => p.id);

            if (!matches.length) {
                showingMatches = false;
                renderGrid(photoList);
                setStatus(cancelled ? 'หยุดการค้นหาแล้ว' : 'ไม่พบรูปที่มีใบหน้าของคุณ',
                    'อาจเป็นเพราะรูปถ่ายไกลหรือเห็นหน้าไม่ชัด ลองเลือกรูปใบหน้าอื่นแล้วค้นหาใหม่ได้เลย');
                setFaceButtons({ reset: true });
                return;
            }

            showingMatches = true;
            renderGrid(matches, true);
            document.getElementById('faceShowAllText').textContent = 'แสดงทุกรูป';
            btnFaceDownload.href = DL_ALL + '?ids=' + matchedIds.join(',');
            setStatus((cancelled ? 'หยุดที่ ' : 'พบ ') + matches.length + ' รูปที่น่าจะมีคุณอยู่',
                'ระบบอาจคัดพลาดได้บ้าง ตรวจสอบรูปก่อนดาวน์โหลดนะครับ');
            setFaceButtons({ showAll: true, download: true, reset: true });
        }

        function stopSearch() {
            faceBar.classList.add('hidden');
            setStatus('หยุดการค้นหาแล้ว', '');
            setFaceButtons({ reset: true });
        }

        /* ── ล้างข้อมูล + ถอนความยินยอม ─────────────────── */
        async function clearFaceData() {
            cancelled = true;
            selfieDescriptor = null;
            matchedIds = [];
            showingMatches = false;
            if (selfieUrl) { URL.revokeObjectURL(selfieUrl); selfieUrl = null; }
            selfiePreview.src = '';
            selfiePreview.classList.add('hidden');
            selfieInput.value = '';

            await cacheClear();
            const key = lsGet(SUBJECT_STORE_KEY);
            lsDel(CONSENT_STORE_KEY);
            if (key) {
                fetch(CONSENT_URL, {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ subject_key: key }),
                }).catch(() => {});
            }

            renderGrid(photoList);
            faceBar.classList.add('hidden');
            setStatus('ล้างข้อมูลใบหน้าในเครื่องนี้แล้ว', 'ถอนความยินยอมเรียบร้อย เริ่มใหม่ได้ทุกเมื่อ');
            setFaceButtons({});
            setTimeout(() => hide('facePanel'), 5000);
        }

        /* ── ความยินยอม PDPA ────────────────────────────── */
        function recordConsent() {
            return fetch(CONSENT_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    subject_key: subjectKey(),
                    consent_version: CONSENT_VERSION,
                    accepted: true,
                }),
            });
        }

        btnFaceSearch.addEventListener('click', () => {
            if (hasConsent()) { selfieInput.click(); return; }
            consentCheck.checked = false;
            consentAccept.disabled = true;
            show('consentModal');
        });

        consentCheck.addEventListener('change', () => {
            consentAccept.disabled = !consentCheck.checked;
        });
        consentCancel.addEventListener('click', () => hide('consentModal'));
        consentModal.addEventListener('click', (e) => {
            if (e.target === consentModal) hide('consentModal');
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !consentModal.classList.contains('hidden')) hide('consentModal');
        });

        consentAccept.addEventListener('click', () => {
            if (!consentCheck.checked) return;
            lsSet(CONSENT_STORE_KEY, CONSENT_VERSION);
            hide('consentModal');

            // ยิงบันทึกความยินยอมแบบไม่รอผล เพื่อให้ตัวเลือกไฟล์ยังอยู่ใน user gesture
            // (Safari บล็อกการเปิดหน้าต่างเลือกไฟล์ถ้ารอ await ก่อน)
            recordConsent()
                .then((res) => {
                    if (res.status === 409) { // ข้อความยินยอมถูกแก้ระหว่างเปิดหน้าค้างไว้
                        cancelled = true;
                        lsDel(CONSENT_STORE_KEY);
                        show('facePanel');
                        faceFail('ข้อความขอความยินยอมมีการอัปเดต', 'กรุณารีเฟรชหน้านี้แล้วยินยอมอีกครั้งนะครับ');
                    }
                })
                .catch(() => {});

            selfieInput.click();
        });

        selfieInput.addEventListener('change', () => {
            const file = selfieInput.files && selfieInput.files[0];
            if (file) runFaceSearch(file);
        });

        btnFaceCancel.addEventListener('click', () => { cancelled = true; });
        btnFaceReset.addEventListener('click', clearFaceData);
        btnFaceShowAll.addEventListener('click', () => {
            showingMatches = !showingMatches;
            const list = showingMatches ? photoList.filter((p) => matchedIds.includes(p.id)) : photoList;
            renderGrid(list, showingMatches);
            document.getElementById('faceShowAllText').textContent =
                showingMatches ? 'แสดงทุกรูป' : 'แสดงเฉพาะรูปของฉัน';
        });

        load();
    </script>
</body>
</html>
