{{-- รอบเดินทางที่ลิงก์นี้ผูกอยู่ — ลูกค้าเปิดลิงก์จากแชทโดยไม่เห็นหน้าทริป
     จึงต้องอ่านจากตรงนี้ได้เลยว่าเป็นรอบวันไหนถึงวันไหน ก่อนจะกรอกอะไร --}}
@php
    $departure = $schedule->departure_date;
    $nights = $departure && $schedule->return_date
        ? (int) $departure->diffInDays($schedule->return_date)
        : 0;

    $roundMeta = [];
    if ($departure) {
        $roundMeta[] = 'ออกวัน'.$departure->locale('th')->isoFormat('dddd');
    }
    // departs_at เก็บเวลาไทยตรง ๆ ในคอลัมน์ชนิด UTC — อ่านค่าดิบจึงได้เวลาที่ตั้งไว้จริง
    if ($schedule->departs_at) {
        $roundMeta[] = 'เวลา '.$schedule->departs_at->format('H:i').' น.';
    }
    if ($nights > 0) {
        $roundMeta[] = ($nights + 1).' วัน '.$nights.' คืน';
    }
@endphp

<div class="round">
    <span class="round-ic">@include('intake.icon', ['name' => 'calendar'])</span>
    <div class="round-text">
        <span class="round-label">{{ $roundLabel ?? 'รอบเดินทาง' }}</span>
        <strong>{{ $schedule->dateRangeLabelThai() }}</strong>
        @if ($roundMeta)
            <span class="round-meta">{{ implode(' · ', $roundMeta) }}</span>
        @endif
    </div>
</div>
