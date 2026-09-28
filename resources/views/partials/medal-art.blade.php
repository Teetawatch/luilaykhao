{{--
    ดวงเหรียญพิชิต — คู่แฝดฝั่งเว็บของ MedalArt ในแอป (lib/widgets/medal_art.dart)
    ใช้ทั้งหน้า /m/{token} และชั้นวางเหรียญในโปรไฟล์ /u/{handle}
    รูปทรงทั้งหมดมาจาก App\Support\MedalGeometry (SVG viewBox 0 0 100 118)

    $card = MedalService::publicCard()  ·  $size = ความกว้างเป็น px
    ต้อง @include('partials.medal-art-styles') ไว้ใน <head> ของหน้าก่อน
--}}
@php
    use App\Support\MedalGeometry as G;

    $design = $card['design'];
    $size = $size ?? 240;
    $color = $design['color'];
    $uid = 'm'.substr(md5(uniqid('', true)), 0, 8);
    $year = ! empty($card['earned_on']) ? ((int) substr($card['earned_on'], 0, 4)) + 543 : null;
    $ribbon = G::ribbon();
    $points = fn (array $xy) => implode(' ', array_map(
        fn ($pair) => $pair[0].','.$pair[1],
        array_chunk($xy, 2),
    ));
@endphp
<div class="medal-art" style="--size: {{ $size }}px;" role="img"
     aria-label="เหรียญพิชิต {{ $design['name'] }} · {{ $card['finisher_label'] }}">
    @if ($design['image_url'])
        <img class="medal-art__custom" src="{{ $design['image_url'] }}" alt="" loading="lazy">
    @else
        <svg viewBox="0 0 {{ G::WIDTH }} {{ G::HEIGHT }}" width="{{ $size }}" height="{{ round($size * G::HEIGHT / G::WIDTH, 1) }}" aria-hidden="true">
            <defs>
                <path id="{{ $uid }}-arc" d="M {{ G::CX - G::RING_TEXT_R }},{{ G::CY }} A {{ G::RING_TEXT_R }},{{ G::RING_TEXT_R }} 0 0,1 {{ G::CX + G::RING_TEXT_R }},{{ G::CY }}"/>
            </defs>
            @foreach ($ribbon['bands'] as $band)
                <polygon points="{{ $points($band) }}" fill="{{ $color }}" style="fill: color-mix(in srgb, {{ $color }} 75%, #000)"/>
            @endforeach
            @foreach ($ribbon['stripes'] as $stripe)
                <polygon points="{{ $points($stripe) }}" fill="#fff"/>
            @endforeach

            <circle cx="{{ G::CX }}" cy="{{ G::CY }}" r="{{ G::ROSETTE_R }}" fill="{{ G::GOLD }}"/>
            @foreach (G::scallops() as $p)
                <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ G::SCALLOP_R }}" fill="{{ G::GOLD }}"/>
            @endforeach
            <circle cx="{{ G::CX }}" cy="{{ G::CY }}" r="{{ G::BAND_R }}" fill="{{ G::GOLD_DEEP }}"/>
            <circle cx="{{ G::CX }}" cy="{{ G::CY }}" r="{{ G::RIM_R }}" fill="{{ G::GOLD }}"/>
            <circle cx="{{ G::CX }}" cy="{{ G::CY }}" r="{{ G::DISC_R }}" fill="{{ $color }}"/>

            <text class="medal-art__ring" fill="{{ G::RING_TEXT }}" font-size="{{ G::RING_TEXT_SIZE }}" dominant-baseline="central">
                <textPath href="#{{ $uid }}-arc" startOffset="50%" text-anchor="middle">{{ G::ringText($year) }}</textPath>
            </text>

            @foreach (G::laurel() as $leaf)
                <ellipse cx="{{ $leaf['cx'] }}" cy="{{ $leaf['cy'] }}" rx="{{ $leaf['rx'] }}" ry="{{ $leaf['ry'] }}"
                         transform="rotate({{ $leaf['deg'] }} {{ $leaf['cx'] }} {{ $leaf['cy'] }})" fill="{{ G::LAUREL }}"/>
            @endforeach

            <text class="medal-art__icon material-symbols-rounded" x="{{ G::CX }}" y="{{ G::ICON_Y }}" font-size="{{ G::ICON_SIZE }}"
                  text-anchor="middle" dominant-baseline="central" fill="#fff">{{ $design['icon'] }}</text>

            <foreignObject x="{{ G::NAME_BOX['x'] }}" y="{{ G::NAME_BOX['y'] }}" width="{{ G::NAME_BOX['w'] }}" height="{{ G::NAME_BOX['h'] }}">
                <div xmlns="http://www.w3.org/1999/xhtml" class="medal-art__name" style="font-size: {{ G::NAME_SIZE }}px;">{{ $design['name'] }}</div>
            </foreignObject>

            <polygon points="{{ $points(G::banner()) }}" fill="{{ G::BANNER }}"/>
            <text class="medal-art__banner" x="{{ G::CX }}" y="{{ G::BANNER_TEXT_Y }}" font-size="{{ G::BANNER_TEXT_SIZE }}"
                  text-anchor="middle" dominant-baseline="central" style="fill: color-mix(in srgb, {{ $color }} 65%, #000)">FINISHER</text>
        </svg>
    @endif
</div>
