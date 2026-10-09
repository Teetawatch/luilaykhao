<?php

namespace App\Services;

use App\Models\BookingSeat;
use App\Models\ScheduleVehicleOption;
use App\Models\TripSchedule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ที่นั่งในใบจองต้องเป็นที่นั่งที่มีอยู่จริงบนผังของรถคันนั้น
 *
 * ผังกับใบจองผูกกันด้วยรหัสที่นั่ง (A1, B2, ...) อย่างเดียว ถ้ารหัสในใบจองไม่มี
 * บนผัง ใบนั้นจะหายไปจากผังเงียบ ๆ — ตัวนับยังบอกว่าเต็ม แต่ผังโชว์ที่ว่าง
 * และรหัสที่ยังตรงกันก็ไปตกคนละตำแหน่งบนตัวรถ (เคยเกิดจริงบน production เมื่อ
 * ผังรถถูกแก้หลังลูกค้าจองไปแล้ว) จึงกันไว้สองทาง:
 *
 *  - ตอนเขียนที่นั่ง: รหัสที่ไม่มีบนผังห้ามเข้า (unknownSeatIds / assertSeatsInLayout)
 *  - ตอนแก้ผัง/เปลี่ยนรถ/เพิ่มคัน: ถ้าทำให้ที่นั่งที่ขายไปแล้วหลุดผัง ห้ามบันทึก (guard)
 *
 * รอบที่บินไป และคันที่ทีมงานจัดที่นั่งหน้างาน ไม่มีผังให้เลือก — เลขที่นั่ง
 * ที่ทีมงานกรอกเองเป็นข้อความอิสระ จึงไม่ตรวจ
 */
class SeatLayoutGuard
{
    /**
     * รหัสที่นั่งที่ไม่มีบนผังของรอบ/คันนี้ — [] เมื่อรอบ/คันนี้ไม่ได้ใช้ผัง
     *
     * @param  iterable<string|null>  $seatIds
     * @return array<int, string>
     */
    public function unknownSeatIds(TripSchedule $schedule, ?ScheduleVehicleOption $option, iterable $seatIds): array
    {
        $ids = collect($seatIds)->filter(fn ($id) => filled($id))->map(fn ($id) => (string) $id);

        if ($ids->isEmpty() || ! $this->usesSeatMap($schedule, $option)) {
            return [];
        }

        return $ids->diff($this->layoutIds($schedule, $option))->unique()->values()->all();
    }

    /**
     * โยนข้อผิดพลาดเมื่อมีรหัสที่นั่งที่ไม่อยู่บนผัง
     *
     * @param  iterable<string|null>  $seatIds
     */
    public function assertSeatsInLayout(TripSchedule $schedule, ?ScheduleVehicleOption $option, iterable $seatIds): void
    {
        $unknown = $this->unknownSeatIds($schedule, $option, $seatIds);

        if ($unknown !== []) {
            throw new \Exception(
                'ไม่มีที่นั่ง '.implode(', ', $unknown).' ในผังที่นั่งของรถคันนี้ กรุณาเลือกที่นั่งใหม่'
            );
        }
    }

    /**
     * แถวที่นั่งของใบจองที่ยังใช้งานอยู่ ซึ่งไม่มีผังไหนของรอบนี้แสดงได้
     *
     * นับสองแบบ: รหัสไม่อยู่บนผังของคันนั้น และที่นั่งที่จองไว้ตอนรอบยังมีรถ
     * คันเดียว (คัน 0) แต่ตอนนี้รอบมีตัวเลือกรถแล้ว — หน้าจองแสดงเฉพาะผังของ
     * ตัวเลือก ที่นั่งของคัน 0 จึงไม่มีผังไหนวาดให้
     *
     * @return Collection<int, BookingSeat>
     */
    public function orphans(TripSchedule $schedule): Collection
    {
        if (! $schedule->allowsSeatSelection()) {
            return collect();
        }

        $schedule->loadMissing(['vehicle', 'vehicleOptions.vehicle']);
        $options = $schedule->vehicleOptions->keyBy('id');
        $hasActiveOptions = $schedule->vehicleOptions->contains('is_active', true);

        $seats = BookingSeat::where('schedule_id', $schedule->id)
            ->whereHas('booking', fn ($q) => $q
                ->whereIn('status', TripSchedule::ACTIVE_BOOKING_STATUSES)
                ->where('is_join_trip', false))
            ->with('booking:id,booking_ref')
            ->orderBy('id')
            ->get();

        $layoutIds = [];

        return $seats->filter(function (BookingSeat $seat) use ($schedule, $options, $hasActiveOptions, &$layoutIds) {
            $optionId = (int) $seat->vehicle_option_id;

            if ($optionId === 0) {
                if ($hasActiveOptions) {
                    return true;
                }
                $option = null;
            } else {
                $option = $options->get($optionId);
                // ตัวเลือกที่ถูกลบไปแล้ว — ลบไม่ได้ถ้ายังมีใบจองอยู่ (deleteVehicleOption)
                // จึงไม่ควรเจอ ถ้าเจอก็ไม่มีผังให้เทียบ
                if (! $option) {
                    return true;
                }
            }

            if (! $this->usesSeatMap($schedule, $option)) {
                return false;
            }

            $layoutIds[$optionId] ??= $this->layoutIds($schedule, $option);

            return ! $layoutIds[$optionId]->contains((string) $seat->seat_id);
        })->values();
    }

