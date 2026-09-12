@php
    /**
     * ใบเดินทางฉบับอีเมล — วาดจาก payload ของ TripBriefService ก้อนเดียวกับหน้า
     * /t/{token} จึงไม่มีทางพูดคนละเรื่องกัน
     *
     * ลำดับของบล็อกเรียงตาม "สิ่งที่คนเปิดอ่านตอนตีสี่ต้องเห็นก่อน":
     * เวลา/จุดขึ้นรถ → เบอร์ที่โทรได้ → รถคันไหน → กำหนดการ → ที่เหลือ
     */
    $emoji       = $isUpdate ? '🔄' : '🧭';
    $bannerTitle = $isUpdate ? 'ใบเดินทางของคุณมีอัปเดตครับ' : 'ใบเดินทางของคุณพร้อมแล้วครับ';
    $headerClass = $isUpdate ? 'hdr-amber' : 'hdr-teal';

    $when   = $b['when'] ?? [];
    $pickup = $b['pickup']['points'] ?? [];
    $meetup = $b['meetup'] ?? null;
    $first  = $pickup[0]['point'] ?? null;

    $pickupTime = $meetup['time_label'] ?? ($first['time'] ?? null);
    if (! $meetup && $pickupTime) {
        $pickupTime = \Illuminate\Support\Str::substr($pickupTime, 0, 5);
    }
@endphp

