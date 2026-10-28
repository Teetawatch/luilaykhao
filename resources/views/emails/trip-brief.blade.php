@php
    /**
     * ใบเดินทางฉบับอีเมล — วาดจาก payload ของ TripBriefService ก้อนเดียวกับหน้า
     * /t/{token} จึงไม่มีทางพูดคนละเรื่องกัน
     *
     * ลำดับของบล็อกเรียงตาม "สิ่งที่คนเปิดอ่านตอนตีสี่ต้องเห็นก่อน":
     * เวลา/จุดขึ้นรถ → เบอร์ที่โทรได้ → รถคันไหน → กำหนดการ → ที่เหลือ
     */
    $bannerTitle = $isUpdate ? 'ใบเดินทางฉบับปรับปรุง' : 'ใบเดินทางของคุณ';
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

<x-emails.partials.base subject="{{ $bannerTitle }} {{ $b['booking']['ref'] }}">

  {{-- Header --}}
  <div class="email-header {{ $headerClass }}">
    <span class="email-brand">Luilaykhao &middot; {{ $bannerTitle }}</span>
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
      เรียน คุณ<strong>{{ $b['booking']['customer_name'] ?? 'นักเดินทาง' }}</strong><br />
      @if($isUpdate)
        ข้อมูลของรอบเดินทางนี้มีการปรับปรุง ทีมงานจึงขอส่งใบเดินทางฉบับล่าสุดมาให้
        กรุณาใช้ฉบับนี้แทนฉบับก่อนหน้าครับ
      @else
        ใกล้ถึงวันเดินทางแล้วครับ ทีมงานได้รวบรวมข้อมูลที่จำเป็นสำหรับการเดินทางไว้ในอีเมลฉบับนี้
        ได้แก่ เวลา จุดขึ้นรถ และเบอร์ติดต่อทีมงานตลอดช่วงทริป
        <strong>โดยไม่จำเป็นต้องติดตั้งแอปครับ</strong>
      @endif
    </div>

    {{-- รถออกก่อนวันทริป: ความเข้าใจผิดที่ทำให้ลูกค้าตกรถมากที่สุด --}}
    @if($when['night_before'] ?? false)
      <div class="alert-box alert-amber">
        <p class="alert-title">โปรดทราบ: รถออกเดินทางก่อนวันทริป</p>
        <p class="alert-text">
          รถออกเดินทางวันที่ <strong>{{ $when['departs_on_label'] }}</strong> เวลา
          <strong>{{ $when['time_label'] }} น.</strong> ไม่ใช่เช้าวันทริป
          กรุณาเตรียมตัวให้พร้อมตั้งแต่คืนก่อนหน้าครับ
        </p>
      </div>
    @endif

    {{-- ── จุดขึ้นรถ / จุดนัดพบ ──────────────────────────────── --}}
    @if($meetup)
      <div class="highlight-box hl-teal">
        <div class="amount-label">จุดนัดพบ</div>
        @if($pickupTime)<div class="amount">{{ $pickupTime }} น.</div>@endif
        <div class="amount-note">
          <strong>{{ $meetup['point'] ?: 'ทีมงานจะแจ้งจุดนัดพบอีกครั้ง' }}</strong>
          @if($meetup['date_label'])<br />{{ $meetup['date_label'] }}@endif
          @if($meetup['baggage'])<br />สัมภาระ: {{ $meetup['baggage'] }}@endif
        </div>
      </div>
      @if($meetup['map_url'])
        <div class="cta-wrap">
          <a href="{{ $meetup['map_url'] }}" class="cta-btn cta-teal">เปิดแผนที่จุดนัดพบ</a>
        </div>
      @endif

    @elseif($first)
      <div class="highlight-box hl-teal">
        <div class="amount-label">จุดขึ้นรถของคุณ</div>
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
          <a href="{{ $first['map_url'] }}" class="cta-btn cta-teal">เปิดแผนที่จุดขึ้นรถ</a>
        </div>
      @endif

    @elseif($b['booking']['is_join_trip'] ?? false)
      <div class="alert-box alert-neutral">
        <p class="alert-title">จอยทริป</p>
        <p class="alert-text">การจองนี้เป็นแบบจอยทริป ซึ่งไม่มีบริการรถรับส่ง กรุณาเดินทางไปพบทีมงาน ณ จุดนัดหมายครับ</p>
      </div>
    @endif

    {{-- ── เพิ่มลงปฏิทิน ─────────────────────────────────────── --}}
    @if($when['start_date'] ?? null)
      <div class="cta-wrap">
        <a href="{{ $b['links']['calendar'] }}" class="cta-btn cta-slate">เพิ่มลงปฏิทิน</a>
      </div>
      <p class="body-text" style="text-align:center;margin-top:-8px;">
        <span class="t-muted">ปฏิทินในโทรศัพท์จะแจ้งเตือนล่วงหน้าก่อนวันเดินทาง</span>
      </p>
    @endif

    {{-- ── ประกาศจากผู้จัด ──────────────────────────────────────
         ลูกค้ากลุ่มที่ได้อีเมลฉบับนี้คือกลุ่มที่ไม่ได้โหลดแอป ประกาศที่ทีมงาน
         โพสต์ไว้จึงไม่เคยไปถึงเขาเลยก่อนหน้านี้ --}}
    @if(! empty($b['announcements']))
      <p class="section-label">ประกาศจากทีมงาน</p>
      <div class="info-card">
        @foreach(array_slice($b['announcements'], 0, 5) as $ann)
          <div class="info-row">
            <span class="info-label">{{ $ann['category_label'] }}@if($ann['is_pinned'])<br /><span class="t-muted">ปักหมุด</span>@endif</span>
            <span class="info-value">
              {{ $ann['title'] }}<br />
              <span class="t-muted" style="font-weight:400;">{{ $ann['body'] }}</span>
            </span>
          </div>
        @endforeach
      </div>
      @if(count($b['announcements']) > 5)
        <p class="body-text" style="margin-top:-12px;">
          <span class="t-muted">ยังมีประกาศอีก {{ count($b['announcements']) - 5 }} ฉบับ สามารถอ่านทั้งหมดได้ในใบเดินทางออนไลน์</span>
        </p>
      @endif
    @endif

    {{-- ── เบอร์ที่โทรได้ ────────────────────────────────────── --}}
    @if(! empty($b['crew']) || ($b['vehicle']['driver_name'] ?? null))
      <p class="section-label">ผู้ติดต่อระหว่างการเดินทาง</p>
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
        <span class="t-muted">หมายเลขเหล่านี้สำหรับติดต่อเรื่องทริปในช่วงเดินทางเท่านั้น เรื่องอื่น ๆ กรุณาติดต่อฝ่ายบริการลูกค้าตามช่องทางด้านล่าง</span>
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
        <span class="t-muted">กำหนดการอาจมีการปรับเปลี่ยนตามสภาพอากาศและสถานการณ์หน้างาน</span>
      </p>
    @endif

    {{-- ── มื้ออาหาร ─────────────────────────────────────────── --}}
    @if(! empty($b['trip']['meals']))
      @php $meals = $b['trip']['meals']; @endphp
      <p class="section-label">มื้ออาหาร · {{ $meals['summary'] }}</p>
      <div class="info-card">
        @foreach($meals['days'] as $day)
          <div class="info-row">
            <span class="info-label">{{ $day['label'] }}@if($day['date_label'] && count($meals['days']) > 1)<br />{{ $day['date_label'] }}@endif</span>
            <span class="info-value">{{ $day['line'] }}</span>
          </div>
        @endforeach
      </div>
      @if($meals['self_count'] > 0 || $meals['note'])
        <p class="body-text" style="margin-top:-12px;">
          <span class="t-muted">
            @if($meals['self_count'] > 0)กรุณาเตรียมเงินสำหรับมื้อที่ชำระเอง ({{ $meals['self_count'] }} มื้อ)@endif
            @if($meals['self_count'] > 0 && $meals['note'])<br />@endif
            {{ $meals['note'] }}
          </span>
        </p>
      @endif
    @endif

    {{-- ── เตรียมตัว ─────────────────────────────────────────── --}}
    @if($b['trip']['bring'] || ! empty($b['trip']['preparations']))
      <div class="alert-box alert-teal">
        <p class="alert-title">การเตรียมตัวก่อนออกเดินทาง</p>
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

    {{-- ── ยังขาดข้อมูลอะไรอยู่ ─────────────────────────────── --}}
    @if(! empty($b['todo']))
      <div class="alert-box alert-amber">
        <p class="alert-title">ข้อมูลที่ยังไม่ครบถ้วน</p>
        <p class="alert-text">
          @foreach($b['todo'] as $item)
            <strong>{{ $item['title'] }}</strong> &middot; {{ $item['detail'] }}
            @if($item['url'])
              <br /><a href="{{ $item['url'] }}" class="t-teal"><strong>{{ $item['cta'] }}</strong></a>
            @endif
            @if(! $loop->last)<br /><br />@endif
          @endforeach
        </p>
      </div>
      <p class="body-text" style="margin-top:-12px;">
        <span class="t-muted">ลิงก์ในส่วนนี้ใช้สำหรับการจองของคุณโดยเฉพาะ หากส่งเอกสารผ่านช่องทางอื่นแล้ว สามารถข้ามส่วนนี้ได้</span>
      </p>
    @endif

    {{-- ── ยอดค้างชำระ ──────────────────────────────────────── --}}
    @if(! $b['payment']['is_settled'])
      <div class="highlight-box hl-amber">
        <div class="amount-label">ยอดค้างชำระ</div>
        <div class="amount">฿{{ number_format($b['payment']['outstanding'], 0) }}</div>
        <div class="amount-note">
          @if($b['payment']['due_label'])
            กำหนดชำระ <strong>{{ $b['payment']['due_label'] }}</strong>
            @if($b['payment']['is_overdue']) &middot; <strong>เลยกำหนดแล้ว</strong> @endif
          @else
            กรุณาชำระก่อนวันเดินทาง
          @endif
        </div>
      </div>
      <div class="cta-wrap">
        <a href="{{ $b['links']['pay'] }}" class="cta-btn cta-amber">ชำระเงิน</a>
      </div>
    @endif

    {{-- ── ชวนโหลดแอป (เฉพาะคนที่ยังไม่มี) ──────────────────── --}}
    @if($b['app']['show'])
      <div class="alert-box alert-blue">
        <p class="alert-title">บริการเพิ่มเติมในแอปลุยเลเขา</p>
        <p class="alert-text">
          <strong>ห้องแชทของรอบเดินทาง</strong> ติดต่อผู้ร่วมทริปและทีมงานก่อนออกเดินทาง<br />
          <strong>QR เช็คอิน</strong> สำหรับให้ทีมงานสแกน ณ จุดขึ้นรถ<br />
          <strong>ติดตามตำแหน่งรถแบบเรียลไทม์</strong> ในวันเดินทาง<br />
          <span class="t-muted">
            ดาวน์โหลดได้ที่
            <a href="{{ $b['app']['ios'] }}" class="t-blue"><strong>App Store</strong></a>
            หรือ
            <a href="{{ $b['app']['android'] }}" class="t-blue"><strong>Google Play</strong></a>
            &middot; หากไม่ติดตั้งแอป ยังคงใช้ใบเดินทางนี้ได้ตามปกติ
          </span>
        </p>
      </div>
    @endif

    {{-- ── ลิงก์ใบเดินทางฉบับที่อัปเดตตัวเอง ─────────────────── --}}
    <div class="cta-wrap">
      <a href="{{ $b['links']['brief'] }}" class="cta-btn cta-slate">เปิดใบเดินทางฉบับล่าสุด</a>
    </div>
    <p class="body-text" style="text-align:center;">
      <span class="t-muted">
        ข้อมูลในลิงก์นี้อัปเดตอัตโนมัติ หากทีมงานเปลี่ยนรถหรือปรับกำหนดการ จะแสดงข้อมูลล่าสุดทันที<br />
        สามารถส่งต่อให้ครอบครัวเก็บไว้เพื่อติดตามการเดินทางได้
      </span>
    </p>

    <x-emails.partials.signoff />

  </div>

  <x-emails.partials.footer :value="$b['booking']['ref']" />

</x-emails.partials.base>
