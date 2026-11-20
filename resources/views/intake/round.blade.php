{{-- รอบเดินทางที่ลิงก์นี้ผูกอยู่ — ลูกค้าเปิดลิงก์จากแชทโดยไม่เห็นหน้าทริป
     จึงต้องอ่านจากตรงนี้ได้เลยว่าเป็นรอบวันไหนถึงวันไหน ที่นั่งเหลือเท่าไหร่
     ก่อนจะลงมือกรอกอะไร --}}
@php
    $departure = $schedule->departure_date;
    $nights = $departure && $schedule->return_date
        ? (int) $departure->diffInDays($schedule->return_date)
        : 0;

    $roundClosed = ! $schedule->acceptsNewCustomers();
    // รอบเหมาคันไม่ได้ขายรายที่นั่ง บอกจำนวนที่ว่างไปก็ไม่มีความหมาย
    $seatsLeft = $schedule->is_charter ? null : $schedule->bookable_seats;

    $closedReason = match (true) {
        $schedule->status !== 'open' => __('รอบนี้ปิดรับแล้ว'),
        $departure && $departure->toDateString() < now('Asia/Bangkok')->toDateString() => __('รอบนี้ออกเดินทางไปแล้ว'),
        default => __('รอบนี้เต็มแล้ว'),
    };

    // departs_at เก็บเวลาไทยตรง ๆ ในคอลัมน์ชนิด UTC — อ่านค่าดิบจึงได้เวลาที่ตั้งไว้จริง
    $departsAt = $schedule->departs_at;
    // ทริปจำนวนไม่น้อยรถออกคืนก่อนวันทริป ("ทริป 5-7" แต่ขึ้นรถคืนวันที่ 4) คนที่อ่าน
    // แค่ช่วงวันเดินทางจะมาผิดวันเต็ม ๆ วันที่รถออกจริงจึงต้องเป็นบรรทัดของตัวเอง
    // ไม่ใช่เวลาเล็ก ๆ ต่อท้ายช่วงวันที่
    $departsEarly = $schedule->departsBeforeTripDay();
    $daysEarly = $schedule->daysDepartingEarly();

    $roundMeta = [];
    if (! $departsEarly && $departure) {
        $roundMeta[] = __('ออกวัน').$departure->locale(app()->getLocale())->isoFormat('dddd');
    }
    if (! $departsEarly && $departsAt) {
        $roundMeta[] = __('เวลา ').$departsAt->format('H:i').__(' น.');
    }
    if ($nights > 0) {
        $roundMeta[] = ($nights + 1).__(' วัน ').$nights.__(' คืน');
    }
    if (! $roundClosed && $seatsLeft !== null) {
        $roundMeta[] = $seatsLeft <= 5 ? __('เหลือ ').$seatsLeft.__(' ที่นั่ง') : __('ที่นั่งว่าง ').$seatsLeft.__(' ที่');
    }
@endphp

<div class="round @if ($roundClosed) round--closed @endif">
    <span class="round-ic">@include('intake.icon', ['name' => 'calendar'])</span>
    <div class="round-text">
        <span class="round-label">{{ $roundLabel ?? __('รอบเดินทาง') }}</span>
        <strong>{{ $schedule->dateRangeLabel() }}</strong>
        @if ($roundMeta)
            <span class="round-meta">{{ implode(' · ', $roundMeta) }}</span>
        @endif
        @if ($departsEarly)
            {{-- เขียนวันให้เต็มพร้อมชื่อวัน ไม่ใช่แค่เวลา — คนที่มาผิดวันคือคนที่อ่าน
                 เจอแค่ "20:00 น." แล้วเติมวันที่ของทริปให้เอง --}}
            <span class="round-early">
                @include('intake.icon', ['name' => 'clock'])
                <span>
                    {{ __('ขึ้นรถ') }} <strong>{{ __(':isoformatที่ :departsat เวลา :format น.', ['isoformat' => $departsAt->locale(app()->getLocale())->isoFormat('dddd'), 'departsat' => \App\Support\AppLocale::fullDate($departsAt), 'format' => $departsAt->format('H:i')]) }}</strong>
                    {{ __('— รถออกก่อนวันทริป :daysearly วัน', ['daysearly' => $daysEarly]) }}
                </span>
            </span>
        @endif
        @if ($roundClosed)
            <span class="round-flag">{{ $closedReason }}</span>
        @endif
    </div>
</div>
