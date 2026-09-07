<?php

namespace App\Services;

use App\Models\CustomerIntake;
use App\Models\CustomerIntakePerson;
use App\Models\ScheduleVehicleOption;
use App\Models\TripSchedule;
use Illuminate\Support\Collection;

/**
 * ที่นั่งบนหน้า "กรอกข้อมูลผู้เดินทาง" — เลือกได้จริง แต่ไม่ล็อก
 *
 * เจตนาของทั้งไฟล์นี้อยู่ที่คำว่า "ไม่ล็อก": ลูกค้าที่กรอกฟอร์มยังไม่ได้จ่ายเงิน
 * ส่วนคนที่กดจองเองในแอป/เว็บแล้วจ่ายเงินภายในหน้าต่างชำระเงินคือคนที่ได้ที่นั่ง
 * จริง ๆ ถ้าฟอร์มนี้ไปล็อกที่นั่งไว้ด้วย เท่ากับเอาที่นั่งของคนที่พร้อมจ่ายไปกันไว้
 * ให้คนที่ยังไม่ได้ตกลงอะไรเลย — จึงเก็บเป็นแค่ "ที่นั่งที่เลือกไว้" บนแถวของคนนั้น
 * แล้วให้แอดมินเป็นคนกันที่นั่งจริงตอนเปิดใบจอง
 *
 * สิ่งที่บริการนี้ทำจึงมีสองอย่าง: วาดผังที่นั่งพร้อมสถานะ ณ วินาทีนี้ ให้หน้าเว็บ
 * สาธารณะเอาไปแสดง และบอกว่า ณ วินาทีที่กดส่ง ที่นั่งไหนยังเลือกได้อยู่
 */
class IntakeSeatService
{
    public function __construct(private readonly SeatLockService $locks) {}

    /**
     * ผังที่นั่งของรอบนี้พร้อมสถานะ — คืน null เมื่อไม่มีอะไรให้เลือก
     *
     * (ยังไม่รู้รอบ / จอยทริปที่ไม่มีรถของเรา / รอบบินที่สายการบินจัดที่นั่งให้ /
     * คันที่ปิดการเลือกที่นั่งไว้)
     *
     * @param  CustomerIntake|null  $group  กลุ่มที่กำลังเปิดหน้าอยู่ — ที่นั่งของเพื่อน
     *                                      ในกลุ่มเดียวกันจะติดชื่อเล่นกำกับ เพื่อให้รู้ว่า
     *                                      ที่นั่งนั้นหายไปเพราะเพื่อนเลือก ไม่ใช่คนแปลกหน้า
     * @return array<string, mixed>|null
     */
    public function mapFor(?TripSchedule $schedule, bool $joinTrip = false, ?CustomerIntake $group = null): ?array
    {
        if (! $schedule || $joinTrip || ! $schedule->allowsSeatSelection()) {
            return null;
        }

        $option = $this->defaultOption($schedule);
        if ($option && ! $option->seat_selection) {
            return null;
        }

        $optionId = (int) ($option?->id ?? 0);
        $layout = $schedule->resolveSeatLayout($option);
        $seats = collect($layout['seats'] ?? []);

        if ($seats->isEmpty()) {
            return null;
        }

        $statuses = $this->locks->getSeatStatus($schedule->id, $seats->pluck('id')->all(), null, $optionId);
        $claims = $this->claims($schedule, $optionId);

        $available = 0;
        $drawn = $seats->map(function (array $seat) use ($statuses, $claims, $group, &$available) {
            $status = $statuses[$seat['id']]['status'] ?? 'available';
            $claim = $claims->get($seat['id']);

            // ลำดับความสำคัญคือความจริงก่อนความตั้งใจ — ที่นั่งที่ถูกจอง/ถูกล็อกอยู่
            // แล้ว ต่อให้มีใครเลือกไว้ในฟอร์มก็เลือกไม่ได้
            [$state, $note] = match (true) {
                $status === 'booked' => ['booked', 'จองแล้ว'],
                $status === 'locked' => ['held', 'มีคนกำลังจองอยู่'],
                $claim !== null => [
                    'claimed',
                    $group && (int) $claim->customer_intake_id === $group->id
                        ? $claim->publicLabel().' เลือกไว้'
                        : 'มีคนเลือกไว้',
                ],
                default => ['available', null],
            };

            if ($state === 'available') {
                $available++;
            }

            return [
                ...$seat,
                'state' => $state,
                'note' => $note,
            ];
        })->all();

        return [
            'vehicle_option_id' => $optionId,
            'vehicle_option_label' => $option?->label,
            'rows' => $layout['rows'] ?? 0,
            'columns' => $layout['columns'] ?? [],
            'seats' => $drawn,
            'available_count' => $available,
            'front_seat' => $layout['front_seat'] ?? null,
            'last_row_center' => $layout['last_row_center'] ?? [],
            'front_label' => $layout['front_label'] ?? 'หน้ารถ',
            'rear_label' => $layout['rear_label'] ?? 'ท้ายรถ',
        ];
    }

