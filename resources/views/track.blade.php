<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
    <meta name="robots" content="noindex, nofollow">
    <title>ติดตามรถ | ลุยเลเขา</title>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Anuphan:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('fonts/db-heavent/db-heavent.css') }}">

    <style>
        :root {
            --brand: #087C68;
            --brand-dark: #044C4D;
            --ink: #111827;
            --muted: #667085;
            --bg: #F2F5F4;
            --surface: #FFFFFF;
            --warn: #D97706;
            --danger: #DC2626;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            height: 100%;
            font-family: 'DB Heavent', 'Anuphan', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--bg);
            color: var(--ink);
            overflow: hidden;
        }

        #app { display: flex; flex-direction: column; height: 100dvh; }

        header {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
            z-index: 600;
        }

        header .logo {
            width: 38px; height: 38px;
            border-radius: 11px;
            background: rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }

        header .title { flex: 1; min-width: 0; }
        header .title h1 {
            font-size: 15px; font-weight: 800;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        header .title p { font-size: 12px; opacity: 0.82; font-weight: 500; }

        .live-dot {
            display: flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700;
            background: rgba(255,255,255,0.16);
            padding: 5px 10px; border-radius: 999px;
        }
        .live-dot .pulse {
            width: 7px; height: 7px; border-radius: 50%;
            background: #4ADE80;
            box-shadow: 0 0 0 0 rgba(74,222,128,0.7);
            animation: pulse 1.8s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(74,222,128,0.6); }
            70% { box-shadow: 0 0 0 8px rgba(74,222,128,0); }
            100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); }
        }

        #map { flex: 1; width: 100%; background: #E5E9E8; z-index: 1; }

        .sheet {
            background: var(--surface);
            border-radius: 22px 22px 0 0;
            box-shadow: 0 -8px 32px rgba(0,0,0,0.12);
            padding: 18px 20px calc(18px + env(safe-area-inset-bottom));
            flex-shrink: 0;
            z-index: 600;
        }

        .eta-row { display: flex; align-items: flex-end; gap: 14px; }
        .eta-main { flex: 1; }
        .eta-label { font-size: 12px; color: var(--muted); font-weight: 600; }
        .eta-value {
            font-size: 38px; font-weight: 900; line-height: 1.05;
            color: var(--brand); letter-spacing: -0.5px;
        }
        .eta-value .unit { font-size: 17px; font-weight: 800; }
        .eta-distance {
            font-size: 13px; color: var(--muted); font-weight: 600;
            margin-top: 2px;
        }

        .vehicle-chip {
            display: flex; align-items: center; gap: 8px;
            background: var(--bg);
            border-radius: 14px;
            padding: 10px 12px;
        }
        .vehicle-chip .ic { font-size: 20px; }
        .vehicle-chip .meta { font-size: 11px; }
        .vehicle-chip .meta strong { display: block; font-size: 13px; font-weight: 800; }
        .vehicle-chip .meta span { color: var(--muted); font-weight: 600; }

        .divider { height: 1px; background: #EEF1F0; margin: 14px 0; }

        .footer-meta {
            display: flex; align-items: center; justify-content: space-between;
            font-size: 11px; color: var(--muted); font-weight: 600;
        }
        .footer-meta .brand-tag { display: flex; align-items: center; gap: 5px; }

        .banner {
            border-radius: 16px;
            padding: 14px 16px;
            display: flex; align-items: center; gap: 12px;
            font-weight: 600;
        }
        .banner .ic { font-size: 24px; flex-shrink: 0; }
        .banner.info { background: #FFF7ED; color: var(--warn); }
        .banner.gone { background: #FEF2F2; color: var(--danger); }
        .banner .txt strong { display: block; font-size: 14px; font-weight: 800; }
        .banner .txt span { font-size: 12.5px; opacity: 0.9; }

        .center-state {
            position: absolute; inset: 0;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            gap: 14px; padding: 32px; text-align: center;
            background: var(--bg); z-index: 700;
        }
        .center-state .emoji { font-size: 48px; }
        .center-state h2 { font-size: 18px; font-weight: 800; }
        .center-state p { font-size: 14px; color: var(--muted); font-weight: 500; max-width: 300px; }
        .spinner {
            width: 38px; height: 38px;
            border: 3.5px solid #D7E0DD;
            border-top-color: var(--brand);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hidden { display: none !important; }

        .bus-marker {
            width: 44px; height: 44px;
            background: var(--brand);
            border: 4px solid #fff;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
            box-shadow: 0 6px 16px rgba(8,124,104,0.4);
        }
        .pin-marker {
            width: 30px; height: 30px;
            background: var(--ink);
            border: 3px solid #fff;
            border-radius: 50% 50% 50% 0;
            transform: rotate(-45deg);
            box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            display: flex; align-items: center; justify-content: center;
        }
        .pin-marker span { transform: rotate(45deg); font-size: 13px; }
    </style>
</head>
<body>
<div id="app">
    <header>
        <div class="logo">🚐</div>
        <div class="title">
            <h1 id="tripTitle">กำลังโหลด...</h1>
            <p id="tripSub">ติดตามรถแบบเรียลไทม์</p>
        </div>
        <div class="live-dot" id="liveDot"><span class="pulse"></span> LIVE</div>
    </header>

    <div style="position: relative; flex: 1; display: flex;">
        <div id="map"></div>

        <div class="center-state" id="loadingState">
            <div class="spinner"></div>
            <p>กำลังโหลดตำแหน่งรถ...</p>
        </div>

        <div class="center-state hidden" id="errorState">
            <div class="emoji">🔍</div>
            <h2 id="errorTitle">ไม่พบลิงก์ติดตามรถ</h2>
            <p id="errorMsg">ลิงก์นี้อาจหมดอายุหรือถูกยกเลิกแล้ว</p>
        </div>
    </div>

    <div class="sheet" id="sheet"></div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
    integrity="sha256-20nQCchB9co0qIjJ7g0fbV/4F0pYr1l8DJ8m1Fp4hkk=" crossorigin=""></script>
<script>
    const TOKEN = @json($token);
    const API_URL = '/api/v1/track/' + encodeURIComponent(TOKEN);
    const POLL_MS = 20000;

    let map, busMarker, pickupMarker, routeLine;
    let pollTimer = null;

    const busIcon = L.divIcon({
        className: '',
        html: '<div class="bus-marker">🚐</div>',
        iconSize: [44, 44], iconAnchor: [22, 22],
    });
    const pinIcon = L.divIcon({
        className: '',
        html: '<div class="pin-marker"><span>📍</span></div>',
        iconSize: [30, 30], iconAnchor: [15, 30],
    });

    function initMap(lat, lng) {
        map = L.map('map', { zoomControl: false, attributionControl: false })
            .setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
        }).addTo(map);
        L.control.zoom({ position: 'bottomright' }).addTo(map);
    }

    function thaiDate(iso) {
        if (!iso) return '';
        const d = new Date(iso);
        if (isNaN(d)) return '';
        const months = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
        return d.getDate() + ' ' + months[d.getMonth()] + ' ' + (d.getFullYear() + 543);
    }

    function timeAgo(iso) {
        if (!iso) return 'ไม่ทราบ';
        const diff = (Date.now() - new Date(iso).getTime()) / 1000;
        if (diff < 60) return 'เมื่อสักครู่';
        if (diff < 3600) return Math.floor(diff / 60) + ' นาทีที่แล้ว';
        return Math.floor(diff / 3600) + ' ชม.ที่แล้ว';
    }

    function showError(title, msg) {
        document.getElementById('loadingState').classList.add('hidden');
        const el = document.getElementById('errorState');
        el.classList.remove('hidden');
        if (title) document.getElementById('errorTitle').textContent = title;
        if (msg) document.getElementById('errorMsg').textContent = msg;
        document.getElementById('sheet').innerHTML = '';
        document.getElementById('liveDot').classList.add('hidden');
    }

    function renderNonTrackable(data) {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('liveDot').classList.add('hidden');
        const gone = ['cancelled', 'refunded'].includes(data.status);
        document.getElementById('sheet').innerHTML = `
            <div class="banner ${gone ? 'gone' : 'info'}">
                <div class="ic">${gone ? '🚫' : '⏳'}</div>
                <div class="txt">
                    <strong>${gone ? 'ทริปถูกยกเลิก' : 'ยังติดตามไม่ได้ตอนนี้'}</strong>
                    <span>${data.message || ''}</span>
                </div>
            </div>`;
    }

    function renderTrackable(data) {
        document.getElementById('loadingState').classList.add('hidden');
        document.getElementById('liveDot').classList.remove('hidden');

        const v = data.vehicle;
        const eta = data.eta;
        const pickup = data.pickup;

        if (!map) initMap(v.lat, v.lng);

        // Bus marker
        const busPos = [v.lat, v.lng];
        if (!busMarker) {
            busMarker = L.marker(busPos, { icon: busIcon }).addTo(map);
        } else {
            busMarker.setLatLng(busPos);
        }

        // Pickup marker + route line
        if (pickup && pickup.lat && pickup.lng) {
            const pickupPos = [pickup.lat, pickup.lng];
            if (!pickupMarker) {
                pickupMarker = L.marker(pickupPos, { icon: pinIcon }).addTo(map);
            }
            const line = [busPos, pickupPos];
            if (!routeLine) {
                routeLine = L.polyline(line, {
                    color: '#087C68', weight: 4, opacity: 0.6, dashArray: '8 8',
                }).addTo(map);
            } else {
                routeLine.setLatLngs(line);
            }
            map.fitBounds(L.latLngBounds(line).pad(0.35));
        } else {
            map.setView(busPos, 15);
        }

        const etaText = eta
            ? `<div class="eta-value">${eta.minutes}<span class="unit"> นาที</span></div>
               <div class="eta-distance">ห่างจุดรับ ${eta.distance_km} กม.</div>`
            : `<div class="eta-value" style="font-size:24px;">กำลังเดินทาง</div>`;

        document.getElementById('sheet').innerHTML = `
            <div class="eta-row">
                <div class="eta-main">
                    <div class="eta-label">${pickup && pickup.name ? 'รถจะถึง ' + pickup.name : 'รถจะถึงจุดรับในอีก'}</div>
                    ${etaText}
                </div>
                <div class="vehicle-chip">
                    <div class="ic">🚐</div>
                    <div class="meta">
                        <strong>${v.license_plate || 'รถนำเที่ยว'}</strong>
                        <span>${v.driver_name || 'คนขับ'}</span>
                    </div>
                </div>
            </div>
            <div class="divider"></div>
            <div class="footer-meta">
                <span>อัปเดต ${timeAgo(v.updated_at)}</span>
                <span class="brand-tag">🌿 ลุยเลเขา</span>
            </div>`;
    }

    async function poll() {
        try {
            const res = await fetch(API_URL, { headers: { 'Accept': 'application/json' } });
            if (res.status === 404) {
                showError('ไม่พบลิงก์ติดตามรถ', 'ลิงก์นี้อาจหมดอายุหรือถูกยกเลิกแล้ว');
                if (pollTimer) clearInterval(pollTimer);
                return;
            }
            if (!res.ok) throw new Error('http ' + res.status);

            const body = await res.json();
            const data = body.data || {};

            document.getElementById('tripTitle').textContent = data.trip_title || 'ทริปของคุณ';
            document.getElementById('tripSub').textContent =
                data.departure_date ? 'เดินทาง ' + thaiDate(data.departure_date) : 'ติดตามรถแบบเรียลไทม์';
            document.getElementById('errorState').classList.add('hidden');

            if (data.trackable && data.vehicle) {
                renderTrackable(data);
            } else {
                renderNonTrackable(data);
            }
        } catch (e) {
            document.getElementById('loadingState').classList.add('hidden');
            if (!map) {
                showError('เชื่อมต่อไม่ได้', 'กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่');
            }
        }
    }

    poll();
    pollTimer = setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') poll();
    });
</script>
</body>
</html>