    /**
     * ทำการแก้ไขที่อาจเปลี่ยนผัง แล้วตรวจว่าไม่มีที่นั่งหลุดผังเพิ่มขึ้น
     * ถ้ามี — ย้อนการแก้ทั้งหมดแล้วโยนข้อผิดพลาดที่บอกว่าติดใบจองไหน
     *
     * นับเฉพาะที่ "เพิ่มขึ้น" เพราะรอบที่หลุดผังอยู่ก่อนแล้วต้องยังแก้ผังให้กลับมา
     * ตรงได้ (นั่นคือวิธีซ่อม) — ไม่ใช่ถูกล็อกค้างไว้
     *
     * @template T
     *
     * @param  callable(): iterable<TripSchedule>  $schedules  รอบที่การแก้นี้กระทบ
     * @param  callable(): T  $change
     * @return T
     */
    public function guard(callable $schedules, callable $change): mixed
    {
        return DB::transaction(function () use ($schedules, $change) {
            $before = $this->orphanKeys($schedules());

            $result = $change();

            // โหลดรอบใหม่ทั้งหมด — รถ/ตัวเลือกที่แก้ต้องถูกอ่านจากฐานข้อมูลอีกครั้ง
            $after = collect($schedules())
                ->map(fn (TripSchedule $s) => $s->fresh())
                ->filter()
                ->flatMap(fn (TripSchedule $s) => $this->orphans($s));

            $new = $after->reject(fn (BookingSeat $seat) => isset($before[$seat->id]));

            if ($new->isNotEmpty()) {
                throw new SeatLayoutConflict($new);
            }

            return $result;
        });
    }

    /**
     * รอบที่ยังไม่จบซึ่งวาดผังจากรถคันนี้ (เป็นรถของรอบ หรือเป็นรถของตัวเลือก)
     *
     * @return Collection<int, TripSchedule>
     */
    public function upcomingSchedulesUsingVehicle(int $vehicleId): Collection
    {
        return $this->upcoming()
            ->where(fn ($q) => $q
                ->where('vehicle_id', $vehicleId)
                ->orWhereHas('vehicleOptions', fn ($o) => $o->where('vehicle_id', $vehicleId)))
            ->get();
    }

    /**
     * รอบที่ยังไม่จบและมีที่นั่งของใบจองที่ยังใช้งาน — ตัวตั้งของการตรวจรายวัน
     *
     * @return Collection<int, TripSchedule>
     */
    public function upcomingSchedulesWithSeats(): Collection
    {
        return $this->upcoming()
            ->whereHas('bookingSeats')
            ->with(['trip:id,title', 'vehicle', 'vehicleOptions.vehicle'])
            ->orderBy('departure_date')
            ->get();
    }

    /**
     * รอบที่ยังไม่จบซึ่งมีที่นั่งหลุดผังอยู่ — หน้า "สิ่งที่รอคุณ" กับ seats:audit
     *
     * @return Collection<int, array{schedule: TripSchedule, orphans: Collection<int, BookingSeat>}>
     */
    public function upcomingConflicts(): Collection
    {
        return $this->upcomingSchedulesWithSeats()
            ->map(fn (TripSchedule $s) => ['schedule' => $s, 'orphans' => $this->orphans($s)])
            ->filter(fn (array $row) => $row['orphans']->isNotEmpty())
            ->values();
    }

    private function upcoming()
    {
        $today = now('Asia/Bangkok')->toDateString();

        return TripSchedule::query()
            ->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q
                ->whereDate('return_date', '>=', $today)
                ->orWhere(fn ($q) => $q->whereNull('return_date')->whereDate('departure_date', '>=', $today)));
    }

    /** @param iterable<TripSchedule> $schedules */
    private function orphanKeys(iterable $schedules): array
    {
        return collect($schedules)
            ->flatMap(fn (TripSchedule $s) => $this->orphans($s))
            ->mapWithKeys(fn (BookingSeat $seat) => [$seat->id => true])
            ->all();
    }

    private function usesSeatMap(TripSchedule $schedule, ?ScheduleVehicleOption $option): bool
    {
        return $schedule->allowsSeatSelection() && (! $option || $option->seat_selection);
    }

    /** @return Collection<int, string> */
    private function layoutIds(TripSchedule $schedule, ?ScheduleVehicleOption $option): Collection
    {
        return collect($schedule->resolveSeatLayout($option)['seats'] ?? [])
            ->pluck('id')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (string) $id)
            ->values();
    }
}
