{{--
    What the page says before the JavaScript bundle arrives.

    This markup lives *inside* #app on purpose: Vue empties the mount container
    before it renders, so this is replaced the moment the app boots and can
    never end up duplicating the real navbar or footer. Until then it is what a
    visitor on a slow connection sees instead of a blank white page — and, more
    to the point, it is the only set of links a crawler that does not run
    JavaScript will ever find on this site. See App\Support\SiteNav.
--}}
<div class="llk-boot">
    <a class="llk-boot__brand" href="{{ url('/') }}">ลุยเลเขา</a>

    @isset($shellTrip)
        {{-- A trip page describes the trip. Every other page gets the tagline:
             the links below are the whole point of the shell there, while here
             they are the footer under something worth reading. --}}
        @include('partials.boot-trip', ['shell' => \App\Support\TripShell::for($shellTrip)])
    @elseif (isset($shellCalendar))
        {{-- หน้าปฏิทินพิมพ์รอบของเดือนนั้นออกมาเลย ด้วยเหตุผลเดียวกับหน้าทริป --}}
        @include('partials.boot-calendar', ['shell' => \App\Support\CalendarShell::present($shellCalendar)])
    @else
        <p class="llk-boot__tagline">แพลตฟอร์มจองและจัดทริปเที่ยวทั่วประเทศไทยและต่างประเทศ เดินป่า ดำน้ำตื้น เช่ารถตู้นำเที่ยว</p>
    @endisset

    <nav class="llk-boot__nav" aria-label="ลิงก์หลักของเว็บไซต์">
        @foreach (\App\Support\SiteNav::sections() as $section)
            <div class="llk-boot__group">
                <h2 class="llk-boot__heading">{{ $section['heading'] }}</h2>
                <ul class="llk-boot__list">
                    @foreach ($section['links'] as $link)
                        <li><a href="{{ $link['url'] }}">{{ $link['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach

        @php($bootTrips = \App\Support\SiteNav::trips())
        @if ($bootTrips)
            <div class="llk-boot__group">
                <h2 class="llk-boot__heading">ทริปที่เปิดจอง</h2>
                <ul class="llk-boot__list">
                    @foreach ($bootTrips as $trip)
                        <li><a href="{{ $trip['url'] }}">{{ $trip['label'] }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endif
    </nav>
</div>
