<!DOCTYPE html>
<html lang="th" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex, nofollow">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>ส่ง GPS | ลุยเลเขา</title>

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
            --success: #16A34A;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-tap-highlight-color: transparent; }

        html, body {
            height: 100%;
            font-family: 'DB Heavent', 'Anuphan', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg);
            color: var(--ink);
            overflow: hidden;
        }

        #app { display: flex; flex-direction: column; height: 100dvh; }

        /* ─── Header ──────────────────────────────────────── */
        header {
            background: linear-gradient(135deg, var(--brand-dark), var(--brand));
            color: #fff;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
            padding-top: calc(14px + env(safe-area-inset-top));
        }
        header .logo {
            width: 38px; height: 38px;
            border-radius: 11px;
            background: rgba(255,255,255,0.16);
            display: flex; align-items: center; justify-content: center;
            font-size: 20px;
        }
        header .title { flex: 1; min-width: 0; }
        header .title h1 { font-size: 15px; font-weight: 800; }
        header .title p { font-size: 12px; opacity: 0.82; font-weight: 500; }

        .status-pill {
            display: flex; align-items: center; gap: 6px;
            font-size: 11px; font-weight: 700;
            background: rgba(255,255,255,0.16);
            padding: 5px 10px; border-radius: 999px;
        }
        .status-pill .dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #94A3B8;
        }
        .status-pill.live .dot {
            background: #4ADE80;
            box-shadow: 0 0 0 0 rgba(74,222,128,0.7);
            animation: pulse 1.8s infinite;
        }
        .status-pill.error .dot { background: #F87171; }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(74,222,128,0.6); }
            70% { box-shadow: 0 0 0 8px rgba(74,222,128,0); }
            100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); }
        }

        /* ─── Content ──────────────────────────────────────── */
        .content {
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            padding: 24px 20px calc(24px + env(safe-area-inset-bottom));
        }

        /* ─── Step: PIN ──────────────────────────────────── */
        .pin-screen {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 28px;
            max-width: 360px;
            width: 100%;
            margin: 0 auto;
            padding-top: 12px;
        }
        .pin-intro { text-align: center; }
        .pin-intro .icon {
            font-size: 40px;
            margin-bottom: 10px;
        }
        .pin-intro h2 { font-size: 22px; font-weight: 800; }
        .pin-intro p { font-size: 14px; color: var(--muted); font-weight: 500; margin-top: 6px; }

        .pin-display {
            display: flex;
            gap: 10px;
            justify-content: center;
            min-height: 56px;
            align-items: center;
        }
        .pin-dot {
            width: 16px; height: 16px;
            border-radius: 50%;
            border: 2px solid #CBD5E1;
            background: transparent;
            transition: all 0.15s;
        }
        .pin-dot.filled {
            background: var(--brand);
            border-color: var(--brand);
            transform: scale(1.1);
        }

        .numpad {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            width: 100%;
        }
        .numpad-btn {
            background: var(--surface);
            border: none;
            border-radius: 16px;
            padding: 18px;
            font-size: 22px;
            font-weight: 700;
            font-family: inherit;
            color: var(--ink);
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            transition: background 0.1s, transform 0.1s;
            user-select: none;
        }
        .numpad-btn:active { background: #E2F0ED; transform: scale(0.96); }
        .numpad-btn.del { font-size: 18px; color: var(--muted); }
        .numpad-btn.zero { grid-column: 2; }

        .pin-error {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: var(--danger);
            font-size: 13px;
            font-weight: 600;
            padding: 10px 16px;
            border-radius: 12px;
            text-align: center;
            width: 100%;
        }
        .pin-error.hidden { display: none; }

        /* ─── Step: Select Vehicle ──────────────────────── */
        .select-screen { max-width: 400px; width: 100%; margin: 0 auto; }
        .select-screen h2 { font-size: 20px; font-weight: 800; margin-bottom: 6px; }
        .select-screen p { font-size: 14px; color: var(--muted); font-weight: 500; margin-bottom: 20px; }

        .schedule-card {
            background: var(--surface);
            border-radius: 18px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.15s, transform 0.1s;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .schedule-card:active { transform: scale(0.98); }
        .schedule-card.selected { border-color: var(--brand); }
        .schedule-icon {
            width: 48px; height: 48px;
            background: #E2F0ED;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .schedule-meta { flex: 1; min-width: 0; }
        .schedule-meta .trip { font-size: 15px; font-weight: 700; }
        .schedule-meta .sub { font-size: 12px; color: var(--muted); font-weight: 500; margin-top: 2px; }

        .btn {
            width: 100%;
            background: var(--brand);
            color: #fff;
            border: none;
            border-radius: 16px;
            padding: 16px;
            font-size: 16px;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            margin-top: 8px;
            transition: opacity 0.15s, transform 0.1s;
        }
        .btn:disabled { opacity: 0.45; cursor: default; }
        .btn:not(:disabled):active { transform: scale(0.98); }
        .btn.danger { background: var(--danger); }

        /* ─── Step: Tracking ──────────────────────────── */
        .tracking-screen { max-width: 400px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }

        .trip-banner {
            background: var(--surface);
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
        }
        .trip-banner .label { font-size: 11px; color: var(--muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .trip-banner .trip-name { font-size: 17px; font-weight: 800; margin-top: 3px; }
        .trip-banner .plate { font-size: 13px; font-weight: 600; color: var(--brand); margin-top: 4px; }

        .gps-card {
            background: var(--surface);
            border-radius: 18px;
            padding: 18px 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
        }
        .gps-status-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .gps-status-icon {
            width: 44px; height: 44px;
            border-radius: 13px;
            display: flex; align-items: center; justify-content: center;
            font-size: 22px;
            background: #E2F0ED;
        }
        .gps-status-icon.warn { background: #FEF3C7; }
        .gps-status-icon.err { background: #FEF2F2; }
        .gps-status-text { flex: 1; }
        .gps-status-text strong { display: block; font-size: 15px; font-weight: 700; }
        .gps-status-text span { font-size: 12px; color: var(--muted); font-weight: 500; }

        .stat-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .stat-box {
            background: var(--bg);
            border-radius: 12px;
            padding: 12px;
        }
        .stat-box .val {
            font-size: 20px;
            font-weight: 800;
            color: var(--brand);
        }
        .stat-box .lbl {
            font-size: 11px;
            color: var(--muted);
            font-weight: 600;
            margin-top: 2px;
        }

        .coords-row {
            margin-top: 10px;
            font-size: 11px;
            color: var(--muted);
            font-weight: 500;
            font-variant-numeric: tabular-nums;
            text-align: center;
        }

        .wake-notice {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: 12px;
            padding: 10px 14px;
            font-size: 12px;
            color: #92400E;
            font-weight: 600;
            display: flex;
            gap: 8px;
            align-items: flex-start;
        }
        .wake-notice.hidden { display: none; }

        /* ─── Spinner ──────────────────────────────────── */
        .spinner {
            display: inline-block;
            width: 18px; height: 18px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            vertical-align: middle;
            margin-right: 6px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .hidden { display: none !important; }
    </style>
</head>
<body>
<div id="app">
    <header>
        <div class="logo">🏕️</div>
        <div class="title">
            <h1>ลุยเลเขา — คนขับ</h1>
            <p id="header-sub">เข้าสู่ระบบด้วย PIN</p>
        </div>
        <div class="status-pill" id="status-pill">
            <div class="dot"></div>
            <span id="status-text">ออฟไลน์</span>
        </div>
    </header>

    <div class="content">

        {{-- ─── Step 1: PIN ─── --}}
        <div class="pin-screen" id="step-pin">
            <div class="pin-intro">
                <div class="icon">🔐</div>
                <h2>ใส่ PIN คนขับ</h2>
                <p>กรอก PIN ที่ได้รับจากผู้ดูแล<br>เพื่อเริ่มส่ง GPS</p>
            </div>

            <div class="pin-display" id="pin-display"></div>

            <div class="pin-error hidden" id="pin-error"></div>

            <div class="numpad">
                <button class="numpad-btn" data-digit="1">1</button>
                <button class="numpad-btn" data-digit="2">2</button>
                <button class="numpad-btn" data-digit="3">3</button>
                <button class="numpad-btn" data-digit="4">4</button>
                <button class="numpad-btn" data-digit="5">5</button>
                <button class="numpad-btn" data-digit="6">6</button>
                <button class="numpad-btn" data-digit="7">7</button>
                <button class="numpad-btn" data-digit="8">8</button>
                <button class="numpad-btn" data-digit="9">9</button>
                <button class="numpad-btn zero" data-digit="0">0</button>
                <button class="numpad-btn del" id="btn-del">⌫</button>
            </div>
        </div>

        {{-- ─── Step 2: Select vehicle/schedule ─── --}}
        <div class="select-screen hidden" id="step-select">
            <h2>เลือกรถ / ทริปวันนี้</h2>
            <p id="select-intro">มีหลายรอบที่คุณรับผิดชอบ — เลือก 1 รอบเพื่อเริ่มส่ง GPS</p>
            <div id="schedule-list"></div>
            <button class="btn" id="btn-start-select" disabled>เริ่มส่ง GPS</button>
        </div>

        {{-- ─── Step 3: Tracking ─── --}}
        <div class="tracking-screen hidden" id="step-tracking">

            <div class="trip-banner" id="trip-banner">
                <div class="label">ทริปที่กำลังส่ง GPS</div>
                <div class="trip-name" id="tracking-trip-name">—</div>
                <div class="plate" id="tracking-plate">—</div>
            </div>

            <div class="gps-card">
                <div class="gps-status-row">
                    <div class="gps-status-icon" id="gps-icon">📡</div>
                    <div class="gps-status-text">
                        <strong id="gps-status-label">กำลังหาสัญญาณ GPS...</strong>
                        <span id="gps-status-sub">โปรดรอสักครู่</span>
                    </div>
                </div>

                <div class="stat-grid">
                    <div class="stat-box">
                        <div class="val" id="stat-count">0</div>
                        <div class="lbl">ส่งสำเร็จ (ครั้ง)</div>
                    </div>
                    <div class="stat-box">
                        <div class="val" id="stat-accuracy">—</div>
                        <div class="lbl">ความแม่นยำ GPS</div>
                    </div>
                    <div class="stat-box">
                        <div class="val" id="stat-speed">—</div>
                        <div class="lbl">ความเร็ว km/h</div>
                    </div>
                    <div class="stat-box">
                        <div class="val" id="stat-last">—</div>
                        <div class="lbl">ส่งล่าสุด</div>
                    </div>
                </div>

                <div class="coords-row" id="coords-row">รอ GPS...</div>
            </div>

            <div class="wake-notice hidden" id="wake-notice">
                ⚠️ อุปกรณ์นี้ไม่รองรับ Wake Lock — หน้าจออาจดับและหยุดส่ง GPS อัตโนมัติ กรุณาตั้งค่าไม่ให้หน้าจอดับระหว่างขับ
            </div>

            <button class="btn danger" id="btn-stop">หยุดส่ง GPS</button>
        </div>

    </div>
</div>

<script>
const API = '/api/v1';
const SEND_INTERVAL_MS = 5000;

let state = {
    pin: '',
    token: null,
    schedules: [],
    selectedSchedule: null,
    watchId: null,
    wakeLock: null,
    sendCount: 0,
    lastPosition: null,
    sendTimer: null,
};

// ─── PIN Screen ──────────────────────────────────────
const pinDisplay = document.getElementById('pin-display');
const pinError   = document.getElementById('pin-error');

function renderPinDots() {
    pinDisplay.innerHTML = '';
    const len = Math.max(state.pin.length, 4);
    for (let i = 0; i < len; i++) {
        const d = document.createElement('div');
        d.className = 'pin-dot' + (i < state.pin.length ? ' filled' : '');
        pinDisplay.appendChild(d);
    }
}
renderPinDots();

document.querySelectorAll('.numpad-btn[data-digit]').forEach(btn => {
    btn.addEventListener('click', () => {
        if (state.pin.length >= 8) return;
        state.pin += btn.dataset.digit;
        renderPinDots();
        hidePinError();
        if (state.pin.length >= 4) attemptLogin();
    });
});

document.getElementById('btn-del').addEventListener('click', () => {
    state.pin = state.pin.slice(0, -1);
    renderPinDots();
    hidePinError();
});

function showPinError(msg) {
    pinError.textContent = msg;
    pinError.classList.remove('hidden');
    state.pin = '';
    renderPinDots();
}
function hidePinError() { pinError.classList.add('hidden'); }

let loginInFlight = false;
async function attemptLogin() {
    if (loginInFlight) return;
    loginInFlight = true;

    try {
        const res = await fetch(`${API}/driver/pin-login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ driver_pin: state.pin }),
        });
        const json = await res.json();

        if (!res.ok) {
            showPinError(json.message ?? 'PIN ไม่ถูกต้อง กรุณาลองใหม่');
            return;
        }

        state.token     = json.data.token;
        state.schedules = json.data.schedules ?? [];

        if (state.schedules.length === 0) {
            showPinError('ไม่พบรอบเดินทางวันนี้ที่คุณรับผิดชอบ');
            return;
        }

        if (state.schedules.length === 1) {
            state.selectedSchedule = state.schedules[0];
            goToTracking();
        } else {
            goToSelectScreen();
        }
    } catch (e) {
        showPinError('ไม่สามารถเชื่อมต่อได้ กรุณาตรวจสอบอินเทอร์เน็ต');
    } finally {
        loginInFlight = false;
    }
}

// ─── Select Screen ────────────────────────────────────
function goToSelectScreen() {
    show('step-select');
    hide('step-pin');

    const list = document.getElementById('schedule-list');
    list.innerHTML = '';

    state.schedules.forEach((s, i) => {
        const card = document.createElement('div');
        card.className = 'schedule-card';
        card.dataset.idx = i;

        const date  = s.departure_date ? new Date(s.departure_date).toLocaleDateString('th-TH', { day:'numeric', month:'short' }) : '';

        card.innerHTML = `
            <div class="schedule-icon">🚌</div>
            <div class="schedule-meta">
                <div class="trip">${s.trip_title ?? 'ทริปไม่ระบุ'}</div>
                <div class="sub">${[s.vehicle?.license_plate, date].filter(Boolean).join(' · ')}</div>
            </div>
        `;
        card.addEventListener('click', () => {
            document.querySelectorAll('.schedule-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            state.selectedSchedule = state.schedules[i];
            document.getElementById('btn-start-select').disabled = false;
        });
        list.appendChild(card);
    });
}

document.getElementById('btn-start-select').addEventListener('click', () => {
    if (state.selectedSchedule) goToTracking();
});

// ─── Tracking Screen ──────────────────────────────────
function goToTracking() {
    hide('step-pin');
    hide('step-select');
    show('step-tracking');

    const s = state.selectedSchedule;
    document.getElementById('tracking-trip-name').textContent = s.trip_title ?? 'ทริปไม่ระบุ';
    document.getElementById('tracking-plate').textContent    = s.vehicle?.license_plate ?? 'ไม่ระบุทะเบียน';
    document.getElementById('header-sub').textContent        = s.trip_title ?? 'กำลังส่ง GPS';

    setStatusPill('loading', 'หา GPS...');
    startTracking();
}

function startTracking() {
    if (!navigator.geolocation) {
        setGpsStatus('err', '📵', 'ไม่รองรับ GPS', 'เบราว์เซอร์นี้ไม่รองรับ Geolocation');
        return;
    }

    state.watchId = navigator.geolocation.watchPosition(
        onPosition,
        onGpsError,
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );

    acquireWakeLock();
}

function onPosition(pos) {
    state.lastPosition = pos;

    const acc = Math.round(pos.coords.accuracy);
    const spd = pos.coords.speed != null ? Math.round(pos.coords.speed * 3.6) : null;

    document.getElementById('stat-accuracy').textContent = acc < 1000 ? acc + ' ม.' : '>1 กม.';
    document.getElementById('stat-speed').textContent    = spd != null ? spd : '—';
    document.getElementById('coords-row').textContent    =
        `${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;

    setGpsStatus(
        acc <= 50 ? 'ok' : 'warn',
        acc <= 50 ? '📍' : '🔄',
        acc <= 50 ? 'สัญญาณดี' : 'กำลังปรับ GPS...',
        `ความแม่นยำ ${acc} เมตร`
    );

    if (!state.sendTimer) {
        sendLocation();
        state.sendTimer = setInterval(sendLocation, SEND_INTERVAL_MS);
    }
}

function onGpsError(err) {
    const msgs = {
        1: 'ไม่ได้รับอนุญาตใช้ GPS — กรุณาอนุญาตในการตั้งค่าเบราว์เซอร์',
        2: 'หา GPS ไม่พบ กรุณาเปิดใช้ Location',
        3: 'GPS หมดเวลา กำลังลองใหม่...',
    };
    setGpsStatus('err', '⚠️', 'ปัญหา GPS', msgs[err.code] ?? 'ข้อผิดพลาด GPS');
    setStatusPill('error', 'ข้อผิดพลาด');
}

async function sendLocation() {
    if (!state.lastPosition) return;

    const p   = state.lastPosition.coords;
    const vid = state.selectedSchedule?.vehicle?.id;
    if (!vid) return;

    const body = {
        vehicle_id: vid,
        latitude:   p.latitude,
        longitude:  p.longitude,
        accuracy:   p.accuracy ?? null,
        speed:      p.speed != null ? p.speed * 3.6 : null,
        heading:    p.heading ?? null,
        recorded_at: new Date(state.lastPosition.timestamp).toISOString(),
    };

    try {
        const res = await fetch(`${API}/tracking/update`, {
            method: 'POST',
            headers: {
                'Content-Type':  'application/json',
                'Accept':        'application/json',
                'Authorization': `Bearer ${state.token}`,
            },
            body: JSON.stringify(body),
        });

        if (res.ok) {
            state.sendCount++;
            document.getElementById('stat-count').textContent = state.sendCount;
            const now = new Date();
            document.getElementById('stat-last').textContent =
                `${now.getHours().toString().padStart(2,'0')}:${now.getMinutes().toString().padStart(2,'0')}:${now.getSeconds().toString().padStart(2,'0')}`;
            setStatusPill('live', 'ส่ง GPS');
        }
    } catch (e) {
        setStatusPill('error', 'ส่งไม่ได้');
    }
}

document.getElementById('btn-stop').addEventListener('click', () => {
    stopTracking();
    hide('step-tracking');
    show('step-pin');

    state.pin      = '';
    state.token    = null;
    state.schedules = [];
    state.selectedSchedule = null;
    state.sendCount = 0;
    state.lastPosition = null;
    state.sendTimer = null;
    renderPinDots();
    setStatusPill('offline', 'ออฟไลน์');
    document.getElementById('header-sub').textContent = 'เข้าสู่ระบบด้วย PIN';
});

function stopTracking() {
    if (state.watchId != null) {
        navigator.geolocation.clearWatch(state.watchId);
        state.watchId = null;
    }
    if (state.sendTimer) {
        clearInterval(state.sendTimer);
        state.sendTimer = null;
    }
    releaseWakeLock();
}

// ─── Wake Lock ────────────────────────────────────────
async function acquireWakeLock() {
    if (!('wakeLock' in navigator)) {
        document.getElementById('wake-notice').classList.remove('hidden');
        return;
    }
    try {
        state.wakeLock = await navigator.wakeLock.request('screen');
        state.wakeLock.addEventListener('release', () => {
            // re-acquire when page becomes visible again
        });
    } catch (e) {
        document.getElementById('wake-notice').classList.remove('hidden');
    }
}

function releaseWakeLock() {
    if (state.wakeLock) {
        state.wakeLock.release().catch(() => {});
        state.wakeLock = null;
    }
}

// re-acquire wake lock when page regains visibility
document.addEventListener('visibilitychange', async () => {
    if (document.visibilityState === 'visible' && state.watchId != null && !state.wakeLock) {
        await acquireWakeLock();
    }
});

// ─── Helpers ──────────────────────────────────────────
function show(id) { document.getElementById(id).classList.remove('hidden'); }
function hide(id) { document.getElementById(id).classList.add('hidden'); }

function setStatusPill(type, text) {
    const pill = document.getElementById('status-pill');
    pill.className = 'status-pill';
    if (type === 'live')    pill.classList.add('live');
    if (type === 'error')   pill.classList.add('error');
    document.getElementById('status-text').textContent = text;
}

function setGpsStatus(type, icon, label, sub) {
    const ic = document.getElementById('gps-icon');
    ic.className = 'gps-status-icon';
    if (type === 'warn') ic.classList.add('warn');
    if (type === 'err')  ic.classList.add('err');
    ic.textContent = icon;
    document.getElementById('gps-status-label').textContent = label;
    document.getElementById('gps-status-sub').textContent   = sub;
}
</script>
</body>
</html>
