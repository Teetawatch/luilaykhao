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
        $schedule->status !== 'open' => 'รอบนี้ปิดรับแล้ว',
        $departure && $departure->toDateString() < now('Asia/Bangkok')->toDateString() => 'รอบนี้ออกเดินทางไปแล้ว',
        default => 'รอบนี้เต็มแล้ว',
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
        $roundMeta[] = 'ออกวัน'.$departure->locale('th')->isoFormat('dddd');
    }
    if (! $departsEarly && $departsAt) {
        $roundMeta[] = 'เวลา '.$departsAt->format('H:i').' น.';
    }
    if ($nights > 0) {
        $roundMeta[] = ($nights + 1).' วัน '.$nights.' คืน';
    }
    if (! $roundClosed && $seatsLeft !== null) {
        $roundMeta[] = $seatsLeft <= 5 ? 'เหลือ '.$seatsLeft.' ที่นั่ง' : 'ที่นั่งว่าง '.$seatsLeft.' ที่';
    }
@endphp

<div class="round @if ($roundClosed) round--closed @endif">
    <span class="round-ic">@include('intake.icon', ['name' => 'calendar'])</span>
    <div class="round-text">
        <span class="round-label">{{ $roundLabel ?? 'รอบเดินทาง' }}</span>
        <strong>{{ $schedule->dateRangeLabelThai() }}</strong>
        @if ($roundMeta)
            <span class="round-meta">{{ implode(' · ', $roundMeta) }}</span>
        @endif
        @if ($departsEarly)
            {{-- เขียนวันให้เต็มพร้อมชื่อวัน ไม่ใช่แค่เวลา — คนที่มาผิดวันคือคนที่อ่าน
                 เจอแค่ "20:00 น." แล้วเติมวันที่ของทริปให้เอง --}}
            <span class="round-early">
                @include('intake.icon', ['name' => 'clock'])
                <span>
                    ขึ้นรถ <strong>{{ $departsAt->locale('th')->isoFormat('dddd') }}ที่ {{ \App\Support\ThaiDate::full($departsAt) }}
                    เวลา {{ $departsAt->format('H:i') }} น.</strong>
                    — รถออกก่อนวันทริป {{ $daysEarly }} วัน
                </span>
            </span>
        @endif
        @if ($roundClosed)
            <span class="round-flag">{{ $closedReason }}</span>
        @endif
    </div>
</div>
