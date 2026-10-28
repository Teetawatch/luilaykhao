@extends('passenger-fill.layout')

@section('title', $p ? 'เซ็นแบบฟอร์ม · '.$p['name'] : 'ไม่พบลิงก์')

@push('styles')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400&display=swap" rel="stylesheet">
<style>
    .alert-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .pill { display: inline-block; border-radius: 999px; padding: 4px 12px; font-size: 13px; font-weight: 700; }
    .pill-ok { background: #ecfdf5; color: #047857; }
    .pill-warn { background: #fffbeb; color: #b45309; }
    .pill-bad { background: #fef2f2; color: #b91c1c; }
    .status { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
    .status small { font-size: 13px; color: #64748b; }
    .step { font-size: 15px; font-weight: 700; color: #0f172a; margin: 18px 0 8px; }
    .muted { font-size: 13px; color: #64748b; line-height: 1.55; }

    /* แบบฟอร์ม — ภาพหน้าเปล่า + ข้อความวางตำแหน่งเดียวกับใน PDF */
    .pf-page { position: relative; width: 100%; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #fff; margin-bottom: 10px; }
    .pf-page img { display: block; width: 100%; height: auto; }
    .pf-f { position: absolute; font-family: 'Sarabun', sans-serif; color: #000; line-height: 1.15; white-space: nowrap; overflow: visible; }
    .pf-f.check { font-family: 'DejaVu Sans', 'Segoe UI Symbol', sans-serif; text-align: center; }
    .pf-f.sig { border: 1.5px dashed #f59e0b; background: rgba(254, 243, 199, .55); border-radius: 4px; }
    .zoom { font-size: 13px; color: #0B6E5F; font-weight: 700; text-decoration: none; display: inline-block; margin-bottom: 6px; }

    /* แผ่นเซ็น */
    .pad-wrap { position: relative; border: 1.5px solid #cbd5e1; border-radius: 12px; background: #fff; touch-action: none; }
    .pad-wrap canvas { display: block; width: 100%; height: 190px; border-radius: 12px; cursor: crosshair; }
    .pad-line { position: absolute; left: 18px; right: 18px; bottom: 44px; border-bottom: 1px dashed #cbd5e1; pointer-events: none; }
    .pad-hint { position: absolute; left: 0; right: 0; bottom: 18px; text-align: center; font-size: 12.5px; color: #94a3b8; pointer-events: none; }
    .pad-tools { display: flex; justify-content: flex-end; margin: 6px 0 14px; }
    .link-btn { background: none; border: 0; color: #64748b; font-size: 13.5px; font-family: inherit; cursor: pointer; text-decoration: underline; padding: 4px; }
    .btn[disabled] { opacity: .5; }
    .resign { margin-top: 10px; }
</style>
@endpush

@section('content')
@if (! $p)
    <div class="card">
        <div class="done">
            <div class="tick">🔗</div>
            <h1>ลิงก์นี้ใช้ไม่ได้แล้ว</h1>
            <p>ลิงก์อาจถูกเปลี่ยนใหม่ ทริปจบไปแล้ว หรือทริปนี้ไม่ต้องเซ็นแบบฟอร์ม<br>สอบถามคนที่จองให้คุณ หรือทักทีมงานได้เลย</p>
        </div>
    </div>
@else
    <div class="card">
        <div class="card-header">
            <div class="brand">ลุยเลเขา · เซ็นแบบฟอร์มอุทยาน</div>
            <h1>{{ $p['name'] }}</h1>
            <p>{{ $p['trip_title'] }} · {{ $p['form_name'] }}</p>
        </div>

        <div class="card-body">
            @if (session('notice'))
                <div class="alert alert-ok">{{ session('notice') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-error">{{ $errors->first() }}</div>
            @endif

            <div class="status">
                @if ($p['signed'])
                    <span class="pill pill-ok">✓ เซ็นแล้ว</span>
                    <small>{{ $p['signed_label'] }}</small>
                @elseif ($p['deadline_label'])
                    <span class="pill {{ $p['overdue'] ? 'pill-bad' : 'pill-warn' }}">
                        {{ $p['overdue'] ? 'เลยกำหนดแล้ว' : 'เซ็นภายใน' }} {{ $p['deadline_label'] }}
                    </span>
                @else
                    <span class="pill pill-warn">ยังไม่ได้เซ็น</span>
                @endif
            </div>

            <p class="muted">
                อุทยานให้ผู้เดินทางทุกคนยื่นแบบฟอร์มนี้ตอนลงทะเบียนล่วงหน้า เราเติมข้อมูลที่คุณให้ไว้ตอนจองให้แล้ว
                (ข้อมูลส่วนตัวถูกปิดไว้บางส่วนในหน้านี้) — <strong>อ่านให้ครบ</strong> แล้วเซ็นในกรอบด้านล่าง
                ช่องที่ยังว่าง ทีมงานจะกรอกให้หรือสอบถามเพิ่มเติม
            </p>

            <div class="step">1. อ่านแบบฟอร์ม</div>
            @foreach ($p['pages'] as $i => $page)
                <a class="zoom" href="{{ $page['url'] }}" target="_blank" rel="noopener">🔍 เปิดหน้า {{ $i + 1 }} แบบเต็มจอ</a>
                <div class="pf-page" data-width-mm="{{ $page['width_mm'] }}">
                    <img src="{{ $page['url'] }}" alt="แบบฟอร์มหน้า {{ $i + 1 }}">
                    @foreach ($p['fields'] as $f)
                        @continue($f['page'] !== $i)
                        <div class="pf-f {{ $f['type'] === 'check' ? 'check' : '' }} {{ $f['type'] === 'signature' ? 'sig' : '' }}"
                             data-size="{{ $f['size'] }}"
                             style="left: {{ $f['x'] * 100 }}%; top: {{ $f['y'] * 100 }}%; width: {{ $f['w'] * 100 }}%; height: {{ $f['h'] * 100 }}%; text-align: {{ $f['align'] }};">{{ $f['value'] }}</div>
                    @endforeach
                </div>
            @endforeach

            @if ($p['open'])
                <div id="sign-area" @if ($p['signed']) hidden @endif>
                    <div class="step">2. เซ็นชื่อ</div>
                    <p class="muted" style="margin-bottom: 10px">ลายเซ็นนี้จะไปอยู่ในช่องสีเหลืองของแบบฟอร์ม</p>

                    <form method="POST" action="{{ route('public.friend-permit.sign', $p['token']) }}" id="sign-form">
                        @csrf
                        <input type="hidden" name="signature" id="signature-input">

                        <div class="pad-wrap">
                            <canvas id="pad"></canvas>
                            <div class="pad-line"></div>
                            <div class="pad-hint" id="pad-hint">ใช้นิ้ววาดลายเซ็นตรงนี้</div>
                        </div>
                        <div class="pad-tools">
                            <button type="button" class="link-btn" id="pad-clear">ล้างแล้วเซ็นใหม่</button>
                        </div>

                        <label class="check" style="align-items: flex-start">
                            <input type="checkbox" name="accept" value="1" id="accept" style="margin-top: 2px; flex-shrink: 0">
                            <span>ข้าพเจ้า {{ $p['name'] }} ได้อ่านข้อความในแบบฟอร์มนี้ครบถ้วนแล้ว และยินยอมให้ใช้ลายเซ็นนี้ลงในแบบฟอร์มฉบับนี้</span>
                        </label>

                        <button type="submit" class="btn" id="sign-submit" disabled>ยืนยันลายเซ็น</button>
                    </form>
                    <p class="privacy">เราเก็บลายเซ็นไว้เฉพาะเพื่อยื่นแบบฟอร์มนี้กับอุทยาน และลบทิ้งหลังจบทริป</p>
                </div>

                @if ($p['signed'])
                    <div class="resign">
                        <button type="button" class="link-btn" id="resign">เซ็นผิด? เซ็นใหม่</button>
                    </div>
                @endif
            @elseif (! $p['signed'])
                <p class="muted" style="margin-top: 14px">ตอนนี้เซ็นผ่านลิงก์นี้ไม่ได้แล้ว ทักทีมงานได้เลยครับ</p>
            @endif
        </div>
    </div>

    <script>
    (function () {
        // ขนาดตัวอักษรในแบบฟอร์มเป็น pt ของกระดาษจริง — แปลงตามความกว้างหน้าบนจอ
        function sizeFields() {
            document.querySelectorAll('.pf-page').forEach(function (page) {
                var ptWidth = parseFloat(page.dataset.widthMm) * 72 / 25.4;
                var scale = page.clientWidth / ptWidth;
                page.querySelectorAll('.pf-f').forEach(function (f) {
                    f.style.fontSize = (parseFloat(f.dataset.size) * scale) + 'px';
                });
            });
        }
        sizeFields();
        window.addEventListener('resize', sizeFields);

        var resign = document.getElementById('resign');
        if (resign) {
            resign.addEventListener('click', function () {
                document.getElementById('sign-area').hidden = false;
                resign.hidden = true;
                setupPad();
            });
        }

        var ready = false;
        function setupPad() {
            var canvas = document.getElementById('pad');
            if (!canvas || ready) return;
            ready = true;

            var ctx = canvas.getContext('2d');
            var drawn = false, drawing = false, last = null;
            var accept = document.getElementById('accept');
            var submit = document.getElementById('sign-submit');
            var hint = document.getElementById('pad-hint');

            function resize() {
                // พื้นหลังโปร่งใส — เซิร์ฟเวอร์ตัดขอบโปร่งใสออก และลายเซ็นไม่บังเส้นประในฟอร์ม
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                var rect = canvas.getBoundingClientRect();
                canvas.width = Math.round(rect.width * ratio);
                canvas.height = Math.round(rect.height * ratio);
                ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.strokeStyle = '#000';
                ctx.lineWidth = 2.4;
                drawn = false;
                hint.hidden = false;
                refresh();
            }
            function point(e) {
                var rect = canvas.getBoundingClientRect();
                return { x: e.clientX - rect.left, y: e.clientY - rect.top };
            }
            function refresh() { submit.disabled = !(drawn && accept.checked); }

            canvas.addEventListener('pointerdown', function (e) {
                e.preventDefault();
                canvas.setPointerCapture(e.pointerId);
                drawing = true;
                last = point(e);
                ctx.beginPath();
                ctx.arc(last.x, last.y, ctx.lineWidth / 2, 0, Math.PI * 2);
                ctx.fillStyle = '#000';
                ctx.fill();
            });
            canvas.addEventListener('pointermove', function (e) {
                if (!drawing) return;
                e.preventDefault();
                var p = point(e);
                ctx.beginPath();
                ctx.moveTo(last.x, last.y);
                ctx.lineTo(p.x, p.y);
                ctx.stroke();
                last = p;
                if (!drawn) { drawn = true; hint.hidden = true; refresh(); }
            });
            ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) {
                canvas.addEventListener(t, function () { drawing = false; });
            });
            document.getElementById('pad-clear').addEventListener('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                drawn = false;
                hint.hidden = false;
                refresh();
            });
            accept.addEventListener('change', refresh);
            document.getElementById('sign-form').addEventListener('submit', function (e) {
                if (!drawn) { e.preventDefault(); return; }
                document.getElementById('signature-input').value = canvas.toDataURL('image/png');
                submit.disabled = true;
                submit.textContent = 'กำลังบันทึก…';
            });

            resize();
            window.addEventListener('resize', function () { if (!drawn) resize(); });
        }

        if (!document.getElementById('sign-area')?.hidden) setupPad();
    })();
    </script>
@endif
@endsection