<x-emails.partials.base subject="{{ $emoji }} {{ $bannerTitle }} — {{ $b['booking']['ref'] }}">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao</span>
    <div class="header-emoji">{{ $emoji }}</div>
    <h1 class="header-title">{{ $b['trip']['title'] }}</h1>
    <p class="header-subtitle">
      {{ $when['range_label'] ?? $when['date_label'] ?? '' }}
      @if($when['countdown_label'] ?? null) &middot; {{ $when['countdown_label'] }} @endif
    </p>
    <div class="ref-badge">{{ $b['booking']['ref'] }}</div>
  </div>

  {{-- Body --}}
  <div class="email-body">

    <div class="greeting">
      สวัสดีคุณ <strong>{{ $b['booking']['customer_name'] ?? 'นักเดินทาง' }}</strong><br />
      @if($isUpdate)
        มีข้อมูลของรอบนี้เปลี่ยนไปนิดหน่อยครับ เลยส่งใบเดินทางฉบับล่าสุดมาให้ใหม่
        ใช้ฉบับนี้แทนฉบับก่อนหน้าได้เลยนะครับ
      @else
        ใกล้ถึงวันเดินทางแล้วครับ ทีมงานรวมทุกอย่างที่ต้องรู้ไว้ให้ในอีเมลฉบับนี้แล้ว
        ทั้งเวลา จุดขึ้นรถ และเบอร์ทีมงานที่โทรหาได้ตลอดช่วงทริป
        <strong>ไม่ต้องโหลดแอปก็ดูได้ครับ</strong>
      @endif
    </div>

    {{-- รถออกก่อนวันทริป: ความเข้าใจผิดที่ทำให้ลูกค้าตกรถมากที่สุด --}}
    @if($when['night_before'] ?? false)
      <div class="alert-box alert-amber">
        <p class="alert-title">⚠️ รถออกก่อนวันทริปนะครับ</p>
        <p class="alert-text">
          รถออกวันที่ <strong>{{ $when['departs_on_label'] }}</strong> เวลา
          <strong>{{ $when['time_label'] }} น.</strong> ไม่ใช่เช้าวันทริป
          เตรียมตัวให้ทันตั้งแต่คืนก่อนหน้านะครับ
        </p>
      </div>
    @endif

    {{-- ── จุดขึ้นรถ / จุดนัดพบ ──────────────────────────────── --}}
    @if($meetup)
      <div class="highlight-box hl-teal">
        <div class="amount-label">✈️ จุดนัดพบ</div>
        @if($pickupTime)<div class="amount">{{ $pickupTime }} น.</div>@endif
        <div class="amount-note">
          <strong>{{ $meetup['point'] ?: 'ทีมงานจะแจ้งจุดนัดพบอีกครั้ง' }}</strong>
          @if($meetup['date_label'])<br />{{ $meetup['date_label'] }}@endif
          @if($meetup['baggage'])<br />🧳 สัมภาระ: {{ $meetup['baggage'] }}@endif
        </div>
      </div>
      @if($meetup['map_url'])
        <div class="cta-wrap">
          <a href="{{ $meetup['map_url'] }}" class="cta-btn cta-teal">🗺 เปิดแผนที่จุดนัดพบ</a>
        </div>
      @endif

    @elseif($first)
      <div class="highlight-box hl-teal">
        <div class="amount-label">🚐 จุดขึ้นรถของคุณ</div>
        @if($pickupTime)<div class="amount">{{ $pickupTime }} น.</div>@endif
        <div class="amount-note">
          <strong>{{ $first['location'] }}</strong>
          @if($first['region'])<br />{{ $first['region'] }}@endif
          @if($first['notes'])<br />{{ $first['notes'] }}@endif
        </div>
      </div>

      {{-- ในใบเดียวกันแต่ละคนขึ้นคนละจุดได้ — ถ้าไม่เหมือนกันต้องบอกให้ครบทุกคน --}}
      @if(! ($b['pickup']['same_for_everyone'] ?? true))
        <div class="info-card">
          <div class="info-card-header"><span class="info-card-title">จุดขึ้นรถของแต่ละท่าน</span></div>
          @foreach($pickup as $row)
            <div class="info-row">
              <span class="info-label">{{ $row['passenger'] ?: 'ผู้เดินทาง' }}</span>
              <span class="info-value">
                {{ $row['point']['location'] }}
                @if($row['point']['time'])<br />{{ \Illuminate\Support\Str::substr($row['point']['time'], 0, 5) }} น.@endif
              </span>
            </div>
          @endforeach
        </div>
      @endif

      @if($first['map_url'])
        <div class="cta-wrap">
          <a href="{{ $first['map_url'] }}" class="cta-btn cta-teal">🗺 เปิดแผนที่จุดขึ้นรถ</a>
        </div>
      @endif

    @elseif($b['booking']['is_join_trip'] ?? false)
      <div class="alert-box alert-neutral">
        <p class="alert-title">🥾 จอยทริป</p>
        <p class="alert-text">รอบนี้คุณจองแบบจอยทริป ไม่มีรถไปรับนะครับ นัดเจอทีมงานที่หน้างานได้เลย</p>
      </div>
    @endif

    {{-- ── เบอร์ที่โทรได้ ────────────────────────────────────── --}}
    @if(! empty($b['crew']) || ($b['vehicle']['driver_name'] ?? null))
      <p class="section-label">ติดต่อได้ที่ใคร</p>
      <div class="info-card">
        @foreach($b['crew'] as $staff)
          <div class="info-row">
            <span class="info-label">ทีมงานประจำรอบ</span>
            <span class="info-value">
              {{ $staff['name'] }}
              @if($staff['phone'])<br /><span class="t-teal">{{ $staff['phone'] }}</span>@endif
            </span>
          </div>
        @endforeach
        @if($b['vehicle']['driver_name'] ?? null)
          <div class="info-row">
            <span class="info-label">คนขับ</span>
            <span class="info-value">
              {{ $b['vehicle']['driver_name'] }}
              @if($b['vehicle']['driver_phone'])<br /><span class="t-teal">{{ $b['vehicle']['driver_phone'] }}</span>@endif
            </span>
          </div>
        @endif
      </div>
      <p class="body-text" style="margin-top:-12px;">
        <span class="t-muted">เบอร์เหล่านี้ใช้ติดต่อเรื่องทริปในช่วงเดินทางนะครับ เรื่องอื่น ๆ ทักทีมงานที่เบอร์กลางได้เลย</span>
      </p>
    @endif

    {{-- ── รถที่มารับ ────────────────────────────────────────── --}}
    @if(($b['vehicle']['plate'] ?? null) || ($b['vehicle']['color'] ?? null))
      <p class="section-label">รถที่มารับ</p>
      <div class="info-card">
        @if($b['booking']['vehicle_option_label'] ?: ($b['vehicle']['name'] ?? null))
          <div class="info-row">
            <span class="info-label">คัน</span>
            <span class="info-value">{{ $b['booking']['vehicle_option_label'] ?: $b['vehicle']['name'] }}</span>
          </div>
        @endif
        @if($b['vehicle']['plate'] ?? null)
          <div class="info-row">
            <span class="info-label">ทะเบียน</span>
            <span class="info-value accent-teal lg">{{ $b['vehicle']['plate'] }}</span>
          </div>
        @endif
        @if($b['vehicle']['color'] ?? null)
          <div class="info-row">
            <span class="info-label">สีรถ</span>
            <span class="info-value">{{ $b['vehicle']['color'] }}</span>
          </div>
        @endif
        @if(! empty($b['booking']['seats']))
          <div class="info-row">
            <span class="info-label">ที่นั่งของคุณ</span>
            <span class="info-value">{{ implode(', ', $b['booking']['seats']) }}</span>
          </div>
        @endif
      </div>
    @endif

    {{-- ── กำหนดการ (ครบทุกข้อ ไม่ตัดท้าย) ──────────────────── --}}
    @if(! empty($b['itinerary']))
      <p class="section-label">กำหนดการ</p>
      <div class="info-card">
        @php $lastDay = null; @endphp
        @foreach($b['itinerary'] as $item)
          @if($item['date_label'] && $item['date_label'] !== $lastDay)
            <div class="info-card-header"><span class="info-card-title">{{ $item['date_label'] }}</span></div>
            @php $lastDay = $item['date_label']; @endphp
          @endif
          <div class="info-row">
            <span class="info-label">{{ $item['time'] ?: '—' }}</span>
            <span class="info-value">
              {{ $item['title'] }}
              @if($item['detail'])<br /><span class="t-muted" style="font-weight:400;">{{ $item['detail'] }}</span>@endif
            </span>
          </div>
        @endforeach
      </div>
      <p class="body-text" style="margin-top:-12px;">
        <span class="t-muted">กำหนดการอาจปรับตามสภาพอากาศและหน้างานนะครับ</span>
      </p>
    @endif

    {{-- ── เตรียมตัว ─────────────────────────────────────────── --}}
    @if($b['trip']['bring'] || ! empty($b['trip']['preparations']))
      <div class="alert-box alert-teal">
        <p class="alert-title">🎒 เตรียมตัวก่อนออกเดินทาง</p>
        <p class="alert-text">
          @if($b['trip']['bring'])
            <strong>สิ่งที่ต้องพก:</strong> {{ $b['trip']['bring'] }}<br />
          @endif
          @foreach(array_slice($b['trip']['preparations'], 0, 8) as $line)
            &middot; {{ is_array($line) ? ($line['title'] ?? '') : $line }}<br />
          @endforeach
        </p>
      </div>
    @endif

    {{-- ── ยอดค้างชำระ ──────────────────────────────────────── --}}
    @if(! $b['payment']['is_settled'])
      <div class="highlight-box hl-amber">
        <div class="amount-label">💳 ยอดที่ยังค้างอยู่</div>
        <div class="amount">฿{{ number_format($b['payment']['outstanding'], 0) }}</div>
        <div class="amount-note">
          @if($b['payment']['due_label'])
            กำหนดชำระ <strong>{{ $b['payment']['due_label'] }}</strong>
            @if($b['payment']['is_overdue']) &middot; <strong>เลยกำหนดแล้ว</strong> @endif
          @else
            ชำระก่อนวันเดินทางนะครับ
          @endif
        </div>
      </div>
      <div class="cta-wrap">
        <a href="{{ $b['links']['pay'] }}" class="cta-btn cta-amber">ชำระเงิน</a>
      </div>
    @endif

    {{-- ── ลิงก์ใบเดินทางฉบับที่อัปเดตตัวเอง ─────────────────── --}}
    <div class="cta-wrap">
      <a href="{{ $b['links']['brief'] }}" class="cta-btn cta-slate">เปิดใบเดินทางฉบับล่าสุด</a>
    </div>
    <p class="body-text" style="text-align:center;">
      <span class="t-muted">
        ลิงก์นี้อัปเดตตัวเองเสมอ ถ้าทีมงานเปลี่ยนรถหรือขยับกำหนดการ เปิดลิงก์นี้จะเห็นของใหม่ทันที<br />
        ส่งต่อให้คนที่บ้านเก็บไว้ได้เลยนะครับ
      </span>
    </p>

    <div class="contact-bar">
      มีอะไรถามได้ตลอดครับ โทร <strong>{{ $b['support']['phone'] }}</strong>
      @if($b['support']['line_id']) &middot; LINE <strong>{{ $b['support']['line_id'] }}</strong> @endif
      <br />{{ $b['support']['hours'] }}
    </div>

  </div>

  {{-- Footer --}}
  <div class="email-footer">
    <div class="footer-logo">Luilaykhao</div>
    <p class="footer-tagline">ลุยเลเขา &middot; ทริปเดินป่าและทะเลที่ออกเดินทางจริงทุกครั้ง</p>
    <div class="footer-divider"></div>
    <p class="footer-disclaimer">
      อีเมลฉบับนี้ส่งถึงคุณเพราะมีการจอง <strong>{{ $b['booking']['ref'] }}</strong> ที่กำลังจะถึงกำหนดเดินทาง<br />
      เบอร์ทีมงานในอีเมลนี้ใช้สำหรับติดต่อเรื่องทริปในช่วงเดินทางเท่านั้น
    </p>
  </div>

</x-emails.partials.base>
