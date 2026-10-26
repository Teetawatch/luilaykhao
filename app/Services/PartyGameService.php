<?php

namespace App\Services;

use App\Models\PartyGameCard;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * เกมรอบวงตอนเย็น — คลังเกมที่แอดมินแก้ได้ กับคำตอบว่า "ใครเปิดเกมได้เมื่อไหร่"
 *
 * แอปเล่นแบบเครื่องเดียววนส่งกัน และที่ที่เล่นจริงคือลานกางเต็นท์ที่ไม่มีสัญญาณ
 * เซิร์ฟเวอร์จึงไม่ได้คุมการเล่นเลย หน้าที่มีแค่:
 *   - ส่งคลังเกม + ช่วงวันที่เปิดได้ของแต่ละรอบ ให้แอปเก็บไว้ในเครื่องล่วงหน้า
 *     [PREFETCH_DAYS] วัน แล้วแอปเทียบวันที่เองตอนไม่มีเน็ต
 *   - เปิดได้เฉพาะคนในรอบ (นิยามเดียวกับ SOS) และเฉพาะช่วงทริปจริง: ตั้งแต่วันที่
 *     รถออก (รอบที่ออกคืนก่อนนับจากคืนนั้น) ถึงวันกลับ — นอกช่วงนี้ไม่ส่งคลังให้เลย
 *
 * รูปร่าง data ต่อเกม (ตรวจที่ [normalize] ที่เดียว):
 *   spyfall    body = สถานที่, data.roles = บทบาทของคนที่ไม่ใช่สายลับ
 *   truth_dare kind = truth|dare, level
 *   never      level — body ขึ้นต้นด้วย "ฉันไม่เคย"
 *   heads_up   category, body = คำ, data.taboo = คำต้องห้าม (โหมดห้ามพูด)
 *   trivia     category, body = คำถาม, data.choices / data.answer (index) / data.note
 *   forbidden  level (ง่าย = ต้องหลอกถึงจะพูด, โหด = คำติดปาก), body = คำสั้น ๆ
 */
class PartyGameService
{
    /** ส่งคลังให้ล่วงหน้ากี่วันก่อนเปิดเล่น — ให้เครื่องมีของติดตัวก่อนขึ้นดอย */
    public const PREFETCH_DAYS = 2;

    public function __construct(
        private SosParticipantService $participants,
        private TripRosterService $roster,
    ) {}

    /**
     * ช่วงวันที่เปิดเกมได้ของรอบนี้ (วันที่ไทย รวมทั้งสองปลาย)
     *
     * @return array{opens_on: string, closes_on: string}|null
     */
    public function window(TripSchedule $schedule): ?array
    {
        $opens = $schedule->effectiveDepartureDate();
        if (! $opens) {
            return null;
        }

        $closes = ($schedule->return_date ?? $schedule->departure_date)?->toDateString()
            ?? $opens->toDateString();

        return [
            'opens_on' => $opens->toDateString(),
            // รอบที่ออกคืนก่อนแต่ไม่ได้กรอกวันกลับ — อย่าให้ปิดก่อนเปิด
            'closes_on' => max($closes, $opens->toDateString()),
        ];
    }

    public function isOpen(TripSchedule $schedule, ?string $today = null): bool
    {
        $window = $this->window($schedule);
        $today ??= now('Asia/Bangkok')->toDateString();

        return $window !== null && $today >= $window['opens_on'] && $today <= $window['closes_on'];
    }

    /**
     * รอบของผู้ใช้ที่เปิดเกมได้อยู่ หรือจะเปิดภายใน [PREFETCH_DAYS] วัน
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function roundsFor(User $user): Collection
    {
        $ids = $this->participants->scheduleIdsFor((int) $user->id);
        if ($ids->isEmpty()) {
            return collect();
        }

        $today = now('Asia/Bangkok')->toDateString();
        $prefetchFrom = now('Asia/Bangkok')->addDays(self::PREFETCH_DAYS)->toDateString();

        return TripSchedule::with('trip:id,title')
            ->whereIn('id', $ids)
            ->where('status', '!=', 'cancelled')
            ->get()
            ->map(function (TripSchedule $schedule) use ($today, $prefetchFrom) {
                $window = $this->window($schedule);
                if (! $window || $window['opens_on'] > $prefetchFrom || $window['closes_on'] < $today) {
                    return null;
                }

                return [
                    'schedule_id' => (int) $schedule->id,
                    'trip_id' => (int) $schedule->trip_id,
                    'trip_title' => $schedule->trip?->title ?? 'ทริป',
                    'opens_on' => $window['opens_on'],
                    'closes_on' => $window['closes_on'],
                    'players' => $this->players($schedule),
                ];
            })
            ->filter()
            ->sortBy('opens_on')
            ->values();
    }

    /**
     * ชื่อผู้เล่นตั้งต้น — คนที่ไปจริงของรอบ ตามด้วยทีมงาน (สตาฟเล่นด้วยได้)
     * ชื่อเดียวกันสองคนต่อท้ายเลขให้ ไม่งั้นวงล้อชี้ "บี" แล้วไม่รู้ว่าบีไหน
     *
     * @return array<int, string>
     */
    public function players(TripSchedule $schedule): array
    {
        $names = $this->roster->travelling($schedule)->pluck('name');

        $staff = $schedule->activeStaff()->get(['users.id', 'users.name', 'users.nickname']);
        foreach ($staff as $member) {
            $names->push($this->roster->shortName($member->nickname, $member->name));
        }

        $seen = [];
        $out = [];
        foreach ($names as $name) {
            $name = trim((string) $name) ?: 'ผู้เล่น';
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            $out[] = $seen[$name] > 1 ? "{$name} {$seen[$name]}" : $name;
        }

        return $out;
    }

