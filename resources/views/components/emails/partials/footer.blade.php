{{--
  ท้ายอีเมลชุดเดียวของทุกฉบับ — ช่องทางติดต่ออ่านจาก SiteSettings (แอดมินแก้ได้ที่
  /admin/settings) ชุดเดียวกับใบเดินทาง ไม่ต้องพิมพ์เบอร์/เวลาทำการซ้ำในแต่ละเทมเพลต
  - label/value: บรรทัดอ้างอิง เช่น "หมายเลขการจอง: LLK-…" (ไม่ส่ง = ไม่แสดง)
  - internal: อีเมลถึงทีมงาน ไม่ต้องมีช่องทางติดต่อฝ่ายบริการลูกค้า
--}}
@props(['label' => __('หมายเลขการจอง'), 'value' => null, 'internal' => false])

@php
  $supportPhone = \App\Support\SiteSettings::supportPhone() ?: config('company.phone');
  $supportLine  = \App\Support\SiteSettings::supportLine();
  $supportHours = \App\Support\SiteSettings::supportHours();
  // อีเมลภาษาอังกฤษ: ชื่อบริษัทภาษาไทยอ่านไม่ออก — ใช้ชื่อแบรนด์ภาษาอังกฤษแทน
  $english = \App\Support\AppLocale::isEnglish();
  $brandName = $english ? 'Luilaykhao' : config('company.name', 'ลุยเลเขา').' (Luilaykhao)';
  $legalName = $english ? (config('company.legal_name_en') ?: 'Luilaykhao') : (config('company.legal_name') ?: config('company.name', 'ลุยเลเขา'));
@endphp

<div class="email-footer">
  <div class="footer-logo">{{ $brandName }}</div>
  @if($value)
    <div class="footer-tagline">{{ $label }}: {{ $value }}</div>
  @else
    <div class="footer-tagline">{{ __('บริการจัดทริปเดินป่าและท่องเที่ยวธรรมชาติ') }}</div>
  @endif
  <div class="footer-divider"></div>
  <div class="footer-disclaimer">
    @if($internal)
      {{ __('อีเมลแจ้งเตือนภายในสำหรับทีมงาน ส่งจากระบบอัตโนมัติ') }}<br />
    @else
      {{ __('ฝ่ายบริการลูกค้า โทร') }} <strong>{{ $supportPhone }}</strong>
      @if($supportLine) &middot; LINE <strong>{{ $supportLine }}</strong>@endif
      ({{ __($supportHours) }})<br />
      @if(config('company.email')){{ config('company.email') }}<br />@endif
      @if(config('company.address')){{ config('company.address') }}<br />@endif
      {{ __('อีเมลฉบับนี้ส่งจากระบบอัตโนมัติ หากต้องการติดต่อ กรุณาใช้ช่องทางด้านบนครับ') }}<br />
    @endif
    {{ __('© :date :config · สงวนลิขสิทธิ์', ['date' => date('Y'), 'config' => $legalName]) }}
  </div>
</div>
