@php
    $stalled = $reason === 'stalled';
    $reopened = $reason === 'reopened';
    $people = $intake->people;
    $schedule = $intake->schedule;

    // หัวเมลสามแบบ — เหตุผลที่ส่งต่างกันคนละเรื่อง คนอ่านต้องรู้ตั้งแต่บรรทัดแรก
    // ว่าต้องไปตามในแชท, หยิบไปเปิดจอง, หรือโทรกลับเพราะการจองเมื่อกี้ไม่สำเร็จ
    $head = match (true) {
        $reopened => ['emoji' => '🔁', 'class' => 'hdr-red', 'title' => 'การจองไม่สำเร็จ ข้อมูลกลับมารอ',
            'subtitle' => 'ใบจองที่เปิดไว้ถูกยกเลิกก่อนได้รับเงิน — ข้อมูลกลุ่มนี้กลับไปอยู่ในหมวด "ยังไม่ได้จอง" ดึงไปเปิดจองใหม่ได้เลย'],
        $stalled => ['emoji' => '⏳', 'class' => 'hdr-amber', 'title' => 'ลูกค้ากรอกค้างไว้',
            'subtitle' => 'กรอกไม่ครบตามที่แจ้งไว้และเงียบไปแล้ว — น่าจะต้องไปตามในแชท'],
        default => ['emoji' => '📝', 'class' => 'hdr-slate', 'title' => 'ลูกค้ากรอกข้อมูลครบแล้ว',
            'subtitle' => 'ข้อมูลครบตามที่แจ้งไว้ หยิบไปเปิดการจองได้เลย'],
    };
@endphp

<x-emails.partials.base subject="{{ $head['emoji'] }} [Admin] {{ $head['title'] }} — {{ $intake->contact_name }}">

  {{-- Header --}}
  <div class="email-header {{ $head['class'] }}">
    <span class="email-brand">Luilaykhao Admin</span>
    <div class="header-emoji">{{ $head['emoji'] }}</div>
    <h1 class="header-title">{{ $head['title'] }}</h1>
    <p class="header-subtitle">{{ $head['subtitle'] }}</p>
    <div class="ref-badge">{{ $intake->contact_phone ?: 'ไม่มีเบอร์' }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      <strong>{{ $intake->contact_name }}</strong>
      @if ($intake->contact_email) &nbsp;&middot;&nbsp; {{ $intake->contact_email }} @endif
      @if ($intake->link?->label) &nbsp;&middot;&nbsp; มาจากลิงก์ "{{ $intake->link->label }}" @endif
    </div>

    @if ($reopened && $intake->booking)
    <div class="alert-box alert-red">
      <p class="alert-title">🔁 การจอง {{ $intake->booking->booking_ref }} ถูกยกเลิก</p>
      <p class="alert-text">
        {{ $intake->booking->cancellation_reason ?: 'ยกเลิกก่อนได้รับชำระเงิน' }}<br />
        ที่นั่งถูกคืนเข้ารอบแล้ว — ถ้าลูกค้ายังอยากไป ดึงกลุ่มนี้ไปเปิดการจองใหม่ได้ทันที ข้อมูลผู้เดินทางยังอยู่ครบ
      </p>
    </div>
    @endif

    <p class="section-label">รอบที่สนใจ</p>
    <div class="info-card">
      <div class="info-row">
        <span class="info-label">ทริป</span>
        <span class="info-value">{{ $schedule?->trip?->title ?? 'ยังไม่ระบุรอบ' }}</span>
      </div>
      @if ($schedule)
      <div class="info-row">
        <span class="info-label">วันเดินทาง</span>
        <span class="info-value">{{ $schedule->dateRangeLabelThai() }}</span>
      </div>
      {{-- คนจอยไม่ได้กินที่นั่งบนรถ ตัวเลขที่นั่งจึงไม่ใช่คำตอบว่ารับกลุ่มนี้ได้ไหม
           — รอบที่รถเต็มแต่โควตาจอยยังว่างต้องไม่ขึ้นว่า "ปิดรับแล้ว" --}}
      @php($roundOpen = $schedule->acceptsBookingType($intake->booking_type))
      <div class="info-row">
        <span class="info-label">{{ $intake->isJoinTrip() ? 'โควตาจอยทริป' : 'ที่นั่ง' }}</span>
        <span class="info-value {{ $roundOpen ? '' : 'accent-amber' }}">
          @if (! $roundOpen)
            รอบนี้ปิดรับแล้ว
          @elseif ($intake->isJoinTrip())
            {{ $schedule->join_trip_available_seats === null
                ? 'ไม่จำกัดจำนวน'
                : 'เหลือ '.$schedule->join_trip_available_seats.' ที่' }}
          @else
            เหลือ {{ $schedule->bookable_seats }} ที่
          @endif
        </span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">ประเภท</span>
        <span class="info-value {{ $intake->isJoinTrip() ? 'accent-amber' : '' }}">
          {{ $intake->bookingTypeLabel() }}{{ $intake->isJoinTrip() ? ' — เดินทางไปเอง ไม่มีรถรับ' : '' }}
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">กรอกแล้ว</span>
        <span class="info-value">{{ $people->count() }} / {{ $intake->party_size }} คน</span>
      </div>
    </div>

    @if ($people->isNotEmpty())
    <p class="section-label">รายชื่อที่กรอกเข้ามา</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>ชื่อ-นามสกุล</th>
            <th style="text-align:right;">โทรศัพท์</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($people as $i => $person)
          <tr>
            <td class="cell-index">{{ $i + 1 }}</td>
            <td class="cell-strong">{{ $person->name }}</td>
            <td class="cell-muted" style="text-align:right;">{{ $person->phone ?: '-' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if ($intake->note)
    <div class="alert-box alert-neutral">
      <p class="alert-title">📝 ลูกค้าฝากบอก</p>
      <p class="alert-text">{{ $intake->note }}</p>
    </div>
    @endif

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/intakes" class="cta-btn cta-slate">
        เปิดหน้าข้อมูลลูกค้าจากลิงก์ &rarr;
      </a>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao Admin</div>
    <div class="footer-tagline">แจ้งเตือนอัตโนมัติจากระบบ</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ถูกส่งอัตโนมัติเฉพาะ Admin เท่านั้น<br />
      ไม่มีเลขบัตรประชาชนหรือข้อมูลสุขภาพในอีเมลนี้ — ดูได้ในหน้าแอดมินเท่านั้น<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; ระบบจัดการภายใน
    </div>
  </div>

</x-emails.partials.base>
