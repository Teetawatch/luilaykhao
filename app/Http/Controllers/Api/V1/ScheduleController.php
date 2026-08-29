<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripScheduleResource;
use App\Models\ScheduleVehicleOption;
use App\Models\TripSchedule;
use App\Services\SeatLockService;
use App\Services\WeatherService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SeatLockService $seatLockService,
        private WeatherService $weatherService,
    ) {}

    public function show(int $id): JsonResponse
    {
        $schedule = TripSchedule::with(['trip.photos', 'vehicle', 'pickupPoints', 'vehicleOptions'])
            ->withHeldSeats()
            ->findOrFail($id);
        $schedule->syncBookedSeats();
        $this->weatherService->attach($schedule);

        return $this->success(new TripScheduleResource($schedule));
    }

    /**
     * ตัวเลือกยานพาหนะที่ผังนี้เป็นของมัน
     *
     * คืน null เมื่อรอบไม่มีตัวเลือก (รถคันเดียว), คืน false เมื่อส่ง id ที่ไม่ใช่
     * ของรอบนี้มา — ไม่ระบุมาแต่รอบมีตัวเลือก จะตกไปที่คันราคาปกติ กติกาเดียว
     * กับที่ BookingService ใช้ตอนสร้างการจอง
     */
    private function resolveVehicleOption(TripSchedule $schedule, mixed $requestedId): ScheduleVehicleOption|null|false
    {
        $options = $schedule->vehicleOptions->where('is_active', true);
        if ($options->isEmpty()) {
            return null;
        }

        if (blank($requestedId)) {
            return $options->firstWhere(
                fn (ScheduleVehicleOption $option) => (float) $option->price_adjustment === 0.0
            ) ?? $options->first();
        }

        return $options->firstWhere('id', (int) $requestedId) ?? false;
    }

    public function seats(Request $request, int $id): JsonResponse
    {
        $schedule = TripSchedule::with(['vehicle', 'vehicleOptions.vehicle'])->findOrFail($id);
        $schedule->syncBookedSeats(); // Sync real-time data to ensure accuracy

        // รอบที่วิ่งหลายคัน — ผังเป็นของคันที่ถามมา ไม่ใช่ของรอบ ไม่ระบุมา = คัน
        // ที่เป็นค่าตั้งต้น (ราคาปกติ) เพื่อให้ client รุ่นก่อนหน้ายังได้ผังที่ใช้ได้
        $option = $this->resolveVehicleOption($schedule, $request->input('vehicle_option_id'));
        if ($option === false) {
            return $this->error('ตัวเลือกยานพาหนะที่เลือกไม่อยู่ในรอบเดินทางนี้', 422);
        }
        $optionId = (int) ($option?->id ?? 0);

        // รอบที่บินไป ไม่มีผังให้เลือก — สายการบินเป็นคนจัดที่นั่ง ทีมงานกรอกเลข
        // ที่นั่งจริงกลับเข้าการจองทีหลัง คืนผังเปล่าไปเลยดีกว่าคืนผังรถตู้ปลอม ๆ
        // ที่ทุกหน้าจอจะเอาไปวาดเป็นตัวเลือก
        if (! $schedule->allowsSeatSelection() || ($option && ! $option->seat_selection)) {
            return $this->success([
                'vehicle_option_id' => $option?->id,
                'has_seat_map' => false,
                'seat_selection_disabled_reason' => $schedule->allowsSeatSelection()
                    ? $option->label.'รอบนี้ไม่ต้องเลือกที่นั่ง ทีมงานจัดที่นั่งให้หน้างาน'
                    : 'ที่นั่งบนเครื่องบินจัดโดยสายการบิน ทีมงานจะแจ้งเลขที่นั่งให้ก่อนวันเดินทาง',
                'rows' => 0,
                'columns' => [],
                'seats' => [],
                'total_seats' => $option?->seats ?? $schedule->total_seats,
                'available_seats' => $option?->available_seats ?? $schedule->available_seats,
                'booking_opens_at' => $schedule->bookingOpensAtFor($request->user()?->id)?->toISOString(),
                'is_bookable_now' => $schedule->isBookableBy($request->user()?->id),
            ]);
        }

        $layout = $schedule->resolveSeatLayout($option);

        $allSeatIds = collect($layout['seats'])->pluck('id')->toArray();
        $statuses = $this->seatLockService->getSeatStatus(
            $id,
            $allSeatIds,
            $request->user()?->id,
            $optionId,
        );

        $seats = collect($layout['seats'])->map(function ($seat) use ($statuses) {
            $seatStatus = $statuses[$seat['id']] ?? [
                'status' => 'available',
                'passenger_name' => null,
                'locked_ttl_seconds' => null,
                'locked_until' => null,
                'locked_by_current_user' => false,
                'booked_by_current_user' => false,
                'booking_ref' => null,
            ];

            return [
                ...$seat,
                ...$seatStatus,
            ];
        });

        return $this->success([
            'has_seat_map' => true, // Always true now since we have a fallback
            // คันที่ผังนี้เป็นของมัน — client ต้องส่งค่านี้กลับมาตอนล็อกที่นั่ง
            'vehicle_option_id' => $option?->id,
            'vehicle_option_label' => $option?->label,
            'rows' => $layout['rows'] ?? 0,
            'columns' => $layout['columns'] ?? [],
            'seats' => $seats,
            // รอบที่วิ่งหลายคัน นับที่นั่งของคันนี้ ไม่ใช่ของทั้งรอบ (โควตาไม่ได้
            // ตั้งไว้ = ใช้ที่ว่างรวมของรอบ)
            'total_seats' => $option?->seats ?? $schedule->total_seats,
            'available_seats' => $option?->available_seats ?? $schedule->available_seats,
            // บอกเวลาที่ผู้ใช้คนนี้จะได้จริง (รวมโบนัสระดับสมาชิก) ไม่งั้นหน้าจอ
            // จะนับถอยหลังคนละเลขกับที่ล็อกจริง
            'lock_ttl_seconds' => SeatLockService::lockTtlSeconds(1, $request->user()?->id),
            // เวลาเปิดจองสำหรับ "คนที่ถามมา" — สมาชิกระดับสูงได้เวลาที่เร็วกว่า
            'booking_opens_at' => $schedule->bookingOpensAtFor($request->user()?->id)?->toISOString(),
            'is_bookable_now' => $schedule->isBookableBy($request->user()?->id),
            'front_seat' => $layout['front_seat'] ?? null,
            'last_row_center' => $layout['last_row_center'] ?? [],
            'front_label' => $layout['front_label'] ?? 'หน้ารถ',
            'rear_label' => $layout['rear_label'] ?? 'ท้ายรถ (สำหรับเก็บสัมภาระ)',
            'driver_icon' => $layout['driver_icon'] ?? 'directions_car',
            'show_driver' => $layout['show_driver'] ?? true,
            'staff_icon' => $layout['staff_icon'] ?? 'support_agent',
            'show_staff' => $layout['show_staff'] ?? true,
        ]);
    }
}
