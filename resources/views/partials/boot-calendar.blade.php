{{--
    ปฏิทินทริปของเดือนนี้ ในตัว response เอง

    อยู่ข้างใน #app เหมือน boot-trip — Vue ล้างกล่องตอน mount เนื้อหานี้จึงหาย
    ไปเองทันทีที่บันเดิลมาถึง และไม่มีทางซ้อนกับของจริง ประกอบโดย
    App\Support\CalendarShell
--}}
<section class="llk-cal">
    <h1 class="llk-trip__title">{{ $shell['heading'] }}</h1>
    <p class="llk-trip__lede">{{ $shell['summary'] }}</p>

    @if ($shell['hot'])
        <h2 class="llk-trip__h2">ทริปไฟไหม้ ใกล้ออกเดินทาง</h2>
        <ul class="llk-boot__list">
            @foreach ($shell['hot'] as $round)
                <li><a href="{{ $round['url'] }}">{{ $round['label'] }}</a></li>
            @endforeach
        </ul>
    @endif

    @foreach ($shell['days'] as $day)
        <h2 class="llk-trip__h2">{{ $day['label'] }}</h2>
        <ul class="llk-boot__list">
            @foreach ($day['rounds'] as $round)
                <li><a href="{{ $round['url'] }}">{{ $round['label'] }}</a></li>
            @endforeach
        </ul>
    @endforeach

    @if ($shell['more'])
        <p class="llk-trip__lede">{{ $shell['more'] }}</p>
    @endif
</section>
