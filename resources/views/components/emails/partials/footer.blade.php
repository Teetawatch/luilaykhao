{{--
  ท้ายอีเมลชุดเดียวของทุกฉบับ — ช่องทางติดต่ออ่านจาก SiteSettings (แอดมินแก้ได้ที่
  /admin/settings) ชุดเดียวกับใบเดินทาง ไม่ต้องพิมพ์เบอร์/เวลาทำการซ้ำในแต่ละเทมเพลต
  - label/value: บรรทัดอ้างอิง เช่น "หมายเลขการจอง: LLK-…" (ไม่ส่ง = ไม่แสดง)
  - internal: อีเมลถึงทีมงาน ไม่ต้องมีช่องทางติดต่อฝ่ายบริการลูกค้า
--}}
@props(['label' => 'หมายเลขการจอง', 'value' => null, 'internal' => false])

@php
  $supportPhone = \App\Support\SiteSettings::supportPhone() ?: config('company.phone');
  $supportLine  = \App\Support\SiteSettings::supportLine();
  $supportHours = \App\Support\SiteSettings::supportHours();
@endphp

<div class="email-footer">
  <div class="footer-logo">{{ config('company.name', 'ลุยเลเขา') }} (Luilaykhao)</div>
  @if($value)
    <div class="footer-tagline">{{ $label }}: {{ $value }}</div>
  @else
    <div class="footer-tagline">บริการจัดทริปเดินป่าและท่องเที่ยวธรรมชาติ</div>
  @endif
  <div class="footer-divider"></div>
  <div class="footer-disclaimer">
    @if($internal)
      อีเมลแจ้งเตือนภายในสำหรับทีมงาน ส่งจากระบบอัตโนมัติ<br />
    @else
      ฝ่ายบริการลูกค้า โทร <strong>{{ $supportPhone }}</strong>
      @if($supportLine) &middot; LINE <strong>{{ $supportLine }}</strong>@endif
      ({{ $supportHours }})<br />
      @if(config('company.email')){{ config('company.email') }}<br />@endif
      @if(config('company.address')){{ config('company.address') }}<br />@endif
      อีเมลฉบับนี้ส่งจากระบบอัตโนมัติ หากต้องการติดต่อ กรุณาใช้ช่องทางด้านบนครับ<br />
    @endif
    &copy; {{ date('Y') }} {{ config('company.legal_name') ?: config('company.name', 'ลุยเลเขา') }} &middot; สงวนลิขสิทธิ์
  </div>
</div>
