<?php

namespace App\Http\Controllers;

use App\Services\MedalImageService;
use App\Services\MedalService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * หน้าเหรียญพิชิตสาธารณะ /m/{token} — server-rendered เพราะบ็อตของ LINE/
 * Facebook อ่าน meta tag จาก HTML ชุดแรกเท่านั้น (แพตเทิร์นเดียวกับ /s/{token})
 */
class MedalPageController extends Controller
{
    /**
     * เหรียญไม่เปลี่ยนตามวันเหมือนการ์ดนับถอยหลัง แต่แอดมินอาจเปลี่ยนภาพเหรียญ
     * หรือเจ้าของเปลี่ยนชื่อเล่นได้ — หกชั่วโมงเป็นจุดกึ่งกลางที่พอดี
     */
    private const OG_CACHE_MINUTES = 360;

    public function __construct(
        private MedalService $medals,
        private MedalImageService $images,
    ) {}

    public function show(string $token): View|Response
    {
        $card = $this->medals->forShareToken($token);

        if ($card === null) {
            return response()->view('medal', ['card' => null, 'token' => $token], 404);
        }

        return response()->view('medal', [
            'card' => $card,
            'token' => $token,
            'ogVersion' => self::ogVersion($card),
        ]);
    }

    /**
     * ลายนิ้วมือของทุกอย่างที่วาดลงภาพ OG — ติดท้าย URL ของภาพ (?v=) เพราะ
     * LINE/Facebook จำภาพตาม URL ไว้นานมาก เจ้าของเปลี่ยนทรงเหรียญแล้วแชร์ใหม่
     * ต้องได้ภาพใหม่ ไม่ใช่ภาพทรงเก่าที่บ็อตเคยเก็บไว้
     *
     * @param  array<string, mixed>  $card
     */
    public static function ogVersion(array $card): string
    {
        return substr(md5(json_encode([
            $card['design'],
            $card['holder_name'],
            $card['shape'] ?? null,
            $card['finish'] ?? null,
        ])), 0, 10);
    }

    public function ogImage(string $token): Response
    {
        $card = $this->medals->forShareToken($token);

        abort_if($card === null, 404);

        // หน้าตาเหรียญอยู่ในคีย์ด้วย — แอดมินเปลี่ยนภาพ/สี หรือเจ้าของเปลี่ยนทรง
        // แล้วภาพใหม่ต้องออกทันที
        $key = 'medal-og:'.$token.':'.self::ogVersion($card);

        $png = Cache::remember(
            $key,
            now()->addMinutes(self::OG_CACHE_MINUTES),
            fn () => $this->images->render($card),
        );

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age='.(self::OG_CACHE_MINUTES * 60),
        ]);
    }
}
