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
            --brand-soft: #EEF5F3;
            --brand-soft-line: #D9E8E3;
            --header-bg: #044C4D;
            --header-line: rgba(255,255,255,0.14);
            --header-soft: rgba(255,255,255,0.08);
            --header-text: rgba(255,255,255,0.72);

            --ink: #101828;
            --ink-2: #344054;
            --muted: #667085;
            --line: #E4E7E6;
            --line-strong: #D3D9D7;
            --bg: #F6F7F7;
            --surface: #FFFFFF;
            --tile-bg: #E9ECEB;

            --notice-bg: #FFFBF2;
            --notice-line: #EFE0C0;
            --notice-ink: #6B4A08;
            --notice-icon: #B0791F;

            --radius: 14px;
            --radius-sm: 10px;
            --radius-pill: 999px;
            --bar-h: 58px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --brand: #14A187;
                --brand-ink: #04201B;
                --brand-soft: #12211E;
                --brand-soft-line: #21372F;
                --header-bg: #08201E;
                --header-line: rgba(255,255,255,0.10);
                --header-soft: rgba(255,255,255,0.06);
                --header-text: rgba(255,255,255,0.66);

                --ink: #F1F4F3;
                --ink-2: #C6CFCC;
                --muted: #8D9995;
                --line: #232A28;
                --line-strong: #333D3A;
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
            display: inline-flex; align-items: center; gap: 7px; flex: none;
        }
        .toolbar .count .icon { width: 17px; height: 17px; color: var(--muted); }
        .toolbar .count .dim { color: var(--muted); font-weight: 600; }
        /* แถบปุ่มเลื่อนแนวนอนได้บนจอแคบ ไม่ตัดปุ่มทิ้งและไม่ดันบรรทัดใหม่ */
        .toolbar .actions {
            margin-left: auto;
            display: flex; align-items: center; gap: 8px;
            overflow-x: auto; scrollbar-width: none;
            padding-inline: 2px; margin-inline: -2px;
        }
        .toolbar .actions::-webkit-scrollbar { display: none; }

        @media (max-width: 560px) {
            .toolbar .wrap { flex-wrap: wrap; }
            .toolbar .count { width: 100%; }
            .toolbar .actions { margin-left: 0; width: 100%; }
            .toolbar .actions .btn { flex: none; }
        }

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
        .btn-ghost { background: var(--surface); color: var(--ink); border-color: var(--line-strong); }
        .btn-ghost:hover { border-color: var(--muted); }
        .btn-ghost.on { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
        .btn-quiet { background: transparent; color: var(--muted); padding: 9px 12px; }
        .btn-quiet:hover { color: var(--ink); background: var(--surface); }
        .btn:disabled { color: var(--muted); background: var(--bg); border-color: var(--line); cursor: not-allowed; }
        .btn:disabled:active { transform: none; }
        .btn-sm { padding: 8px 13px; font-size: 13px; }

        /* ── Layout ───────────────────────────────────── */
        main { padding-block: clamp(16px, 3vw, 24px) clamp(40px, 8vw, 72px); }
        /* เว้นที่ให้แถบล่างตอนเลือกรูป ไม่ให้บังรูปแถวสุดท้าย */
        body.picking main { padding-bottom: 128px; }

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

        /* ป้าย "คุณ" บนรูปที่ตรงกับใบหน้าที่ค้นหา */
        .tile.match::before {
            content: "คุณ"; position: absolute; top: 8px; left: 8px; z-index: 2;
            background: var(--brand); color: var(--brand-ink); font-size: 11.5px; font-weight: 800;
            padding: 3px 9px; border-radius: var(--radius-pill); letter-spacing: 0.02em;
        }

        /* โหมดเลือกรูป — กดที่รูปเพื่อติ๊ก แล้วดาวน์โหลดเฉพาะที่เลือก */
        .tile .pick {
            position: absolute; inset: 0; z-index: 3;
            border: 0; background: transparent; cursor: pointer;
            display: none;
        }
        .grid.selecting .tile .pick { display: block; }
        .grid.selecting .tile img { cursor: pointer; }
        .grid.selecting .tile .dl { display: none; }
        .tile .mark {
            position: absolute; top: 8px; right: 8px; z-index: 4;
            width: 26px; height: 26px; border-radius: var(--radius-pill);
            border: 2px solid rgba(255,255,255,0.9);
            background: rgba(12,15,14,0.35);
            display: none; align-items: center; justify-content: center;
            color: transparent; pointer-events: none;
        }
        .grid.selecting .tile .mark { display: flex; }
        .tile .mark .icon { width: 15px; height: 15px; stroke-width: 3; }
        .grid.selecting .tile:hover img { transform: none; }
        .tile.on { border-color: var(--brand); }
        .grid.selecting .tile.on img,
        .grid.selecting .tile.on:hover img { transform: scale(0.92); border-radius: var(--radius); }
        .tile.on .mark { background: var(--brand); border-color: var(--brand); color: var(--brand-ink); }

        /* ── แถบล่างตอนเลือกรูป ───────────────────────── */
        .selectbar {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 30;
            background: var(--surface); border-top: 1px solid var(--line);
            padding: 12px clamp(14px, 3.4vw, 28px) calc(12px + env(safe-area-inset-bottom));
            animation: slideUp 0.2s ease;
        }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: none; } }
        .selectbar .inner {
            max-width: 1240px; margin: 0 auto;
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
        }
        .selectbar .n { font-size: 14px; font-weight: 800; flex: 1; min-width: 120px; }
        .selectbar .n span { color: var(--brand); }

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

        /* ── ค้นหารูปของฉันด้วยใบหน้า ─────────────────── */
        .face-panel {
            background: var(--surface); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 14px 16px; margin-bottom: 16px;
        }
        .face-panel .row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .face-panel .texts { flex: 1; min-width: 190px; }
        .face-panel .msg { font-size: 15px; font-weight: 800; letter-spacing: -0.005em; }
        .face-panel .sub { font-size: 13.5px; color: var(--muted); font-weight: 500; margin-top: 3px; line-height: 1.55; }
        .face-panel .selfie {
            width: 44px; height: 44px; border-radius: var(--radius-sm); object-fit: cover;
            border: 1px solid var(--line-strong); flex: none;
        }
        .face-panel .actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .bar { height: 5px; border-radius: var(--radius-pill); background: var(--line); overflow: hidden; margin-top: 14px; }
        .bar > i { display: block; height: 100%; width: 0; background: var(--brand); transition: width 0.25s ease; }

        /* ── Consent modal (PDPA) ─────────────────────── */
        .modal {
            position: fixed; inset: 0; z-index: 60;
            background: rgba(8,12,11,0.62);
            display: flex; align-items: flex-end; justify-content: center;
            animation: fade 0.14s ease;
        }
        @media (min-width: 640px) { .modal { align-items: center; padding: 20px; } }
        .modal .box {
            background: var(--surface); width: 100%; max-width: 560px;
            border: 1px solid var(--line); border-bottom: 0;
            border-radius: 18px 18px 0 0; padding-bottom: env(safe-area-inset-bottom);
            max-height: 92dvh; overflow-y: auto;
            animation: slideUp 0.22s ease;
        }
        @media (min-width: 640px) {
            .modal .box { border-bottom: 1px solid var(--line); border-radius: 18px; padding-bottom: 0; animation: none; }
        }
        .modal .head {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 18px 20px; border-bottom: 1px solid var(--line);
        }
        .modal .head .badge {
            width: 38px; height: 38px; border-radius: var(--radius-sm); background: var(--brand-soft);
            border: 1px solid var(--brand-soft-line);
            display: flex; align-items: center; justify-content: center; color: var(--brand); flex: none;
        }
        .modal .head .badge .icon { width: 20px; height: 20px; }
        .modal h3 { font-size: 17px; font-weight: 800; letter-spacing: -0.01em; }
        .modal .lead { font-size: 13.5px; color: var(--muted); line-height: 1.55; margin-top: 4px; }
        .modal .body { padding: 18px 20px; }

        .consent-list { list-style: none; display: grid; gap: 14px; margin-bottom: 18px; }
        .consent-list li { display: flex; gap: 10px; font-size: 13.5px; line-height: 1.68; color: var(--ink-2); }
        .consent-list li .icon { width: 17px; height: 17px; color: var(--brand); flex: none; margin-top: 3px; }
        .consent-list li b { font-weight: 800; color: var(--ink); }

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
        .legal { font-size: 12.5px; color: var(--muted); line-height: 1.65; margin-top: 14px; }
        .legal a { color: var(--brand); font-weight: 700; }

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
        <symbol id="i-scan-face" viewBox="0 0 24 24"><path d="M3 8V5a2 2 0 0 1 2-2h3M16 3h3a2 2 0 0 1 2 2v3M21 16v3a2 2 0 0 1-2 2h-3M8 21H5a2 2 0 0 1-2-2v-3"/><path d="M9 10h.01M15 10h.01M9 15c.8.7 1.9 1 3 1s2.2-.3 3-1"/></symbol>
        <symbol id="i-shield" viewBox="0 0 24 24"><path d="M12 2 4 5.5V11c0 5 3.4 9.4 8 11 4.6-1.6 8-6 8-11V5.5L12 2Z"/><path d="m9 12 2 2 4-4"/></symbol>
        <symbol id="i-lock" viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></symbol>
        <symbol id="i-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></symbol>
        <symbol id="i-trash" viewBox="0 0 24 24"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13"/></symbol>
        <symbol id="i-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
        <symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/></symbol>
        <symbol id="i-check" viewBox="0 0 24 24"><path d="m4.5 12.5 5 5 10-11"/></symbol>
        <symbol id="i-check-square" viewBox="0 0 24 24"><path d="M9 11.5 11.5 14l5-5.5"/><rect x="3" y="3" width="18" height="18" rx="3"/></symbol>
        <symbol id="i-user-search" viewBox="0 0 24 24"><circle cx="10" cy="8" r="4"/><path d="M3 21a7 7 0 0 1 8.6-6.8"/><circle cx="17" cy="17" r="3.5"/><path d="m21 21-1.5-1.5"/></symbol>
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
            <div class="actions">
                <button class="btn btn-ghost" id="selectBtn" type="button">
                    <svg class="icon"><use href="#i-check-square"/></svg> <span>เลือกรูป</span>
                </button>
                <button class="btn btn-ghost" id="faceSearchBtn" type="button">
                    <svg class="icon"><use href="#i-scan-face"/></svg> ค้นหารูปของฉัน
                </button>
                <a class="btn btn-primary" id="downloadAll" href="#">
                    <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดทั้งหมด
                </a>
            </div>
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

    {{-- แถบล่างของโหมดเลือกรูป — โผล่เฉพาะตอนกด "เลือกรูป" --}}
    <div class="selectbar hidden" id="selectBar">
        <div class="inner">
            <div class="n" id="selectCount">เลือกแล้ว <span>0</span> รูป</div>
            <button class="btn btn-quiet btn-sm" id="selectAll" type="button">เลือกทั้งหมด</button>
            <button class="btn btn-ghost btn-sm" id="selectCancel" type="button">ยกเลิก</button>
            <a class="btn btn-primary btn-sm" id="selectDownload" href="#">
                <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดที่เลือก
            </a>
        </div>
    </div>

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

        const nf = (n) => Number(n || 0).toLocaleString('th-TH');
        function show(id) { document.getElementById(id).classList.remove('hidden'); }
        function hide(id) { document.getElementById(id).classList.add('hidden'); }

        // โครงรูปตอนกำลังโหลด — จำนวนพอดีหนึ่งหน้าจอ ไม่ต้องรู้ว่ามีกี่รูปจริง
        document.getElementById('skeletonGrid').innerHTML =
            Array.from({ length: 12 }, () => '<div class="skel"></div>').join('');

        /* ══════════════════════════════════════════════════
           Lightbox
           ══════════════════════════════════════════════════ */
        let photoList = [];   // ทุกรูปในอัลบั้ม
        let viewList = [];    // ชุดที่กำลังแสดงอยู่ (ทั้งหมด หรือเฉพาะรูปที่เจอใบหน้าคุณ)
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
           โหมดเลือกรูป — ดาวน์โหลดเฉพาะรูปที่ติ๊กไว้
           ══════════════════════════════════════════════════ */
        const grid = document.getElementById('grid');
        const selectBar = document.getElementById('selectBar');
        const selectBtn = document.getElementById('selectBtn');
        const selected = new Set();
        let selecting = false;

        function updateSelectBar() {
            document.querySelector('#selectCount span').textContent = nf(selected.size);
            const dl = document.getElementById('selectDownload');
            dl.href = selected.size ? DL_ALL + '?ids=' + [...selected].join(',') : '#';
            dl.classList.toggle('hidden', selected.size === 0);
            document.getElementById('selectAll').textContent =
                (selected.size > 0 && selected.size === viewList.length) ? 'ล้างที่เลือก' : 'เลือกทั้งหมด';
        }

        function setSelecting(on) {
            selecting = on;
            grid.classList.toggle('selecting', on);
            selectBar.classList.toggle('hidden', !on);
            selectBtn.classList.toggle('on', on);
            selectBtn.querySelector('span').textContent = on ? 'ปิดโหมดเลือก' : 'เลือกรูป';
            document.body.classList.toggle('picking', on);
            if (!on) { selected.clear(); paintSelection(); }
            updateSelectBar();
        }

        function paintSelection() {
            grid.querySelectorAll('.tile').forEach((t) => {
                t.classList.toggle('on', selected.has(Number(t.dataset.id)));
            });
        }

        function toggleSelected(id) {
            if (selected.has(id)) selected.delete(id); else selected.add(id);
            paintSelection();
            updateSelectBar();
        }

        selectBtn.addEventListener('click', () => setSelecting(!selecting));
        document.getElementById('selectCancel').addEventListener('click', () => setSelecting(false));
        document.getElementById('selectAll').addEventListener('click', () => {
            if (selected.size > 0 && selected.size === viewList.length) selected.clear();
            else viewList.forEach((p) => selected.add(p.id));
            paintSelection();
            updateSelectBar();
        });

        /* ══════════════════════════════════════════════════
           ตารางรูป
           ══════════════════════════════════════════════════ */
        // แสดงรูปตามชุดที่ส่งเข้ามา — ใช้ทั้งตอนโหลดครบทุกรูปและตอนกรองด้วยใบหน้า
        function renderGrid(list, matched = false) {
            viewList = list;
            grid.innerHTML = list.map((p, i) => `
                <div class="tile${matched ? ' match' : ''}" data-id="${p.id}" style="animation-delay:${Math.min(i, 11) * 18}ms">
                    <img src="${p.thumb_url || p.url}" alt="" loading="lazy" decoding="async" data-index="${i}">
                    <span class="mark"><svg class="icon"><use href="#i-check"/></svg></span>
                    <button class="pick" type="button" data-id="${p.id}" aria-label="เลือกรูปนี้"></button>
                    <a class="dl" href="${DL_ONE(p.id)}" aria-label="ดาวน์โหลดรูปนี้">
                        <svg class="icon"><use href="#i-download"/></svg>
                    </a>
                </div>
            `).join('');

            grid.querySelectorAll('img').forEach((img) => {
                img.addEventListener('click', () => openLightbox(Number(img.dataset.index)));
            });
            grid.querySelectorAll('.pick').forEach((btn) => {
                btn.addEventListener('click', () => toggleSelected(Number(btn.dataset.id)));
            });

            paintSelection();
            updateSelectBar();
        }

        async function load() {
            try {
                const res = await fetch(API_URL, { headers: { 'Accept': 'application/json' } });
                if (res.status === 404) { failAlbum(); return; }
                if (!res.ok) throw new Error('http ' + res.status);

                const body = await res.json();
                const data = body.data ?? body;

                document.getElementById('albumTitle').textContent = data.trip_title || 'อัลบั้มรูปทริป';
                photoList = data.photos || [];
                hide('loadingState');

                renderChips(data);

                if (!photoList.length) { show('emptyState'); return; }

                document.getElementById('photoCount').innerHTML =
                    '<svg class="icon"><use href="#i-images"/></svg> ทั้งหมด ' + nf(photoList.length) +
                    ' รูป <span class="dim">· กดที่รูปเพื่อดูเต็มจอ</span>';
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
            if (selecting) setSelecting(false); // สองโหมดนี้อยู่ด้วยกันไม่ได้
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
