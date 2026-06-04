<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DriverController extends Controller
{
    use ApiResponse;

    public function pinLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ]);

        // จำกัดเฉพาะ role ที่มีอยู่จริง (กัน RoleDoesNotExist ถ้ายังไม่เคยสร้าง role 'driver')
        $roles = Role::whereIn('name', ['driver', 'staff', 'operator', 'admin'])->pluck('name')->all();

        $user = collect($roles)->isEmpty()
            ? null
            : User::role($roles)
                ->whereNotNull('driver_pin_hash')
                ->get()
                ->first(fn (User $candidate) => Hash::check($validated['driver_pin'], $candidate->driver_pin_hash));

        if (! $user) {
            return $this->error('ไม่พบรหัสคนขับนี้ กรุณาตรวจสอบอีกครั้ง', 401);
        }

        $user->load('roles');
        $token = $user->createToken('driver-app-token')->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $this->formatUser($user),
            'schedules' => $this->driverSchedulesQueryForUser($user)
                ->limit(10)
                ->get()
                ->map(fn (TripSchedule $schedule) => $this->formatSchedule($schedule))
                ->values(),
        ], 'เข้าสู่ระบบคนขับสำเร็จ');
    }

    public function me(Request $request): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $user = $request->user();
        $user->load('roles');

        return $this->success([
            'user' => $this->formatUser($user),
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

    public function lookupCheckIn(Request $request): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์ staff', 403);
        }

        $validated = $request->validate([
            'qr_code' => ['required', 'string'],
            'schedule_id' => ['nullable', 'integer', 'exists:trip_schedules,id'],
        ]);

        $booking = $this->resolveCheckInBooking(
            $request,
            $validated['qr_code'],
            $validated['schedule_id'] ?? null
        );

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        return $this->success(
            new BookingResource($booking),
            'พบข้อมูลการจอง',
            200,
            $this->checkInMeta($booking)
        );
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

        $booking = $this->resolveCheckInBooking(
            $request,
            $validated['qr_code'],
            $validated['schedule_id'] ?? null
        );

        if ($booking instanceof JsonResponse) {
            return $booking;
        }

        if ($booking->status !== 'confirmed') {
            return $this->error('การจองนี้ยังไม่ได้รับการยืนยัน (สถานะ: '.$booking->status.')', 422);
        }

        if ($booking->checked_in) {
            return $this->error('เช็คอินแล้วเมื่อ '.$booking->checked_in_at?->format('d/m/Y H:i'), 422);
        }

        $booking->update([
            'checked_in' => true,
            'checked_in_at' => now(),
        ]);

        return $this->success(
            new BookingResource($booking->fresh($this->checkInRelations())),
            'เช็คอินสำเร็จ'
        );
    }

    private function resolveCheckInBooking(Request $request, string $rawCode, ?int $scheduleId = null): Booking|JsonResponse
    {
        $code = $this->extractCode($rawCode);
        $booking = Booking::with($this->checkInRelations())
            ->where(function (Builder $query) use ($code) {
                $query->where('qr_code', $code)
                    ->orWhere('booking_ref', $code);
            })
            ->first();

        if (! $booking) {
            return $this->error('ไม่พบการจองสำหรับ QR Code นี้', 404);
        }

        if ($scheduleId && (int) $booking->schedule_id !== (int) $scheduleId) {
            return $this->error('QR Code นี้ไม่ใช่ผู้เดินทางของรอบที่เลือก', 422);
        }

        if (! $this->canAccessSchedule($request, $booking->schedule)) {
            return $this->error('คุณไม่มีสิทธิ์เช็คอินรายการนี้', 403);
        }

        return $booking;
    }

    private function checkInRelations(): array
    {
        return [
            'schedule.trip',
            'schedule.vehicle',
            'schedule.staff',
            'schedule.pickupPoints',
            'pickupPoint',
            'user',
            'passengers',
            'seats',
            'installmentPayments',
        ];
    }

    private function checkInMeta(Booking $booking): array
    {
        if ($booking->checked_in) {
            return [
                'can_check_in' => false,
                'block_reason' => 'เช็คอินแล้วเมื่อ '.$booking->checked_in_at?->format('d/m/Y H:i'),
            ];
        }

        if ($booking->status !== 'confirmed') {
            return [
                'can_check_in' => false,
                'block_reason' => 'การจองยังไม่ได้รับการยืนยัน',
            ];
        }

        return [
            'can_check_in' => true,
            'block_reason' => null,
        ];
    }

    private function driverSchedulesQuery(Request $request): Builder
    {
        return $this->driverSchedulesQueryForUser($request->user());
    }

    private function driverSchedulesQueryForUser(User $user): Builder
    {
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

                // รถที่ผูกบัญชีคนขับคนนี้ไว้โดยตรง (PIN ส่ง GPS)
                $query->orWhereHas('vehicle', fn (Builder $vehicle) => $vehicle->where('driver_user_id', $user->id));

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
        return $request->user()->hasAnyRole(['driver', 'staff', 'operator', 'admin']);
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

        if ($schedule->vehicle && (int) $schedule->vehicle->driver_user_id === (int) $user->id) {
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

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'roles' => $user->roles->pluck('name')->values(),
        ];
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
            'pickup_points' => $schedule->relationLoaded('pickupPoints')
                ? $schedule->pickupPoints
                    ->sortBy('sort_order')
                    ->map(fn ($point) => [
                        'id' => $point->id,
                        'location' => $point->pickup_location,
                        'region_label' => $point->region_label,
                        'latitude' => $point->latitude,
                        'longitude' => $point->longitude,
                        'notes' => $point->notes,
                    ])
                    ->values()
                : [],
        ];
    }

    public function scheduleManifest(Request $request, int $id): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $schedule = TripSchedule::with(['trip', 'vehicle', 'staff', 'pickupPoints'])->find($id);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        if (! $this->canAccessSchedule($request, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์ดูรอบเดินทางนี้', 403);
        }

        $bookings = Booking::with(['user', 'pickupPoint', 'passengers'])
            ->where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->orderBy('checked_in')
            ->orderBy('booking_ref')
            ->get();

        $manifest = $bookings->map(function (Booking $booking) {
            $passengers = $booking->passengers
                ->map(fn ($passenger) => [
                    'name' => trim(($passenger->title ? $passenger->title.' ' : '').$passenger->name),
                    'nickname' => $passenger->nickname,
                    'phone' => $passenger->phone,
                ])
                ->values();

            return [
                'booking_ref' => $booking->booking_ref,
                'status' => $booking->status,
                'checked_in' => (bool) $booking->checked_in,
                'checked_in_at' => $booking->checked_in_at?->toIso8601String(),
                'contact_name' => $booking->user?->name,
                'contact_phone' => $booking->user?->phone,
                'is_group' => (bool) $booking->is_group,
                'group_name' => $booking->group_name,
                'pickup_region' => $booking->pickup_region,
                'pickup_location' => $booking->pickupPoint?->pickup_location,
                'pickup_region_label' => $booking->pickupPoint?->region_label,
                'pickup_map_url' => $booking->pickupPoint?->map_url,
                'pickup_notes' => $booking->pickupPoint?->notes,
                'passenger_count' => $passengers->count(),
                'passengers' => $passengers,
            ];
        })->values();

        return $this->success([
            'schedule' => $this->formatSchedule($schedule),
            'summary' => [
                'bookings' => $bookings->count(),
                'checked_in' => $bookings->where('checked_in', true)->count(),
                'passengers' => $bookings->sum(fn (Booking $booking) => $booking->passengers->count()),
            ],
            'bookings' => $manifest,
        ], 'รายชื่อผู้โดยสาร');
    }

    /**
     * แจ้งเตือนผู้โดยสารว่าคนขับเริ่มออกเดินทางแล้ว เรียกตอนคนขับกด "เริ่มติดตาม".
     * ส่งครั้งเดียวต่อรอบเดินทางต่อวัน (idempotent ด้วย cache).
     */
    public function markDeparted(Request $request, int $id): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $schedule = TripSchedule::with(['trip', 'vehicle', 'staff'])->find($id);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        if (! $this->canAccessSchedule($request, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์อัปเดตรอบเดินทางนี้', 403);
        }

        $cacheKey = "trip_departed_notified:{$schedule->id}";
        if (Cache::has($cacheKey)) {
            return $this->success(
                ['notified' => 0, 'already_sent' => true],
                'แจ้งเตือนออกเดินทางถูกส่งไปแล้วก่อนหน้านี้'
            );
        }

        $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
        $bookings = Booking::where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->whereNotNull('user_id')
            ->get(['id', 'booking_ref', 'user_id']);

        foreach ($bookings as $booking) {
            SmartNotification::send(
                $booking->user_id,
                'vehicle_departed',
                'รถออกเดินทางแล้ว 🚐',
                "คนขับทริป \"{$tripTitle}\" เริ่มออกเดินทางแล้ว ติดตามตำแหน่งรถแบบเรียลไทม์ได้เลย",
                [
                    'booking_ref' => $booking->booking_ref,
                    'vehicle_id' => $schedule->vehicle_id,
                    'schedule_id' => $schedule->id,
                ],
            );
        }

        Cache::put($cacheKey, true, now()->endOfDay());

        return $this->success(
            ['notified' => $bookings->count(), 'already_sent' => false],
            'ส่งแจ้งเตือนออกเดินทางให้ผู้โดยสารแล้ว'
        );
    }
}
