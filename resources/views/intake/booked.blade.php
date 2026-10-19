@extends('intake.layout')

@section('title', 'จองสำเร็จ')

{{--
    หน้า "จองสำเร็จ" ของลิงก์กลุ่ม — ปลายทางหลังจ่ายเงิน และหน้าที่เพื่อนในกลุ่ม
    เห็นเมื่อเปิดลิงก์กลุ่มหลังจากจองแล้ว

    งานหลักของหน้านี้คือพาไปแอป: ลูกค้ากลุ่มนี้จองผ่านลิงก์ในแชท ไม่เคยเห็นแอป แต่ทุก
    อย่างหลังจากนี้ (ห้องแชทของรอบ QR ขึ้นรถ ติดตามรถ ชำระยอดคงเหลือ) อยู่ในแอป
    ขาย "ของข้างใน" ไม่ใช่ขาย "แอป" และไม่ชวนคนที่มีแอปแล้ว (AppLinks::hasApp)

    ลิงก์นี้อยู่ในแชทกลุ่ม — ห้ามแสดงลิงก์เปิดบัญชี (ไปทาง SMS เท่านั้น) เบอร์เต็ม
    หรืออีเมลเต็มของคนจอง
--}}

@php
    $heroImage = $trip?->cover_image ? url($trip->cover_image) : null;
    $heroTitle = 'จองสำเร็จแล้ว';
    $heroEyebrow = $trip?->title ?: 'ลุยเลเขา';
    $heroEyebrowIcon = 'check-circle';
    $heroSub = 'ที่นั่งของกลุ่มนี้ได้รับการยืนยันแล้ว เจอกันวันเดินทาง';
    $heroChips = [['icon' => 'note', 'text' => $booking->booking_ref]];
    if ($schedule) {
        $heroChips[] = ['icon' => 'calendar', 'text' => $schedule->dateRangeLabelThai()];
    }
    $balance = (float) $booking->balance_amount;
    $hasBalance = $booking->payment_type === 'deposit' && $balance > 0 && ! $booking->balance_paid_at;
    $ogTitle = 'จองสำเร็จ · '.($trip?->title ?? 'ลุยเลเขา');
    $ogDescription = 'ที่นั่งของกลุ่มได้รับการยืนยันแล้ว';
@endphp

