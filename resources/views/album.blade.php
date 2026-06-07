<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="robots" content="noindex, nofollow">
    <title>อัลบั้มรูปทริป | ลุยเลเขา</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand: #087C68;
            --brand-dark: #044C4D;
            --ink: #111827;
            --muted: #667085;
            --bg: #F2F5F4;
            --surface: #FFFFFF;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            font-family: 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            min-height: 100dvh;
        }

        header {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        header .logo {
            width: 40px; height: 40px;
            border-radius: 12px;
            background: rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center;
            font-size: 21px;
            flex-shrink: 0;
        }

        header .title { flex: 1; min-width: 0; }
        header .title h1 {
            font-size: 16px; font-weight: 800;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        header .title p { font-size: 12px; opacity: 0.85; font-weight: 500; }

        main { max-width: 960px; margin: 0 auto; padding: 16px; }

        .toolbar {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; margin-bottom: 16px; flex-wrap: wrap;
        }
        .toolbar .count { font-size: 14px; color: var(--muted); font-weight: 600; }

        .btn-all {
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--brand); color: #fff;
            border: none; border-radius: 10px;
            padding: 11px 18px; font-size: 14px; font-weight: 700;
            font-family: inherit; cursor: pointer; text-decoration: none;
            transition: background 0.15s, transform 0.05s;
        }
        .btn-all:hover { background: var(--brand-dark); }
        .btn-all:active { transform: scale(0.98); }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
        }

        .tile {
            position: relative;
            aspect-ratio: 1;
            border-radius: 12px;
            overflow: hidden;
            background: #e5e7eb;
        }
        .tile img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
            cursor: zoom-in;
        }
        .tile a.dl {
            position: absolute; bottom: 8px; right: 8px;
            width: 36px; height: 36px;
            border-radius: 999px;
            background: rgba(0,0,0,0.55);
            color: #fff; text-decoration: none;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px;
            transition: background 0.15s;
        }
        .tile a.dl:hover { background: var(--brand); }

        .state {
            text-align: center; padding: 64px 20px; color: var(--muted);
        }
        .state .emoji { font-size: 48px; display: block; margin-bottom: 12px; }
        .state h2 { font-size: 18px; color: var(--ink); margin-bottom: 6px; }
        .spinner {
            width: 38px; height: 38px; margin: 0 auto 14px;
            border: 4px solid #d1d5db; border-top-color: var(--brand);
            border-radius: 50%; animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hidden { display: none !important; }

        /* Lightbox */
        .lightbox {
            position: fixed; inset: 0; z-index: 50;
            background: rgba(0,0,0,0.92);
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        .lightbox img { max-width: 100%; max-height: 86dvh; border-radius: 8px; }
        .lightbox .close {
            position: absolute; top: 16px; right: 16px;
            width: 44px; height: 44px; border-radius: 999px;
            background: rgba(255,255,255,0.15); color: #fff;
            border: none; font-size: 24px; cursor: pointer;
        }
        .lightbox .lb-dl {
            position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%);
        }

        footer { text-align: center; padding: 24px 16px 40px; color: var(--muted); font-size: 12px; }
    </style>
</head>
<body>
    <header>
        <div class="logo">📸</div>
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
            <span class="emoji">🔌</span>
            <h2 id="errorTitle">ไม่พบอัลบั้มนี้</h2>
            <p id="errorMsg">ลิงก์อาจหมดอายุหรือถูกปิดการแชร์แล้ว</p>
        </div>

        <div id="emptyState" class="state hidden">
            <span class="emoji">🖼️</span>
            <h2>ยังไม่มีรูปในอัลบั้มนี้</h2>
            <p>ทีมงานกำลังทยอยอัปโหลด ลองกลับมาใหม่อีกครั้งนะครับ</p>
        </div>

        <div id="content" class="hidden">
            <div class="toolbar">
                <span class="count" id="photoCount"></span>
                <a class="btn-all" id="downloadAll" href="#">⬇️ ดาวน์โหลดทั้งหมด</a>
            </div>
            <div class="grid" id="grid"></div>
        </div>
    </main>

    <div id="lightbox" class="lightbox hidden">
        <button class="close" id="lbClose">✕</button>
        <img id="lbImg" src="" alt="">
        <a class="btn-all lb-dl" id="lbDownload" href="#">⬇️ ดาวน์โหลดรูปนี้</a>
    </div>

    <footer>ลุยเลเขา · ภาพกิจกรรมประจำทริป</footer>

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

        function openLightbox(url, id) {
            document.getElementById('lbImg').src = url;
            document.getElementById('lbDownload').href = DL_ONE(id);
            show('lightbox');
        }
        document.getElementById('lbClose').addEventListener('click', () => hide('lightbox'));
        document.getElementById('lightbox').addEventListener('click', (e) => {
            if (e.target.id === 'lightbox') hide('lightbox');
        });

        async function load() {
            try {
                const res = await fetch(API_URL, { headers: { 'Accept': 'application/json' } });
                if (res.status === 404) { hide('loadingState'); show('errorState'); return; }
                if (!res.ok) throw new Error('http ' + res.status);

                const body = await res.json();
                const data = body.data ?? body;

                document.getElementById('albumTitle').textContent = data.trip_title || 'อัลบั้มรูปทริป';
                const dep = thaiDate(data.departure_date);
                document.getElementById('albumMeta').textContent = dep ? ('เดินทาง ' + dep) : 'ภาพกิจกรรมประจำทริป';

                const photos = data.photos || [];
                hide('loadingState');

                if (!photos.length) { show('emptyState'); return; }

                document.getElementById('photoCount').textContent = 'ทั้งหมด ' + photos.length + ' รูป';
                document.getElementById('downloadAll').href = DL_ALL;

                const grid = document.getElementById('grid');
                grid.innerHTML = photos.map((p) => `
                    <div class="tile">
                        <img src="${p.thumb_url || p.url}" alt="" loading="lazy" data-id="${p.id}" data-full="${p.url}">
                        <a class="dl" href="${DL_ONE(p.id)}" title="ดาวน์โหลดรูปนี้">⬇️</a>
                    </div>
                `).join('');

                grid.querySelectorAll('img').forEach((img) => {
                    // Lightbox shows the full-resolution image, not the thumbnail.
                    img.addEventListener('click', () => openLightbox(img.dataset.full, img.dataset.id));
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
