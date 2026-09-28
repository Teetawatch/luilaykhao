{{--
    ดวงเหรียญพิชิต — คู่แฝดฝั่งเว็บของ MedalArt ในแอป (lib/widgets/medal_art.dart)
    ใช้ทั้งหน้า /m/{token} และชั้นวางเหรียญในโปรไฟล์ /u/{handle}

    $card = MedalService::publicCard()  ·  $size = ความกว้างเป็น px
    ต้องโหลด Material Symbols Rounded + สไตล์ .medal-art จาก partials/medal-art-styles ไว้ในหน้าก่อน
--}}
@php
    $design = $card['design'];
    $size = $size ?? 240;
@endphp
<div class="medal-art" style="--medal: {{ $design['color'] }}; --size: {{ $size }}px;" role="img"
     aria-label="เหรียญพิชิต {{ $design['name'] }} · {{ $card['finisher_label'] }}">
    @if ($design['image_url'])
        <img class="medal-art__custom" src="{{ $design['image_url'] }}" alt="" loading="lazy">
    @else
        <div class="medal-art__ribbon"><span></span><span></span></div>
        <div class="medal-art__rim">
            <div class="medal-art__disc">
                <div class="medal-art__ring"></div>
                <span class="material-symbols-rounded medal-art__icon">{{ $design['icon'] }}</span>
                <span class="medal-art__name">{{ $design['name'] }}</span>
                <span class="medal-art__kicker">FINISHER</span>
            </div>
        </div>
    @endif
</div>
