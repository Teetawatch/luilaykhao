<?php

namespace App\Services;

use App\Models\User;
use App\Support\AppLocale;
use App\Support\ContentText;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * push / แจ้งเตือนในแอป / LINE ถึงลูกค้าที่ใช้ภาษาอังกฤษ (users.locale = en)
 *
 * ข้อความแจ้งเตือนถูกประกอบเป็นภาษาไทยในโค้ดกว่าร้อยจุด แทนที่จะไล่แก้ทุกจุด
 * จึงแปลตอนส่งแทน: ข้อความที่มีใน lang/en.json ใช้คำแปลนั้นเลย ที่เหลือ (มีชื่อทริป
 * เวลา ยอดเงินฝังอยู่) ให้ Haiku แปล แล้วจำคำแปลของข้อความเดียวกันไว้ 30 วัน
 * ลูกค้าไทยไม่ผ่านตัวนี้เลย
 *
 * ไม่แปล: SOS/เหตุฉุกเฉิน (ต้องถึงทันที ไม่รอคิว) และแชท (คำพูดของคนในห้อง —
 * มีปุ่มแปลในแอปอยู่แล้ว)
 */
class NotificationTranslator
{
    private const SKIP_PREFIXES = ['sos', 'incident', 'chat'];

    private const CACHE_DAYS = 30;

    public function __construct(private ContentTranslationService $translations) {}

    public function localeFor(int $userId): string
    {
        return AppLocale::normalise(User::whereKey($userId)->value('locale')) ?? AppLocale::THAI;
    }

    public function needs(string $type, string $title, string $body): bool
    {
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return false;
            }
        }

        return ContentText::isThai($title) || ContentText::isThai($body);
    }

    /**
     * @return array{title: string, body: string} ช่องที่แปลไม่ได้คงเป็นไทย
     */
    public function translate(string $title, string $body): array
    {
        $out = ['title' => $title, 'body' => $body];
        $need = [];

        foreach ($out as $field => $text) {
            if (! ContentText::isThai($text)) {
                continue;
            }
            if (trans()->hasForLocale($text, AppLocale::ENGLISH)) {
                $out[$field] = __($text, [], AppLocale::ENGLISH);

                continue;
            }
            if (is_string($cached = Cache::get($this->cacheKey($text)))) {
                $out[$field] = $cached;

                continue;
            }
            $need['notification.'.$field] = $text;
        }

        if ($need === []) {
            return $out;
        }

        try {
            $results = $this->translations->translateSegments($need, AppLocale::ENGLISH);
        } catch (PartialTranslation $e) {
            $results = $e->results;
            Log::warning('NotificationTranslator: translation stopped part-way', ['message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $results = [];
            Log::warning('NotificationTranslator: translation failed', ['message' => $e->getMessage()]);
        }

        foreach ($results as $path => $text) {
            $field = substr($path, strlen('notification.'));
            Cache::put($this->cacheKey($need[$path]), $text, now()->addDays(self::CACHE_DAYS));
            $out[$field] = $text;
        }

        return $out;
    }

    private function cacheKey(string $thai): string
    {
        return 'push-translation:en:'.sha1(trim($thai));
    }
}
