<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * push ของแชท (ห้องรวม / แชทส่วนตัว / ซัพพอร์ต) เป็นภาษาของคนรับ — แบบเดียวกับ
 * ที่เขาเห็นในห้อง: ข้อความของคนพิมพ์ใช้คำแปลอัตโนมัติ (ChatTranslationService),
 * คำประกอบของ push ("กล่าวถึงคุณ", "ส่งรูปภาพ", ชื่อทริป) ใช้ lang/en.json
 *
 * คนรับที่อ่านไทย หรือข้อความที่เป็นภาษาของเขาอยู่แล้ว ได้ push ทันทีโดยไม่รอ
 * อะไรเลย คนที่ต้องรอคำแปลจะได้ช้ากว่าราวหนึ่งถึงสองวินาที ถ้าแปลไม่ทัน หรือ
 * งบเดือนหมด ก็ส่งต้นฉบับไปแทน push ต้องไม่หายเพราะการแปล
 */
class ChatPushTranslator
{
    /** รอคำแปลที่งานอื่นกำลังทำอยู่ได้นานสุดกี่ครั้ง × ช่วงละ 400 ms */
    private const WAIT_ROUNDS = 15;

    public function __construct(private ChatTranslationService $translations) {}

    /**
     * แบ่งคนรับตามภาษา — คีย์ "{lang}|{1=ต้องแปลข้อความ}" ชุดที่ไม่ต้องแปลมาก่อน
     * (ส่งได้ทันที) lang = ภาษาของคำประกอบ push (th หรือ en/zh)
     *
     * @param  iterable<int|string>  $userIds
     * @return array<string, array{lang: string, translate: bool, ids: array<int, int>}>
     */
    public function group(Model $message, iterable $userIds, ?TripSchedule $schedule): array
    {
        $ids = collect($userIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        // ภาษาที่ข้อความนี้ถูกแปลในแอปอยู่แล้ว (ห้องคนอ่านไทยล้วน = ไม่มี) —
        // push แปลตามกติกาเดียวกับที่เห็นในห้อง ไม่แปลเพิ่มเอง
        $targets = $this->translations->autoTargets($message);
        $groups = [];

        User::whereIn('id', $ids)
            ->get(['id', 'locale', 'nationality', 'chat_translate_lang', 'chat_auto_translate'])
            ->each(function (User $user) use (&$groups, $schedule, $targets) {
                $lang = $this->translations->preferredLanguage($user, $schedule);
                $translate = $user->chat_auto_translate !== false
                    && in_array($lang, $targets, true);

                $key = $lang.'|'.($translate ? 1 : 0);
                $groups[$key] ??= ['lang' => $lang, 'translate' => $translate, 'ids' => []];
                $groups[$key]['ids'][] = (int) $user->id;
            });

        // ชุดที่ไม่ต้องรอคำแปลส่งก่อน
        uasort($groups, fn ($a, $b) => (int) $a['translate'] <=> (int) $b['translate']);

        return $groups;
    }

    /**
     * ข้อความของคนพิมพ์เป็นภาษา $lang — ถ้างานแปลอัตโนมัติกำลังแปลอยู่ รอผลแทน
     * การเรียกโมเดลซ้ำ null = ไม่ได้ (ส่งต้นฉบับ)
     */
    public function translatedBody(Model $message, string $lang): ?string
    {
        for ($round = 0; $round < self::WAIT_ROUNDS; $round++) {
            $state = $this->translations->sharedTranslation($message, $lang);

            if ($state['status'] === 'done') {
                return $state['text'];
            }
            if ($state['status'] === 'failed') {
                return null;
            }

            Sleep::for(400)->milliseconds();
        }

        return null;
    }

    /**
     * ทำงาน $callback ด้วยภาษาของคำประกอบ push — th = ไทย, ภาษาอื่นใช้อังกฤษ
     * (แอปยังไม่มีหน้าภาษาจีน คนอ่านจีนได้คำประกอบอังกฤษ + ข้อความแปลเป็นจีน)
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function inLanguage(string $lang, callable $callback): mixed
    {
        $previous = App::getLocale();
        App::setLocale($lang === 'th' ? 'th' : 'en');

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    /** ข้อความสำรองของข้อความเสียง ในภาษาปัจจุบัน (เรียกใน inLanguage) */
    public static function audioPreview(?int $durationMs): string
    {
        $seconds = (int) round(max(0, (int) $durationMs) / 1000);

        return $seconds > 0
            ? __('🎤 ข้อความเสียง :time', ['time' => sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60)])
            : __('🎤 ข้อความเสียง');
    }

    /** ตัวอย่างข้อความใน push: ตัวอักษร (แปลแล้วถ้ามี) / เสียง / รูป — เรียกใน inLanguage */
    public function preview(Model $message, ?string $translated, int $limit = 120): string
    {
        $body = (string) $message->getAttribute('body');

        return match (true) {
            $translated !== null => Str::limit($translated, $limit),
            filled($body) => Str::limit($body, $limit),
            (bool) $message->getAttribute('audio_path') => self::audioPreview($message->getAttribute('audio_duration_ms')),
            (bool) $message->getAttribute('image_path') => $message instanceof ChatMessage ? __('📷 ส่งรูปภาพ') : __('📷 รูปภาพ'),
            default => '',
        };
    }
}
