@extends('passenger-fill.layout')

@section('title', $pass ? 'บัตรของ'.$pass['name'] : 'ไม่พบลิงก์')

@push('styles')
<style>
    .hello { font-size: 15px; color: #475569; margin-bottom: 4px; }
    .facts { list-style: none; margin: 14px 0 0; padding: 0; }
    .facts li { display: flex; gap: 10px; font-size: 14.5px; color: #334155; padding: 6px 0; border-top: 1px solid #f1f5f9; }
    .facts li:first-child { border-top: none; }
    .facts .k { color: #64748b; min-width: 74px; font-weight: 600; }
    .facts a { color: #0B6E5F; font-weight: 700; text-decoration: none; }
    .block { margin-top: 18px; padding-top: 18px; border-top: 1px solid #e2e8f0; }
    .block h2 { font-size: 17px; margin-bottom: 6px; }
    .block p { font-size: 14px; color: #475569; }
    .qr { text-align: center; margin: 14px 0 6px; }
    .qr img { width: 240px; max-width: 100%; height: auto; border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; background: #fff; }
    .code { font-family: ui-monospace, Menlo, monospace; font-size: 13px; color: #64748b; letter-spacing: .5px; text-align: center; }
    .pill { display: inline-block; border-radius: 999px; padding: 4px 12px; font-size: 13px; font-weight: 700; }
    .pill-ok { background: #ecfdf5; color: #047857; }
    .pill-warn { background: #fffbeb; color: #b45309; }
    .pill-muted { background: #f1f5f9; color: #475569; }
    .btn-outline { background: #fff; color: #0B6E5F; border: 1.5px solid #0B6E5F; }
    .btn-quiet { background: #f8fafc; color: #b91c1c; border: 1px solid #fecaca; }
    .btn + .btn, form + .btn, .btn + form, form + form { margin-top: 10px; }
    a.btn { text-align: center; text-decoration: none; }
    .stores { display: flex; gap: 10px; margin-top: 10px; }
    .stores a { flex: 1; text-align: center; font-size: 13.5px; font-weight: 700; color: #334155; text-decoration: none; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px; }
    .alert-ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
    .tiny { font-size: 12.5px; color: #64748b; margin-top: 8px; line-height: 1.55; }
</style>
@endpush

@section('content')
@if (! $pass)
    <div class="card">
        <div class="done">
            <div class="tick">🔗</div>
            <h1>ลิงก์นี้ใช้ไม่ได้แล้ว</h1>
            <p>ลิงก์อาจถูกเปลี่ยนใหม่ หรือทริปจบไปแล้ว<br>ขอลิงก์ใหม่จากคนที่จองให้คุณได้เลย</p>
        </div>
    </div>
@else
    <div class="card">
        <div class="card-header">
            <div class="brand">ลุยเลเขา</div>
            <h1>{{ $pass['trip_title'] }}</h1>
            @if ($pass['departure_label'])
                <p>{{ $pass['departure_label'] }}</p>
            @endif
        </div>

        <div class="card-body">
            @if (session('notice'))
                <div class="alert alert-ok">{{ session('notice') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-error">{{ session('error') }}</div>
            @endif

            <p class="hello">สวัสดี <strong>{{ $pass['name'] }}</strong> 👋</p>
            <p class="lead" style="margin-bottom: 0">
                นี่คือลิงก์ของคุณสำหรับทริปนี้ เก็บไว้เปิดตอนวันเดินทาง
                @if ($pass['checked_in'])
                    <br><span class="pill pill-ok">ขึ้นรถแล้ว {{ $pass['checked_in_label'] }} น.</span>
                @elseif ($pass['not_going'])
                    <br><span class="pill pill-warn">แจ้งไว้ว่าไม่ไป</span>
                @elseif (! $pass['confirmed'])
                    <br><span class="pill pill-muted">รอคนจองชำระเงิน</span>
                @endif
            </p>

            <ul class="facts">
                @if ($pass['departs_label'])
                    <li><span class="k">ออกเดินทาง</span><span>{{ $pass['departs_label'] }}</span></li>
                @endif
                @if ($pass['pickup_label'])
                    <li>
                        <span class="k">จุดขึ้นรถ</span>
                        <span>
                            {{ $pass['pickup_label'] }}@if ($pass['pickup_time']) · {{ $pass['pickup_time'] }} น.@endif
                            @if ($pass['pickup_map_url'])
                                <br><a href="{{ $pass['pickup_map_url'] }}" target="_blank" rel="noopener">เปิดแผนที่</a>
                            @endif
                        </span>
                    </li>
                @endif
                @if ($pass['trip_location'])
                    <li><span class="k">สถานที่</span><span>{{ $pass['trip_location'] }}</span></li>
                @endif
            </ul>

            {{-- บัตรขึ้นรถ --}}
            @if (! $pass['not_going'] && ($pass['show_qr'] || $pass['qr_pending']))
                <div class="block" id="pass">
                    <h2>บัตรขึ้นรถของคุณ</h2>
                    @if ($pass['show_qr'])
                        <p>ให้ทีมงานสแกน QR นี้ที่จุดขึ้นรถ — เช็คอินเฉพาะคุณ ไม่ต้องรอคนจอง</p>
                        <div class="qr"><img src="{{ $pass['qr'] }}" alt="QR บัตรขึ้นรถของ {{ $pass['name'] }}"></div>
                        <div class="code">{{ $pass['code'] }}</div>
                        <p class="tiny">แคปหน้าจอเก็บไว้ได้ เผื่อจุดขึ้นรถไม่มีสัญญาณ</p>
                    @else
                        <p>QR สำหรับขึ้นรถจะขึ้นที่นี่ตั้งแต่วันก่อนเดินทาง เปิดลิงก์นี้อีกครั้งตอนนั้นได้เลย</p>
                    @endif
                </div>
            @endif

            {{-- ข้อมูลของตัวเอง --}}
            @if ($pass['needs_fill'])
                <div class="block">
                    <h2>กรอกข้อมูลของคุณ</h2>
                    <p>ชื่อ-นามสกุลจริงสำหรับทำประกัน เบอร์ติดต่อ และเรื่องสุขภาพที่ทีมงานควรรู้ — ใช้เวลาไม่ถึงนาที ไม่ต้องสมัครสมาชิก</p>
                    <form method="POST" action="{{ route('public.friend-pass.fill', $pass['token']) }}" style="margin-top: 12px">
                        @csrf
                        <button type="submit" class="btn">กรอกข้อมูลของฉัน</button>
                    </form>
                </div>
            @endif

            {{-- เข้าแอป --}}
            <div class="block">
                <h2>ห้องแชทของทริป</h2>
                @if ($pass['join_url'])
                    <p>คุยกับทีมงานและเพื่อนร่วมทริป ดูตำแหน่งรถ และรับแจ้งเตือนวันเดินทาง — เข้าร่วมในแอปด้วยชื่อของคุณ</p>
                    <a class="btn" href="{{ $pass['join_url'] }}" style="margin-top: 12px">เข้าร่วมทริปในแอป</a>
                    <p class="tiny">ยังไม่มีแอป? โหลดก่อนแล้วกลับมากดปุ่มนี้อีกครั้ง</p>
                    @if ($pass['ios_url'] || $pass['android_url'])
                        <div class="stores">
                            @if ($pass['ios_url'])<a href="{{ $pass['ios_url'] }}" target="_blank" rel="noopener">App Store</a>@endif
                            @if ($pass['android_url'])<a href="{{ $pass['android_url'] }}" target="_blank" rel="noopener">Google Play</a>@endif
                        </div>
                    @endif
                @else
                    <p>ชื่อนี้เข้าร่วมทริปในแอปแล้ว เปิดแอปลุยเลเขาเพื่อดูห้องแชทและบัตรขึ้นรถได้เลย</p>
                @endif
            </div>

            {{-- ไปไม่ได้ --}}
            @if ($pass['attendance_open'])
                <div class="block">
                    @if ($pass['not_going'])
                        <h2>เปลี่ยนใจ ไปได้แล้ว?</h2>
                        <p>บอกทีมงานได้จนถึงเวลารถออก</p>
                        <form method="POST" action="{{ route('public.friend-pass.attendance', $pass['token']) }}" style="margin-top: 12px">
                            @csrf
                            <input type="hidden" name="not_going" value="0">
                            <button type="submit" class="btn btn-outline">ฉันไปได้</button>
                        </form>
                    @else
                        <h2>ไปไม่ได้?</h2>
                        <p>บอกไว้ก่อน ทีมงานจะได้ไม่ต้องรอคุณที่จุดขึ้นรถ ถ้ามีคนไปแทน ให้คนจองกด “ส่งต่อที่นั่ง” ในแอป ชื่อในประกันจะได้ตรงตัวคน</p>
                        <form method="POST" action="{{ route('public.friend-pass.attendance', $pass['token']) }}" style="margin-top: 12px"
                              onsubmit="return confirm('แจ้งทีมงานว่าคุณไม่ไปทริปนี้?')">
                            @csrf
                            <input type="hidden" name="not_going" value="1">
                            <button type="submit" class="btn btn-quiet">ฉันไปไม่ได้</button>
                        </form>
                        <p class="tiny">การแจ้งไม่ไปไม่ใช่การยกเลิกการจอง และไม่ได้คืนเงินอัตโนมัติ</p>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
@endsection
