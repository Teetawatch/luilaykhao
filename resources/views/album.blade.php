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
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}">

    <style>
        :root {
            --brand: #087C68;
            --brand-dark: #044C4D;
            --brand-soft: #E6F2EF;
            --ink: #111827;
            --muted: #667085;
            --line: #E6E9E8;
            --bg: #F2F5F4;
            --surface: #FFFFFF;
            --shadow-sm: 0 1px 2px rgba(16,24,40,0.06), 0 1px 3px rgba(16,24,40,0.10);
            --shadow-md: 0 8px 24px rgba(16,24,40,0.12);
            --radius: 14px;
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
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
            padding: clamp(14px, 3vw, 18px) clamp(16px, 4vw, 22px);
            padding-top: max(clamp(14px, 3vw, 18px), env(safe-area-inset-top));
            display: flex;
            align-items: center;
            gap: 14px;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 2px 16px rgba(4,76,77,0.25);
        }
        header .logo {
            width: 44px; height: 44px;
            border-radius: 13px;
            background: rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            backdrop-filter: blur(4px);
        }
        header .logo .icon { width: 24px; height: 24px; }
        header .title { flex: 1; min-width: 0; }
        header .title h1 {
            font-size: clamp(15px, 4vw, 18px); font-weight: 800; letter-spacing: -0.01em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        header .title p {
            font-size: clamp(11px, 3vw, 13px); opacity: 0.88; font-weight: 500;
            margin-top: 2px;
            display: flex; align-items: center; gap: 5px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        header .title p .icon { width: 14px; height: 14px; flex-shrink: 0; }

        /* ── Layout ───────────────────────────────────── */
        main { max-width: 1120px; margin: 0 auto; padding: clamp(14px, 3vw, 22px); }

        .toolbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 18px; flex-wrap: wrap;
        }
        .toolbar .count {
            font-size: 14px; color: var(--muted); font-weight: 600;
            display: inline-flex; align-items: center; gap: 7px;
        }
        .toolbar .count .icon { width: 17px; height: 17px; color: var(--brand); }

        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            border: none; border-radius: 11px;
            padding: 11px 18px; font-size: 14px; font-weight: 700;
            font-family: inherit; cursor: pointer; text-decoration: none;
            transition: background 0.15s, transform 0.06s, box-shadow 0.15s;
            white-space: nowrap;
        }
        .btn .icon { width: 18px; height: 18px; }
        .btn-primary { background: var(--brand); color: #fff; box-shadow: var(--shadow-sm); }
        .btn-primary:hover { background: var(--brand-dark); box-shadow: var(--shadow-md); }
        .btn-primary:active { transform: scale(0.97); }

        /* ── Grid ─────────────────────────────────────── */
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 150px), 1fr));
            gap: clamp(8px, 2vw, 12px);
        }
        @media (min-width: 640px) {
            .grid { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
        }

        .tile {
            position: relative;
            aspect-ratio: 1;
            border-radius: var(--radius);
            overflow: hidden;
            background: #e5e7eb;
            box-shadow: var(--shadow-sm);
        }
        .tile img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
            cursor: zoom-in;
            transition: transform 0.35s ease;
        }
        .tile::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.28), transparent 42%);
            opacity: 0; transition: opacity 0.2s; pointer-events: none;
        }
        .tile:hover img { transform: scale(1.05); }
        .tile:hover::after { opacity: 1; }

        .tile .dl {
            position: absolute; bottom: 9px; right: 9px;
            width: 38px; height: 38px;
            border-radius: 999px;
            background: rgba(17,24,39,0.55);
            color: #fff; text-decoration: none;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(6px);
            transition: background 0.15s, transform 0.06s;
            opacity: 0;
        }
        .tile:hover .dl, .tile:focus-within .dl { opacity: 1; }
        .tile .dl:hover { background: var(--brand); }
        .tile .dl:active { transform: scale(0.92); }
        .tile .dl .icon { width: 19px; height: 19px; }
        /* Touch devices can't hover — keep the button visible. */
        @media (hover: none) {
            .tile .dl { opacity: 1; }
        }

        /* ── States ───────────────────────────────────── */
        .state { text-align: center; padding: clamp(48px, 12vw, 80px) 20px; color: var(--muted); }
        .state .badge {
            width: 76px; height: 76px; margin: 0 auto 18px;
            border-radius: 22px; background: var(--brand-soft);
            display: flex; align-items: center; justify-content: center;
            color: var(--brand);
        }
        .state .badge .icon { width: 34px; height: 34px; stroke-width: 1.8; }
        .state h2 { font-size: 19px; color: var(--ink); margin-bottom: 6px; font-weight: 800; }
        .state p { font-size: 14px; max-width: 360px; margin: 0 auto; line-height: 1.55; }

        .spinner {
            width: 40px; height: 40px; margin: 0 auto 16px;
            border: 4px solid #d8e0de; border-top-color: var(--brand);
            border-radius: 50%; animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hidden { display: none !important; }

        /* ── Lightbox ─────────────────────────────────── */
        .lightbox {
            position: fixed; inset: 0; z-index: 50;
            background: rgba(8,10,12,0.94);
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
            backdrop-filter: blur(2px);
            animation: fade 0.18s ease;
        }
        @keyframes fade { from { opacity: 0; } to { opacity: 1; } }
        .lightbox img {
            max-width: 100%; max-height: 82dvh;
            border-radius: 10px; box-shadow: 0 16px 50px rgba(0,0,0,0.5);
            user-select: none;
        }
        .lb-btn {
            position: absolute;
            border: none; cursor: pointer;
            background: rgba(255,255,255,0.12); color: #fff;
            display: flex; align-items: center; justify-content: center;
            border-radius: 999px;
            transition: background 0.15s, transform 0.06s;
            backdrop-filter: blur(6px);
        }
        .lb-btn:hover { background: rgba(255,255,255,0.24); }
        .lb-btn:active { transform: scale(0.92); }
        .lb-btn .icon { width: 24px; height: 24px; }
        .lb-close { top: max(16px, env(safe-area-inset-top)); right: 16px; width: 46px; height: 46px; }
        .lb-nav { top: 50%; transform: translateY(-50%); width: 48px; height: 48px; }
        .lb-nav:active { transform: translateY(-50%) scale(0.92); }
        .lb-prev { left: 14px; }
        .lb-next { right: 14px; }
        .lb-counter {
            position: absolute; top: max(20px, env(safe-area-inset-top)); left: 50%; transform: translateX(-50%);
            color: #fff; font-size: 13px; font-weight: 600;
            background: rgba(255,255,255,0.12); padding: 6px 14px; border-radius: 999px;
            backdrop-filter: blur(6px);
        }
        .lb-download {
            position: absolute; bottom: max(20px, env(safe-area-inset-bottom)); left: 50%; transform: translateX(-50%);
        }
        @media (max-width: 540px) {
            .lb-nav { width: 42px; height: 42px; }
            .lb-prev { left: 8px; } .lb-next { right: 8px; }
        }

        footer {
            text-align: center; padding: 28px 16px calc(40px + env(safe-area-inset-bottom));
            color: var(--muted); font-size: 12.5px;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        footer .icon { width: 14px; height: 14px; color: var(--brand); }
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
    </svg>

    <header>
        <div class="logo"><svg class="icon"><use href="#i-camera"/></svg></div>
        <div class="title">
            <h1 id="albumTitle">อัลบั้มรูปทริป</h1>
            <p id="albumMeta">กำลังโหลด…</p>
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
            <div class="toolbar">
                <span class="count" id="photoCount"><svg class="icon"><use href="#i-images"/></svg></span>
                <a class="btn btn-primary" id="downloadAll" href="#">
                    <svg class="icon"><use href="#i-download"/></svg> ดาวน์โหลดทั้งหมด
                </a>
            </div>
            <div class="grid" id="grid"></div>
        </div>
    </main>

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
        const DL_ALL = '/album/' + encodeURIComponent(TOKEN) + '/download';
        const DL_ONE = (id) => '/album/' + encodeURIComponent(TOKEN) + '/download/' + id;

        const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        function thaiDate(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            if (isNaN(d)) return '';
            return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543);
        }

        function show(id) { document.getElementById(id).classList.remove('hidden'); }
        function hide(id) { document.getElementById(id).classList.add('hidden'); }

        // ── Lightbox with prev/next navigation ──────────
        let photoList = [];
        let lbIndex = 0;
        const lbImg = document.getElementById('lbImg');
        const lbCounter = document.getElementById('lbCounter');
        const lbDownload = document.getElementById('lbDownload');

        function renderLightbox() {
            const p = photoList[lbIndex];
            if (!p) return;
            lbImg.src = p.url;
            lbDownload.href = DL_ONE(p.id);
            lbCounter.textContent = (lbIndex + 1) + ' / ' + photoList.length;
        }
        function openLightbox(index) {
            lbIndex = index;
            renderLightbox();
            show('lightbox');
        }
        function step(delta) {
            lbIndex = (lbIndex + delta + photoList.length) % photoList.length;
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

        async function load() {
            try {
                const res = await fetch(API_URL, { headers: { 'Accept': 'application/json' } });
                if (res.status === 404) { hide('loadingState'); show('errorState'); return; }
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

                const grid = document.getElementById('grid');
                grid.innerHTML = photoList.map((p, i) => `
                    <div class="tile">
                        <img src="${p.thumb_url || p.url}" alt="" loading="lazy" data-index="${i}">
                        <a class="dl" href="${DL_ONE(p.id)}" aria-label="ดาวน์โหลดรูปนี้">
                            <svg class="icon"><use href="#i-download"/></svg>
                        </a>
                    </div>
                `).join('');

                grid.querySelectorAll('img').forEach((img) => {
                    img.addEventListener('click', () => openLightbox(Number(img.dataset.index)));
                });

                show('content');
            } catch (e) {
                hide('loadingState');
                document.getElementById('errorTitle').textContent = 'โหลดอัลบั้มไม่สำเร็จ';
                document.getElementById('errorMsg').textContent = 'กรุณาลองใหม่อีกครั้ง';
                show('errorState');
            }
        }

        load();
    </script>
</body>
</html>
