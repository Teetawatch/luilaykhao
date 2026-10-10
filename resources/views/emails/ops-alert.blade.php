@php
  $hasProblems = count($failing) > 0;
@endphp
<x-emails.partials.base :subject="$hasProblems ? '🚨 [Admin] ระบบเบื้องหลังมีปัญหา' : '✅ [Admin] ระบบเบื้องหลังกลับมาปกติ'">

  {{-- Header --}}
  <div class="email-header {{ $hasProblems ? 'hdr-red' : 'hdr-green' }}">
    <span class="email-brand">Luilaykhao Admin</span>
    <div class="header-emoji">{{ $hasProblems ? '🚨' : '✅' }}</div>
    <h1 class="header-title">
      {{ $hasProblems ? 'ระบบเบื้องหลังมีปัญหา '.count($failing).' รายการ' : 'ระบบเบื้องหลังกลับมาปกติแล้ว' }}
    </h1>
    <p class="header-subtitle">
      @if ($hasProblems)
        งานอัตโนมัติ (อีเมล SMS แจ้งเตือนลูกค้า) อาจไม่ได้ทำงาน — ลูกค้าจะไม่ได้รับอะไรเลยจนกว่าจะแก้
      @else
        ทุกรายการที่แจ้งไว้ก่อนหน้ากลับมาทำงานตามปกติ
      @endif
    </p>
  </div>

  {{-- Body --}}
  <div class="email-body">

    @if ($hasProblems)
    <p class="section-label">ที่ยังมีปัญหาอยู่</p>
    <div class="table-wrap">
      <table class="data-table">
        <tbody>
          @foreach ($failing as $check)
          <tr>
            <td>
              <span class="cell-strong">✗ {{ $check['label'] }}</span><br />
              <span class="cell-muted">{{ $check['detail'] }}</span>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if (count($recovered) > 0)
    <p class="section-label">กลับมาปกติแล้ว</p>
    <div class="table-wrap">
      <table class="data-table">
        <tbody>
          @foreach ($recovered as $check)
          <tr>
            <td>
              <span class="cell-strong">✓ {{ $check['label'] }}</span><br />
              <span class="cell-muted">{{ $check['detail'] }}</span>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    @if ($hasProblems)
    <div class="alert-box alert-neutral">
      <p class="alert-title">🔧 เช็คบนเซิร์ฟเวอร์</p>
      <p class="alert-text">
        cd /var/www/luilaykhao<br />
        sudo -u www-data php artisan ops:doctor<br />
        sudo supervisorctl status
      </p>
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim((string) $appUrl, '/') }}/horizon" class="cta-btn cta-red">
        เปิด Horizon &rarr;
      </a>
    </div>
    @endif

    <p class="cell-muted" style="margin-top:16px;">
      เครื่อง {{ $host }} · ตรวจเมื่อ {{ $checkedAt->format('d/m/Y H:i') }} น.
    </p>
  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao Admin</div>
    <div class="footer-tagline">แจ้งเตือนอัตโนมัติจากระบบ</div>
    <div class="footer-divider"></div>
    <div class="footer-disclaimer">
      อีเมลนี้ส่งถึงผู้ดูแลระบบเท่านั้น<br />
      &copy; {{ date('Y') }} Luilaykhao &middot; ระบบจัดการภายใน
    </div>
  </div>

</x-emails.partials.base>
