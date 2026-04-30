<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverController extends Controller
{
    use ApiResponse;

    public function me(Request $request): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $user = $request->user();
        $user->load('roles');

        return $this->success([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->pluck('name')->values(),
            ],
            'schedules' => $this->driverSchedulesQuery($request)
                ->limit(10)
                ->get()
                ->map(fn (TripSchedule $schedule) => $this->formatSchedule($schedule))
                ->values(),
        ]);
    }

    public function schedules(Request $request): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $schedules = $this->driverSchedulesQuery($request)
            ->limit(30)
            ->get()
            ->map(fn (TripSchedule $schedule) => $this->formatSchedule($schedule))
            ->values();

        return $this->success($schedules, 'รอบเดินทางของคนขับ');
    }

    public function checkIn(Request $request): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $validated = $request->validate([
            'qr_code' => ['required', 'string'],
            'schedule_id' => ['nullable', 'integer', 'exists:trip_schedules,id'],
        ]);

        $code = $this->extractCode($validated['qr_code']);
        $booking = Booking::with([
            'schedule.trip',
            'schedule.vehicle',
            'schedule.staff',
            'schedule.pickupPoints',
            'pickupPoint',
            'user',
            'passengers',
            'seats',
        ])
            ->where(function (Builder $query) use ($code) {
                $query->where('qr_code', $code)
                    ->orWhere('booking_ref', $code);
            })
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบการจองสำหรับ QR Code นี้', 404);
        }

        if (! empty($validated['schedule_id']) && (int) $booking->schedule_id !== (int) $validated['schedule_id']) {
            return $this->error('QR Code นี้ไม่ใช่ผู้เดินทางของรอบที่เลือก', 422);
        }

        if (! $this->canAccessSchedule($request, $booking->schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เช็กอินรายการนี้', 403);
        }

        if ($booking->status !== 'confirmed') {
            return $this->error('การจองนี้ยังไม่ได้รับการยืนยัน (สถานะ: '.$booking->status.')', 422);
        }

        if ($booking->checked_in) {
            return $this->error('เช็กอินแล้วเมื่อ '.$booking->checked_in_at?->format('d/m/Y H:i'), 422);
        }

        $booking->update([
            'checked_in' => true,
            'checked_in_at' => now(),
        ]);

        return $this->success(
            new BookingResource($booking->fresh([
                'schedule.trip',
                'schedule.vehicle',
                'schedule.staff',
                'schedule.pickupPoints',
                'pickupPoint',
                'user',
                'passengers',
                'seats',
            ])),
            'เช็กอินสำเร็จ'
        );
    }

    private function driverSchedulesQuery(Request $request): Builder
    {
        $user = $request->user();

        $query = TripSchedule::with([
            'trip',
            'vehicle',
            'staff',
            'pickupPoints',
        ])
            ->whereNotIn('status', ['cancelled'])
            ->whereDate('departure_date', '>=', today()->subDay())
            ->whereDate('departure_date', '<=', today()->addDays(7));

        if (! $user->hasAnyRole(['admin', 'operator'])) {
            $query->where(function (Builder $query) use ($user) {
                $query->whereHas('staff', fn (Builder $staff) => $staff->where('users.id', $user->id));

                $phone = $this->normalizePhone($user->phone);
                if ($phone !== '') {
                    $query->orWhereHas('vehicle', function (Builder $vehicle) use ($phone, $user) {
                        $vehicle
                            ->where('driver_phone', $user->phone)
                            ->orWhereRaw("REPLACE(REPLACE(REPLACE(driver_phone, '-', ''), ' ', ''), '.', '') = ?", [$phone]);
                    });
                }
            });
        }

        return $query
            ->withCount([
                'bookings as confirmed_bookings_count' => fn (Builder $query) => $query->where('status', 'confirmed'),
                'bookings as checked_in_bookings_count' => fn (Builder $query) => $query->where('checked_in', true),
            ])
            ->orderBy('departure_date')
            ->orderBy('id');
    }

    private function hasDriverAccess(Request $request): bool
    {
        return $request->user()->hasAnyRole(['staff', 'operator', 'admin']);
    }

    private function canAccessSchedule(Request $request, ?TripSchedule $schedule): bool
    {
        if (! $schedule) {
            return false;
        }

        $user = $request->user();
        if ($user->hasAnyRole(['admin', 'operator'])) {
            return true;
        }

        if ($schedule->staff?->contains(fn ($staff) => (int) $staff->id === (int) $user->id)) {
            return true;
        }

        return $this->normalizePhone($user->phone) !== ''
            && $schedule->vehicle
            && $this->normalizePhone($schedule->vehicle->driver_phone) === $this->normalizePhone($user->phone);
    }

    private function extractCode(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/QR-[A-Z0-9]+/i', $raw, $matches)) {
            return strtoupper($matches[0]);
        }

        if (preg_match('/LLK-\d{8}-\d{4}/i', $raw, $matches)) {
            return strtoupper($matches[0]);
        }

        return $raw;
    }

    private function normalizePhone(?string $phone): string
    {
        return preg_replace('/\D+/', '', $phone ?? '') ?? '';
    }

    private function formatSchedule(TripSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'trip_title' => $schedule->trip?->title ?? '',
            'trip_location' => $schedule->trip?->location ?? '',
            'departure_point' => $schedule->trip?->departure_point ?? '',
            'destination_lat' => $schedule->trip?->latitude,
            'destination_lng' => $schedule->trip?->longitude,
            'departure_date' => $schedule->departure_date?->toDateString(),
            'return_date' => $schedule->return_date?->toDateString(),
            'total_seats' => $schedule->total_seats,
            'booked_seats' => $schedule->booked_seats,
            'available_seats' => $schedule->available_seats,
            'confirmed_bookings_count' => (int) ($schedule->confirmed_bookings_count ?? 0),
            'checked_in_bookings_count' => (int) ($schedule->checked_in_bookings_count ?? 0),
            'status' => $schedule->status,
            'vehicle' => $schedule->vehicle ? [
                'id' => $schedule->vehicle->id,
                'name' => $schedule->vehicle->name,
                'license_plate' => $schedule->vehicle->license_plate,
                'driver_name' => $schedule->vehicle->driver_name,
                'driver_phone' => $schedule->vehicle->driver_phone,
            ] : null,
        ];
    }
}