    /**
     * คลังที่เปิดอยู่ — ใบกลางของทุกทริป บวกใบเฉพาะทริปของรอบที่ส่งให้
     *
     * @param  array<int, int>  $tripIds
     * @return Collection<int, array<string, mixed>>
     */
    public function catalog(array $tripIds): Collection
    {
        return PartyGameCard::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('trip_id')->orWhereIn('trip_id', $tripIds))
            ->orderBy('game')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (PartyGameCard $card) => $this->present($card))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(PartyGameCard $card): array
    {
        return [
            'id' => (int) $card->id,
            'game' => $card->game,
            'kind' => $card->kind,
            'level' => $card->level,
            'category' => $card->category,
            'body' => $card->body,
            'data' => $card->data ?: (object) [],
            'trip_id' => $card->trip_id,
        ];
    }

    /**
     * ตรวจและจัดรูปข้อมูลหนึ่งใบตามเกมของมัน — ทิ้งช่องที่เกมนั้นไม่ใช้ ไม่งั้นแอดมิน
     * เปลี่ยนเกมของใบแล้วค่าเก่าค้างอยู่ในฐานข้อมูลเงียบ ๆ
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $game = (string) ($input['game'] ?? '');
        $body = trim((string) ($input['body'] ?? ''));
        $data = is_array($input['data'] ?? null) ? $input['data'] : [];
        $errors = [];

        if (! in_array($game, PartyGameCard::GAMES, true)) {
            $errors['game'] = 'เลือกเกมให้ถูกต้อง';
        }
        if ($body === '') {
            $errors['body'] = 'กรอกข้อความก่อน';
        } elseif (mb_strlen($body) > 500) {
            $errors['body'] = 'ข้อความยาวเกิน 500 ตัวอักษร';
        }

        $out = [
            'game' => $game,
            'body' => $body,
            'kind' => null,
            'level' => null,
            'category' => null,
            'data' => null,
        ];

        $needsLevel = in_array($game, ['truth_dare', 'never', 'forbidden'], true);
        if ($needsLevel) {
            $level = $input['level'] ?? null;
            if (! in_array($level, PartyGameCard::LEVELS, true)) {
                $errors['level'] = 'เลือกระดับความแรง';
            }
            $out['level'] = $level;
        }

        // คำต้องห้ามต้องสั้นพอที่จะเผลอพูดในบทสนทนา ไม่ใช่ประโยค
        if ($game === 'forbidden' && mb_strlen($body) > 30) {
            $errors['body'] = 'คำต้องห้ามควรเป็นคำสั้น ๆ ไม่เกิน 30 ตัวอักษร';
        }

        if ($game === 'truth_dare') {
            $kind = $input['kind'] ?? null;
            if (! in_array($kind, PartyGameCard::KINDS, true)) {
                $errors['kind'] = 'เลือกว่าเป็นความจริงหรือคำท้า';
            }
            $out['kind'] = $kind;
        }

        if (in_array($game, ['heads_up', 'trivia'], true)) {
            $category = trim((string) ($input['category'] ?? ''));
            if ($category === '') {
                $errors['category'] = 'ใส่หมวดก่อน';
            } elseif (mb_strlen($category) > 60) {
                $errors['category'] = 'ชื่อหมวดยาวเกิน 60 ตัวอักษร';
            }
            $out['category'] = $category;
        }

        if ($game === 'spyfall') {
            $roles = $this->cleanList($data['roles'] ?? []);
            if (count($roles) < 3) {
                $errors['data.roles'] = 'ใส่บทบาทอย่างน้อย 3 บทบาท';
            } elseif (count($roles) > 12) {
                $errors['data.roles'] = 'บทบาทไม่เกิน 12 บทบาท';
            }
            $out['data'] = ['roles' => $roles];
        }

        if ($game === 'heads_up') {
            $taboo = $this->cleanList($data['taboo'] ?? []);
            if (count($taboo) > 5) {
                $errors['data.taboo'] = 'คำต้องห้ามไม่เกิน 5 คำ';
            }
            $out['data'] = $taboo ? ['taboo' => $taboo] : null;
        }

        if ($game === 'trivia') {
            $choices = $this->cleanList($data['choices'] ?? []);
            $answer = $data['answer'] ?? null;
            if (count($choices) < 2 || count($choices) > 4) {
                $errors['data.choices'] = 'ใส่ตัวเลือก 2–4 ข้อ';
            } elseif (! is_numeric($answer) || (int) $answer < 0 || (int) $answer >= count($choices)) {
                $errors['data.answer'] = 'เลือกข้อที่ถูก';
            }
            $note = trim((string) ($data['note'] ?? ''));
            $out['data'] = array_filter([
                'choices' => $choices,
                'answer' => is_numeric($answer) ? (int) $answer : null,
                'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
            ], fn ($v) => $v !== null);
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function cleanList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\n,]+/u', $value) ?: [];
        }

        return collect(Arr::wrap($value))
            ->map(fn ($v) => mb_substr(trim((string) $v), 0, 80))
            ->filter(fn ($v) => $v !== '')
            ->unique()
            ->values()
            ->all();
    }
}
