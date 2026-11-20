<x-emails.partials.base :subject="__('ได้รับการจอง :booking_ref', ['booking_ref' => $booking->booking_ref])">

  {{-- Header --}}
  <div class="email-header hdr-teal">
    <span class="email-brand">Luilaykhao</span>
    <h1 class="header-title">{{ __('ได้รับการจองของคุณเรียบร้อยแล้ว') }}</h1>
    <p class="header-subtitle">
      @if($booking->isOnHold())
        {{ __('ทีมงานได้สำรองที่นั่งไว้ให้คุณแล้ว') }}
      @else
        {{ __('กรุณาชำระเงินภายในเวลาที่กำหนดเพื่อยืนยันที่นั่ง') }}
      @endif
    </p>
    <div class="ref-badge">{{ $booking->booking_ref }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      {{ __('เรียน คุณ') }}<strong>{{ $booking->user->name }}</strong><br />
      @if($booking->isOnHold())
        {{ __('ขอบคุณที่เลือกเดินทางกับลุยเลเขาครับ ทีมงานได้บันทึกการจองของคุณ และสำรองที่นั่งไว้ให้จนถึงเวลาที่ระบุด้านล่าง คุณสามารถชำระเงินภายในช่วงเวลาดังกล่าวได้ครับ') }}
      @else
        {{ __('ขอบคุณที่เลือกเดินทางกับลุยเลเขาครับ ทีมงานได้บันทึกการจองของคุณเรียบร้อยแล้ว กรุณาชำระเงินภายในเวลาที่กำหนด เพื่อยืนยันที่นั่งของคุณครับ') }}
      @endif
    </div>

    @if($booking->isOnHold())
    <div class="alert-box alert-teal">
      <p class="alert-title">{{ __('การสำรองที่นั่ง') }}</p>
      <p class="alert-text">
        {{ __('ที่นั่งของคุณได้รับการสำรองไว้จนถึง') }}
        <strong>{{ __(':settimezone น.', ['settimezone' => \App\Support\AppLocale::shortDateTime($booking->hold_until->setTimezone('Asia/Bangkok'))]) }}</strong>
        {{ __('กรุณาชำระเงินภายในเวลาดังกล่าว หากต้องการขยายเวลาหรือต้องการความช่วยเหลือ สามารถติดต่อทีมงานได้ตลอดครับ') }}
      </p>
    </div>
    @endif

    <p class="section-label">{{ __('รายละเอียดทริป') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเดินทาง') }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ทริป / กิจกรรม') }}</span>
        <span class="info-value">{{ $booking->schedule?->trip?->tr('title') ?? '-' }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('วันเดินทาง') }}</span>
        <span class="info-value">{{ $booking->schedule?->departureLabel() ?? '-' }}</span>
      </div>
      @if($booking->pickupPoint || $booking->pickup_region)
      <div class="pickup-block">
        <div class="pickup-label">{{ __('จุดรับ') }}</div>
        @if($booking->pickupPoint)
          <div class="pickup-location">{{ $booking->pickupPoint->tr('pickup_location') }}</div>
          @if($booking->pickupPoint->tr('region_label') ?? $booking->pickup_region)
          <div class="pickup-region">{{ $booking->pickupPoint->tr('region_label') ?? $booking->pickup_region }}</div>
          @endif
        @else
          <div class="pickup-location">{{ $booking->pickup_region }}</div>
        @endif
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('จำนวนผู้เดินทาง') }}</span>
        <span class="info-value">{{ __(':count ท่าน', ['count' => $booking->passengers->count()]) }}</span>
      </div>
      @if($booking->is_join_trip)
      <div class="info-row">
        <span class="info-label">{{ __('ประเภทการจอง') }}</span>
        <span class="info-value accent-teal">Join Trip</span>
      </div>
      @endif
    </div>

    <p class="section-label">{{ __('รายละเอียดการชำระเงิน') }}</p>
    <div class="info-card">
      <div class="info-card-header">
        <span class="info-card-title">{{ __('ข้อมูลการเงิน') }}</span>
      </div>
      @if($booking->discount_amount > 0)
      <div class="info-row">
        <span class="info-label">{{ __('ราคาก่อนส่วนลด') }}</span>
        <span class="info-value">฿{{ number_format($booking->total_amount + $booking->discount_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('ส่วนลด (:promotion_code)', ['promotion_code' => $booking->promotion_code]) }}</span>
        <span class="info-value accent-teal">-฿{{ number_format($booking->discount_amount, 0) }}</span>
      </div>
      @endif
      <div class="info-row">
        <span class="info-label">{{ __('ยอดรวมทั้งหมด') }}</span>
        <span class="info-value accent-teal lg">฿{{ number_format($booking->total_amount, 0) }}</span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('รูปแบบการชำระ') }}</span>
        <span class="info-value">
          @if($booking->payment_type === 'installment')
            {{ __('ผ่อนชำระ :installment_count งวด', ['installment_count' => $booking->installment_count]) }}
          @elseif($booking->payment_type === 'deposit')
            {{ __('วางมัดจำ') }}
          @else
            {{ __('ชำระเต็มจำนวน') }}
          @endif
        </span>
      </div>
      <div class="info-row">
        <span class="info-label">{{ __('สถานะ') }}</span>
        <span class="info-value accent-amber">{{ $booking->isOnHold() ? __('สำรองที่นั่งแล้ว รอชำระเงิน') : __('รอชำระเงิน') }}</span>
      </div>
    </div>

    @if($booking->passengers->count() > 0)
    <p class="section-label">{{ __('รายชื่อผู้เดินทาง') }}</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th style="width:40px;">#</th>
            <th>{{ __('ชื่อ-นามสกุล') }}</th>
            <th style="text-align:right;">{{ __('โทรศัพท์') }}</th>
          </tr>
        </thead>
        <tbody>
          @foreach($booking->passengers as $i => $p)
          <tr>
            <td class="cell-index">{{ $i + 1 }}</td>
            <td class="cell-strong">{{ $p->name }}</td>
            <td class="cell-muted" style="text-align:right;">{{ $p->phone ?? '-' }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @endif

    {{--
      ทวนเงื่อนไขจากหลักฐานที่บันทึกไว้กับใบจองนี้ ไม่ใช่พิมพ์เอง — ฉบับที่เคย
      พิมพ์ไว้ตรงนี้บอก "แจ้งเลื่อนล่วงหน้า 45 วัน" ขณะที่เงื่อนไขจริงคือ 30
      อีเมลในกล่องของลูกค้าเองคือหลักฐานที่เถียงยากที่สุด จึงต้องตรงทุกตัวอักษร

      ใบที่ไม่ได้กดยอมรับ (แอดมินจองแทน) ต้องไม่บอกว่า "คุณได้ยอมรับไว้"
    --}}
    @php($terms = \App\Support\TermsAcceptanceSummary::forBooking($booking))
    <div class="alert-box alert-neutral">
      @if($terms['status'] !== 'none' && ! empty($terms['lines']))
        <p class="alert-title">{{ __('เงื่อนไขการจองที่คุณได้ยอมรับไว้') }}</p>
        <p class="alert-text">
          @if(\App\Support\AppLocale::isEnglish())
            <span class="t-muted">{{ __('ข้อความด้านล่างคือเงื่อนไขฉบับภาษาไทยที่มีผลผูกพัน คำแปลภาษาอังกฤษอยู่ที่หน้าเงื่อนไขการให้บริการบนเว็บไซต์') }}</span><br />
          @endif
          @foreach($terms['lines'] as $i => $line)
            {{ $i + 1 }}.&nbsp;{{ $line }}<br />
          @endforeach
          <span class="t-muted">
            {{ __('ยอมรับเงื่อนไขฉบับวันที่ :terms เมื่อ :settimezone น.', ['terms' => \App\Support\AppLocale::fullDate(\Illuminate\Support\Carbon::parse($terms['version'])), 'settimezone' => \App\Support\AppLocale::shortDateTime(\Illuminate\Support\Carbon::parse($terms['accepted_at'])->setTimezone('Asia/Bangkok'))]) }}
            @if(! empty($terms['channel_label'])) {{ __('ผ่าน:terms', ['terms' => $terms['channel_label']]) }}@endif
          </span>
        </p>
      @else
        <p class="alert-title">{{ __('เงื่อนไขการจอง') }}</p>
        <p class="alert-text">
          @if(\App\Support\AppLocale::isEnglish())
            <span class="t-muted">{{ __('ข้อความด้านล่างคือเงื่อนไขฉบับภาษาไทยที่มีผลผูกพัน คำแปลภาษาอังกฤษอยู่ที่หน้าเงื่อนไขการให้บริการบนเว็บไซต์') }}</span><br />
          @endif
          @foreach(\App\Support\LegalPolicy::bookingTerms() as $i => $line)
            {{ $i + 1 }}.&nbsp;{{ $line }}<br />
          @endforeach
        </p>
      @endif
    </div>

    <div class="cta-wrap">
      <a href="{{ rtrim(config('app.frontend_url', config('app.url')), '/') }}/bookings/{{ $booking->booking_ref }}"
         class="cta-btn cta-teal">
        {{ __('ดูรายละเอียดและชำระเงิน') }}
      </a>
    </div>

    {{--
      ชวนเข้าห้องแชท ไม่ใช่ชวนโหลดแอป — ของที่ลูกค้าอยากได้คือเพื่อนร่วมทาง
      ไม่ใช่ไอคอนเพิ่มในเครื่อง และห้องแชทของรอบเป็นสิ่งเดียวในแอปที่เว็บให้ไม่ได้

      ขึ้นเฉพาะคนที่ยังไม่เคยเปิดแอป (ดู AppLinks::hasApp) — คนที่มีอยู่แล้วเห็น
      ของตัวเองถูกขายซ้ำทุกใบจอง จะเริ่มข้ามอีเมลจากเราทั้งฉบับ
    --}}
    @if (! \App\Support\AppLinks::hasApp($booking->user))
      <div class="alert-box alert-teal">
        <p class="alert-title">{{ __('ห้องแชทของรอบเดินทางในแอปลุยเลเขา') }}</p>
        <p class="alert-text">
          {{ __('ทุกรอบเดินทางมีห้องแชทสำหรับสอบถามทีมงานและติดต่อกับผู้ร่วมเดินทาง ในแอปยังมีข้อมูลติดต่อทีมงาน QR สำหรับเช็คอินหน้างาน และการติดตามตำแหน่งรถแบบเรียลไทม์ในวันเดินทางครับ') }}<br />
          <span class="t-muted">
            {{ __('ดาวน์โหลดได้ที่') }}
            <a href="{{ \App\Support\AppLinks::ios() }}" class="t-teal"><strong>App Store</strong></a>
            {{ __('หรือ') }}
            <a href="{{ \App\Support\AppLinks::android() }}" class="t-teal"><strong>Google Play</strong></a>
          </span>
        </p>
      </div>
    @endif

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$booking->booking_ref" />

</x-emails.partials.base>