    /**
     * ที่นั่งที่ยัง "เลือกได้" ณ วินาทีที่กดส่ง
     *
     * เบอร์ของคนที่กำลังกรอกนับเป็นที่นั่งของตัวเอง — คนที่กลับมาแก้ข้อมูลของตัวเอง
     * ต้องส่งที่นั่งเดิมกลับมาได้ ไม่ใช่โดนบอกว่า "มีคนเลือกไว้แล้ว" ซึ่งก็คือตัวเขาเอง
     *
     * @return array<int, string>
     */
    public function selectableSeatIds(?TripSchedule $schedule, bool $joinTrip = false, ?string $phone = null): array
    {
        $map = $this->mapFor($schedule, $joinTrip);
        if (! $map) {
            return [];
        }

        $mine = $phone
            ? $this->claims($schedule, (int) $map['vehicle_option_id'])
                ->filter(fn (CustomerIntakePerson $person) => $person->phone === $this->digits($phone))
                ->keys()
                ->all()
            : [];

        return collect($map['seats'])
            ->filter(fn (array $seat) => $seat['state'] === 'available' || in_array($seat['id'], $mine, true))
            ->pluck('id')
            ->all();
    }

    /** คันที่ผังของหน้านี้เป็นของมัน — กติกาเดียวกับตอนจองจริง (คันราคาปกติ) */
    public function defaultOption(TripSchedule $schedule): ?ScheduleVehicleOption
    {
        $options = $schedule->vehicleOptions()->where('is_active', true)->get();

        if ($options->isEmpty()) {
            return null;
        }

        return $options->firstWhere(
            fn (ScheduleVehicleOption $option) => (float) $option->price_adjustment === 0.0
        ) ?? $options->first();
    }

    /**
     * ที่นั่งที่ลูกค้ากลุ่มอื่น (และกลุ่มเดียวกัน) เลือกไว้ในฟอร์ม — คีย์ด้วยรหัสที่นั่ง
     *
     * นับเฉพาะกลุ่มที่ยังไม่ถูกดึงไปจอง กลุ่มที่จองแล้วมีที่นั่งจริงใน booking_seats
     * ซึ่งอ่านมาจากอีกทางอยู่แล้ว ถ้านับซ้ำจะกลายเป็นกันที่นั่งไว้สองรอบ
     *
     * @return Collection<string, CustomerIntakePerson>
     */
    private function claims(TripSchedule $schedule, int $optionId): Collection
    {
        return CustomerIntakePerson::query()
            ->whereNotNull('seat_id')
            ->where('seat_vehicle_option_id', $optionId)
            ->whereHas('intake', fn ($query) => $query
                ->where('trip_schedule_id', $schedule->id)
                ->where('status', 'new'))
            ->get(['id', 'customer_intake_id', 'seat_id', 'name', 'nickname', 'phone'])
            ->keyBy('seat_id');
    }

    private function digits(?string $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }
}
