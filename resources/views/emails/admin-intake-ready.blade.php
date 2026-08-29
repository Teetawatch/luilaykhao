@php
    $stalled = $reason === 'stalled';
    $people = $intake->people;
    $schedule = $intake->schedule;
@endphp

<x-emails.partials.base subject="{{ $stalled ? '⏳ [Admin] ลูกค้ากรอกค้างไว้' : '📝 [Admin] ลูกค้ากรอกข้อมูลครบแล้ว' }} — {{ $intake->contact_name }}">

  {{-- Header --}}
  <div class="email-header {{ $stalled ? 'hdr-amber' : 'hdr-slate' }}">
    <span class="email-brand">Luilaykhao Admin</span>
    <div class="header-emoji">{{ $stalled ? '⏳' : '📝' }}</div>
    <h1 class="header-title">{{ $stalled ? 'ลูกค้ากรอกค้างไว้' : 'ลูกค้ากรอกข้อมูลครบแล้ว' }}</h1>
    <p class="header-subtitle">
      {{ $stalled
          ? 'กรอกไม่ครบตามที่แจ้งไว้และเงียบไปแล้ว — น่าจะต้องไปตามในแชท'
          : 'ข้อมูลครบตามที่แจ้งไว้ หยิบไปเปิดการจองได้เลย' }}
    </p>
    <div class="ref-badge">{{ $intake->contact_phone ?: 'ไม่มีเบอร์' }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      <strong>{{ $intake->contact_name }}</strong>
      @if ($intake->contact_email) &nbsp;&middot;&nbsp; {{ $intake->contact_email }} @endif
      @if ($intake->link?->label) &nbsp;&middot;&nbsp; มาจากลิงก์ "{{ $intake->link->label }}" @endif
    </div>

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
      <div class="info-row">
        <span class="info-label">ที่นั่ง</span>
        <span class="info-value {{ $schedule->acceptsNewCustomers() ? '' : 'accent-amber' }}">
          {{ $schedule->acceptsNewCustomers() ? 'เหลือ '.$schedule->bookable_seats.' ที่' : 'รอบนี้ปิดรับแล้ว' }}
        </span>
      </div>
      @endif
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