@push('styles')
<style>
    .summary { border: 1px solid var(--line); border-radius: 14px; margin-bottom: 20px; }
    .summary > div { display: flex; justify-content: space-between; gap: 12px; padding: 11px 14px; font-size: 15.5px; }
    .summary > div + div { border-top: 1px solid var(--line); }
    .summary span:first-child { color: var(--muted); }
    .summary b { color: var(--ink); text-align: right; }

    .app { border-radius: 18px; background: var(--forest); color: #fff; padding: 20px 18px; margin-bottom: 18px; }
    .app h2 { font-size: 21px; font-weight: 700; line-height: 1.3; }
    .app > p { font-size: 15.5px; color: rgba(255,255,255,.8); margin-top: 4px; line-height: 1.5; }
    .app-perks { list-style: none; display: grid; gap: 10px; margin: 16px 0 18px; }
    .app-perks li { display: flex; gap: 10px; align-items: flex-start; font-size: 15.5px; line-height: 1.45; }
    .app-perks .ic { width: 20px; height: 20px; color: var(--leaf); margin-top: 1px; }
    .app-perks b { display: block; color: #fff; }
    .app-perks span { color: rgba(255,255,255,.75); }
    .stores { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .store {
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        min-height: 54px; border-radius: 12px; background: #fff; color: var(--forest);
        text-decoration: none; font-weight: 700; font-size: 16.5px; line-height: 1.15;
    }
    .store small { font-size: 12.5px; font-weight: 500; color: var(--muted); }

    .steps { list-style: none; counter-reset: s; display: grid; gap: 10px; margin-bottom: 18px; }
    .steps li { counter-increment: s; display: flex; gap: 11px; font-size: 15.5px; line-height: 1.5; color: var(--body); }
    .steps li::before {
        content: counter(s); flex: 0 0 26px; width: 26px; height: 26px; border-radius: 50%;
        display: grid; place-items: center; background: var(--tint); border: 1px solid var(--tint-line);
        color: var(--canopy-dark); font-weight: 700; font-size: 14px;
    }
    .steps b { color: var(--ink); }
</style>
@endpush

@section('content')
    <div class="callout callout--ok">
        @include('intake.icon', ['name' => 'check-circle'])
        <div>
            <strong>ชำระเงินเรียบร้อย ยืนยันที่นั่งแล้ว</strong>
            ใบยืนยันการจองส่งไปที่อีเมลของคนจองแล้ว
        </div>
    </div>

    <div class="summary">
        <div><span>เลขที่จอง</span><b>{{ $booking->booking_ref }}</b></div>
        @if ($schedule)
            <div><span>วันเดินทาง</span><b>{{ $schedule->dateRangeLabelThai() }}</b></div>
        @endif
        <div><span>ผู้เดินทาง</span><b>{{ implode(', ', $people) ?: $booking->passengers->count().' คน' }}</b></div>
        @if ($booking->seats->isNotEmpty())
            <div><span>ที่นั่ง</span><b>{{ $booking->seats->pluck('seat_id')->join(', ') }}</b></div>
        @endif
        <div><span>ชำระแล้ว</span><b>฿{{ number_format((float) $booking->paid_amount) }}</b></div>
        @if ($hasBalance)
            <div>
                <span>ยอดคงเหลือ</span>
                <b>฿{{ number_format($balance) }}@if ($booking->balance_due_at) · ภายใน {{ \App\Support\ThaiDate::full($booking->balance_due_at) }}@endif</b>
            </div>
        @endif
    </div>

    @if ($hasApp)
        <div class="callout callout--ok">
            @include('intake.icon', ['name' => 'phone'])
            <div>
                <strong>เปิดแอปลุยเลเขาเพื่อดูการจองนี้</strong>
                ห้องแชทของรอบ QR ขึ้นรถ และกำหนดการ อยู่ในหน้า "การจองของฉัน"
            </div>
        </div>
    @else
        <section class="app">
            <h2>ขั้นต่อไป: โหลดแอปลุยเลเขา</h2>
            <p>ทุกอย่างของทริปนี้อยู่ในแอป ตั้งแต่วันนี้จนถึงวันกลับถึงบ้าน</p>

            <ul class="app-perks">
                <li>@include('intake.icon', ['name' => 'users'])
                    <div><b>ห้องแชทของรอบ</b><span>คุยกับทีมงานและเพื่อนร่วมทริป รับประกาศสำคัญก่อนใคร</span></div></li>
                <li>@include('intake.icon', ['name' => 'check-circle'])
                    <div><b>QR ขึ้นรถของแต่ละคน</b><span>ยื่นให้ทีมงานสแกนตอนขึ้นรถ ไม่ต้องรอเช็กชื่อ</span></div></li>
                <li>@include('intake.icon', ['name' => 'bus'])
                    <div><b>ติดตามรถวันเดินทาง</b><span>เห็นว่ารถถึงไหนแล้ว อีกกี่นาทีถึงจุดรับ</span></div></li>
                <li>@include('intake.icon', ['name' => 'calendar'])
                    <div><b>กำหนดการ ของที่ต้องเตรียม และพยากรณ์อากาศ</b><span>พร้อมแจ้งเตือนก่อนเดินทาง</span></div></li>
                @if ($hasBalance)
                    <li>@include('intake.icon', ['name' => 'lock'])
                        <div><b>ชำระยอดคงเหลือในแอป</b><span>มีแจ้งเตือนก่อนครบกำหนด ไม่ต้องจำเอง</span></div></li>
                @endif
            </ul>

            <div class="stores">
                @if ($iosUrl)
                    <a class="store" href="{{ $iosUrl }}" target="_blank" rel="noopener"><small>iPhone</small>App Store</a>
                @endif
                @if ($androidUrl)
                    <a class="store" href="{{ $androidUrl }}" target="_blank" rel="noopener"><small>Android</small>Google Play</a>
                @endif
            </div>
        </section>

        <p class="lead"><strong>เห็นการจองนี้ในแอปได้อย่างไร</strong></p>
        <ol class="steps">
            <li><span>โหลดแอปแล้วเปิดขึ้นมา</span></li>
            @if ($isShadow)
                {{-- ใบจองอยู่ในบัญชีที่ระบบเปิดให้ (ยังไม่มีรหัสผ่าน) — สองทางเข้าตาม AccountClaimService --}}
                <li><span>คนจองกดลิงก์ <b>เปิดใช้บัญชี</b> ใน SMS @if ($phoneTail !== '') ที่ส่งไปเบอร์ลงท้าย {{ $phoneTail }} @endif
                    ตั้งรหัสผ่านครั้งเดียว การจองจะขึ้นในแอปทันที</span></li>
                <li><span>หรือเข้าแอปด้วย LINE / Google แล้วไปที่ <b>การจองของฉัน</b> → <b>ยังไม่เห็นในแอป?</b>
                    กรอกเลขที่จอง {{ $booking->booking_ref }} กับเบอร์ 4 ตัวท้ายของคนจอง</span></li>
            @elseif ($accountEmail)
                <li><span>คนจองเข้าสู่ระบบด้วยบัญชีเดิม ({{ $accountEmail }}) การจองอยู่ในหน้า <b>การจองของฉัน</b></span></li>
            @endif
            <li><span>เพื่อนในกลุ่มเข้าร่วมการจองได้จากลิงก์เชิญในแอป แต่ละคนจะได้ QR ขึ้นรถของตัวเอง</span></li>
        </ol>
    @endif

    <div class="privacy">
        @include('intake.icon', ['name' => 'lock'])
        <span>หน้านี้แสดงเฉพาะชื่อเล่นและเลขที่จอง ไม่แสดงเบอร์โทร เลขบัตรประชาชน หรือข้อมูลสุขภาพของใคร</span>
    </div>
@endsection
