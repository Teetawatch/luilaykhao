<x-emails.partials.base subject="🔒 [Admin] ค้างปิดงบ {{ count($rounds) }} รอบเดินทาง">

  {{-- Header --}}
  <div class="email-header hdr-amber">
    <span class="email-brand">Luilaykhao Admin</span>
    <div class="header-emoji">🔒</div>
    <h1 class="header-title">ค้างปิดงบ {{ count($rounds) }} รอบ</h1>
    <p class="header-subtitle">
      รอบเหล่านี้เดินทางจบไปเกิน {{ $graceDays }} วันแล้ว แต่ยังไม่มีใครปิดงบ
      — กำไรที่เห็นในระบบตอนนี้จึงยังไม่ใช่ตัวเลขจริง
    </p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    @if ($blocksNewRounds)
    <div class="alert-box alert-neutral">
      <p class="alert-title">⛔ เปิดรอบใหม่ของทริปเหล่านี้ไม่ได้</p>
      <p class="alert-text">
        ระบบตั้งค่าให้ทริปที่ค้างปิดงบเปิดรอบใหม่ไม่ได้จนกว่าจะเคลียร์
        ถ้าต้องเปิดขายรอบถัดไป ต้องปิดงบรอบเดิมก่อน
      </p>
    </div>
    @endif

    <p class="section-label">รอบที่ค้างอยู่</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th>ทริป / รอบ</th>
            <th style="text-align:right;">จบมาแล้ว</th>
          </tr>
        </thead>
        <tbody>
          @foreach ($rounds as $round)
          <tr>
            <td>
              <span class="cell-strong">{{ $round['trip_title'] }}</span><br />
              <span class="cell-muted">
                {{ $round['departure_label'] }}
                @if ($round['expense_items_count'] === 0)
                  &middot; ยังไม่มีรายการค่าใช้จ่ายเลย
                @endif
              </span>
            </td>
            <td class="cell-muted" style="text-align:right;">{{ $round['days_since_end'] }} วัน</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.url'), '/') }}/admin/finance" class="cta-btn cta-slate">
        เปิดหน้าบัญชีทริป &rarr;
      </a>
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao Admin</div>
    <div class="footer-tagline">แจ้งเตือนอัตโนมัติจากระบบ</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ส่งถึงผู้ดูแลและผู้มีสิทธิ์การเงินเท่านั้น<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; ระบบจัดการภายใน
    </div>
  </div>

</x-emails.partials.base>
