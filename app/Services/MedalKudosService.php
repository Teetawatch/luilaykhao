<?php

namespace App\Services;

use App\Models\MedalKudos;
use App\Models\SmartNotification;
use App\Models\TripMedal;
use App\Models\User;
use App\Support\MedalDesign;
use Illuminate\Support\Facades\Log;

/**
 * ปรบมือ (kudos) ให้เพื่อนร่วมรอบ — แบบ Strava แต่วงแคบกว่าโดยตั้งใจ
 *
 * เห็นกันและปรบมือให้กันได้เฉพาะคนที่ได้เหรียญ *รอบเดียวกัน* เท่านั้น คือคน
 * ที่เดินมาด้วยกันจริง ไม่เปิดเป็นฟีดสาธารณะ เพราะเหรียญมีชื่อคน การโพสต์ให้
 * คนแปลกหน้าเห็นโดยเจ้าตัวไม่ได้เลือกเองคือการเปิดเผยข้อมูลเกินจำเป็น
 * คนที่บล็อกกัน (ทางใดทางหนึ่ง) มองไม่เห็นกันบนกระดานนี้เช่นเดียวกับที่อื่น
 */
class MedalKudosService
{
    /** push "มีคนปรบมือให้" ไม่เกินหนึ่งครั้งต่อเหรียญในช่วงนี้ — รอบ 12 คนปรบมือ
     * ให้กันทั้งหมดคือ push หลายสิบครั้งภายในไม่กี่นาที */
    public const PUSH_COOLDOWN_MINUTES = 30;

    public function __construct(
        private MedalService $medals,
        private ModerationService $moderation,
    ) {}

    /**
     * คนที่พิชิตรอบเดียวกับเหรียญ [$mine] ของผู้ชม เรียงตามเลข Finisher
     *
     * @return array<string, mixed>
     */
    public function board(User $viewer, TripMedal $mine): array
    {
        $hidden = $this->moderation->hiddenAuthorIds($viewer);

        $medals = TripMedal::query()
            ->where('schedule_id', $mine->schedule_id)
            ->whereNotIn('user_id', $hidden)
            ->with(['user', 'trip'])
            ->withCount('kudos')
            ->orderBy('finisher_no')
            ->get()
            ->filter(fn (TripMedal $m) => $m->user !== null);

        $kudoed = MedalKudos::where('user_id', $viewer->id)
            ->whereIn('medal_id', $medals->pluck('id'))
            ->pluck('medal_id')
            ->flip();

        return [
            'schedule_id' => $mine->schedule_id,
            'trip_name' => $mine->trip ? MedalDesign::name($mine->trip) : null,
            'finishers' => $medals
                ->map(fn (TripMedal $m) => [
                    'medal_id' => $m->id,
                    'holder_name' => $this->medals->holderName($m->user),
                    'avatar_url' => $m->user->avatar_url,
                    'finisher_no' => $m->finisher_no,
                    'finisher_label' => 'Finisher #'.$m->finisher_no,
                    'is_me' => $m->user_id === $viewer->id,
                    'kudos_count' => (int) $m->kudos_count,
                    'kudoed_by_me' => $kudoed->has($m->id),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * ปรบมือ/เลิกปรบมือ — คืนสถานะใหม่ โยน \Exception (ข้อความไทย) เมื่อไม่มีสิทธิ์
     *
     * @return array{kudoed: bool, kudos_count: int}
     */
    public function toggle(User $viewer, TripMedal $target): array
    {
        if ($target->user_id === $viewer->id) {
            throw new \Exception('ปรบมือให้เหรียญของตัวเองไม่ได้');
        }

        $sameRound = TripMedal::where('user_id', $viewer->id)
            ->where('schedule_id', $target->schedule_id)
            ->exists();

        if (! $sameRound || $this->moderation->hasBlockBetween($viewer, $target->user_id)) {
            throw new \Exception('ปรบมือได้เฉพาะเพื่อนที่พิชิตรอบเดียวกับคุณ');
        }

        $existing = MedalKudos::where('medal_id', $target->id)->where('user_id', $viewer->id)->first();

        if ($existing) {
            $existing->delete();
        } else {
            $created = MedalKudos::firstOrCreate(['medal_id' => $target->id, 'user_id' => $viewer->id]);

            if ($created->wasRecentlyCreated) {
                $this->notify($viewer, $target);
            }
        }

        return [
            'kudoed' => $existing === null,
            'kudos_count' => MedalKudos::where('medal_id', $target->id)->count(),
        ];
    }

    private function notify(User $giver, TripMedal $target): void
    {
        $recent = SmartNotification::where('user_id', $target->user_id)
            ->where('type', 'medal_kudos')
            ->where('data->medal_id', $target->id)
            ->where('created_at', '>=', now()->subMinutes(self::PUSH_COOLDOWN_MINUTES))
            ->exists();

        if ($recent) {
            return;
        }

        $target->loadMissing('trip');
        $name = $target->trip ? MedalDesign::name($target->trip) : 'ทริปของคุณ';

        try {
            SmartNotification::send(
                $target->user_id,
                'medal_kudos',
                '👏 '.$this->medals->holderName($giver).' ปรบมือให้คุณ',
                "ยินดีกับเหรียญพิชิต {$name} ของคุณ — แตะเพื่อดูว่าใครปรบมือให้บ้าง",
                [
                    'medal_id' => $target->id,
                    'route' => 'medal',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('Kudos push failed', ['medal_id' => $target->id, 'message' => $e->getMessage()]);
        }
    }
}
