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
    <p class="alert-title">✍️ ทริปนี้ต้องเซ็นแบบฟอร์มอุทยาน</p>
    <p class="alert-text">
      อุทยานให้ผู้เดินทางทุกคนเซ็นแบบฟอร์มก่อนลงทะเบียน เรากรอกข้อมูลที่ให้ไว้ตอนจองลงแบบฟอร์มแล้ว
      แค่อ่านและเซ็นชื่อบนมือถือครับ
      @if($permitDeadline)
        <strong>กรุณาเซ็นภายใน {{ \App\Support\ThaiDate::full($permitDeadline) }}</strong>
      @endif
      @if($booking->passengers->whereNull('not_going_at')->count() > 1)
        — เพื่อน ๆ ในการจองนี้เซ็นของตัวเองผ่านลิงก์ในหน้านั้นได้เลยครับ
      @endif
    </p>
  </div>
  <div class="cta-wrap">
    <a href="{{ $booking->briefUrl() }}#permit" class="cta-btn cta-amber">✍️ อ่านและเซ็นแบบฟอร์มอุทยาน</a>
  </div>
@endif
