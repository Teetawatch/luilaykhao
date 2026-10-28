{{--
  ทริปที่อุทยานให้ผู้เดินทางเซ็นแบบฟอร์มก่อนลงทะเบียน — ปุ่มไปใบเดินทาง #permit ซึ่งมี
  ลิงก์เซ็นของแต่ละคน (ใช้ได้โดยไม่ต้องล็อกอิน คนจองส่งต่อให้เพื่อนได้)
  แสดงเฉพาะอีเมลของคนจอง เพื่อนที่ได้ใบเสร็จของตัวเองไม่ต้องเห็นปุ่มของทั้งกลุ่ม
--}}
@props(['booking', 'recipientName' => null])

@php
    $permits = app(\App\Services\PermitSignatureService::class);
    $permitForm = $recipientName ? null : $permits->formNeedingSignature($booking);
    $showPermit = $permitForm && $permits->isOpen($booking);
    $permitDeadline = $showPermit ? $permits->deadline($booking, $permitForm) : null;
    if ($showPermit) {
        $booking->ensureBriefToken();
    }
@endphp

@if($showPermit)
  <div class="alert-box alert-amber">
    <p class="alert-title">ทริปนี้ต้องลงนามในแบบฟอร์มของอุทยาน</p>
    <p class="alert-text">
      อุทยานกำหนดให้ผู้เดินทางทุกท่านลงนามในแบบฟอร์มก่อนลงทะเบียน ทีมงานได้กรอกข้อมูลจากการจองลงในแบบฟอร์มไว้แล้ว
      คุณเพียงอ่านและลงนามผ่านโทรศัพท์มือถือครับ
      @if($permitDeadline)
        <strong>กรุณาลงนามภายใน {{ \App\Support\ThaiDate::full($permitDeadline) }}</strong>
      @endif
      @if($booking->passengers->whereNull('not_going_at')->count() > 1)
        — ผู้เดินทางท่านอื่นในการจองนี้สามารถลงนามของตนเองผ่านลิงก์ในหน้าดังกล่าวได้
      @endif
    </p>
  </div>
  <div class="cta-wrap">
    <a href="{{ $booking->briefUrl() }}#permit" class="cta-btn cta-amber">อ่านและลงนามแบบฟอร์มอุทยาน</a>
  </div>
@endif
