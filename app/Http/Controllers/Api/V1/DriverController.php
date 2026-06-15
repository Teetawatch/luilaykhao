<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\SchedulePickupPoint;
use App\Models\SmartNotification;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\ChatService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        // When this check-in completes everyone at the booking's pickup point,
        // close the point and immediately notify the next stop's passengers.
        $auto = $this->maybeAutoCompletePickup($booking);

        $message = 'เช็คอินสำเร็จ';
        if ($auto !== null) {
            $message = $auto['next']
                ? "เช็คอินสำเร็จ • จุดนี้ครบแล้ว แจ้งจุดถัดไป: {$auto['next']['label']}"
                : 'เช็คอินสำเร็จ • รับครบทุกจุดแล้ว';
        }

        return $this->success(
            new BookingResource($booking->fresh($this->checkInRelations())),
            $message
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
                'type' => $schedule->vehicle->type,
                'capacity' => $schedule->vehicle->capacity,
                'color' => $schedule->vehicle->color,
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

        $bookings = Booking::with(['user', 'pickupPoint', 'passengers.pickupPoint', 'seats'])
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
                'checked_in_passengers' => $bookings->where('checked_in', true)
                    ->sum(fn (Booking $booking) => $booking->passengers->count()),
                'care_alerts' => $bookings->sum(
                    fn (Booking $booking) => $booking->passengers
                        ->filter(fn ($p) => filled($p->allergies)
                            || filled($p->health_notes)
                            || $p->halal_food)
                        ->count()
                ),
            ],
            'pickup_groups' => $this->buildPickupGroups($bookings),
            'seat_map' => $this->buildSeatMap($schedule, $bookings),
            'bookings' => $manifest,
        ], 'รายชื่อผู้โดยสาร');
    }

    /**
     * Overlay each booked seat with its occupant (name + nickname, matched from
     * the booking's passenger list) onto the vehicle layout, so staff can see
     * who sits where. Returns null when this schedule has no seat assignments
     * (e.g. charters / join trips) — the app then hides the seat-map section.
     */
    private function buildSeatMap(TripSchedule $schedule, Collection $bookings): ?array
    {
        $occupants = [];

        foreach ($bookings as $booking) {
            $byName = $booking->passengers->keyBy(fn ($p) => trim((string) $p->name));

            foreach ($booking->seats as $seat) {
                $name = trim((string) $seat->passenger_name);
                $passenger = $name !== '' ? $byName->get($name) : null;

                $occupants[$seat->seat_id] = [
                    'name' => $name !== '' ? $name : ($booking->user?->name ?? ''),
                    'nickname' => $passenger?->nickname,
                    'booking_ref' => $booking->booking_ref,
                    'checked_in' => (bool) $booking->checked_in,
                ];
            }
        }

        if (empty($occupants)) {
            return null;
        }

        $layout = $schedule->resolveSeatLayout();
        $seats = collect($layout['seats'])->map(fn ($seat) => [
            'id' => $seat['id'],
            'label' => $seat['label'] ?? $seat['id'],
            'occupant' => $occupants[$seat['id']] ?? null,
        ])->values();

        return [
            'rows' => $layout['rows'] ?? 0,
            'columns' => $layout['columns'] ?? [],
            'seats' => $seats,
            'front_seat' => $layout['front_seat'] ?? null,
            'last_row_center' => $layout['last_row_center'] ?? [],
            'front_label' => $layout['front_label'] ?? 'หน้ารถ',
            'rear_label' => $layout['rear_label'] ?? 'ท้ายรถ',
            'show_driver' => $layout['show_driver'] ?? true,
            'occupied' => count($occupants),
            'total' => collect($layout['seats'])->count(),
        ];
    }

    /**
     * จัดผู้โดยสารทุกคนเป็นกลุ่มตามจุดรับ (ผู้โดยสารแต่ละคนอาจเลือกจุดรับเองได้
     * ไม่งั้นใช้จุดรับระดับการจอง) พร้อมข้อมูลครบ + สถานะเช็คอินรายคน
     */
    private function buildPickupGroups(Collection $bookings): array
    {
        $groups = [];

        foreach ($bookings as $booking) {
            foreach ($booking->passengers as $passenger) {
                $point = $passenger->pickupPoint ?: $booking->pickupPoint;
                $key = $point?->id ?? 0;

                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'id' => $point?->id,
                        'label' => $point
                            ? ($point->pickup_location ?: $point->region_label ?: 'จุดรับ')
                            : 'ไม่ระบุจุดรับ',
                        'region_label' => $point?->region_label,
                        'map_url' => $point?->map_url,
                        'notes' => $point?->notes,
                        'sort_order' => $point?->sort_order ?? 9999,
                        'completed_at' => $point?->completed_at?->toIso8601String(),
                        'passengers' => [],
                    ];
                }

                $groups[$key]['passengers'][] = [
                    'title' => $passenger->title,
                    'name' => $passenger->name,
                    'full_name' => trim(($passenger->title ? $passenger->title.' ' : '').$passenger->name),
                    'nickname' => $passenger->nickname,
                    'phone' => $passenger->phone ?: $booking->user?->phone,
                    'checked_in' => (bool) $booking->checked_in,
                    'booking_ref' => $booking->booking_ref,
                    // Profile photo of the account that made the booking (only when
                    // a real avatar was uploaded — passengers have no own photo).
                    'avatar_url' => $booking->user?->avatar
                        ? $booking->user->avatar_url
                        : null,
                    // Safety / care info surfaced at-a-glance in the manifest.
                    'allergies' => $passenger->allergies,
                    'health_notes' => $passenger->health_notes,
                    'halal_food' => (bool) $passenger->halal_food,
                    'blood_group' => $passenger->blood_group,
                    'emergency_contact' => $passenger->emergency_contact,
                    'emergency_phone' => $passenger->emergency_phone,
                ];
            }
        }

        return collect($groups)
            ->sortBy('sort_order')
            ->map(function ($group) {
                $group['passenger_count'] = count($group['passengers']);
                $group['checked_in_count'] = collect($group['passengers'])
                    ->where('checked_in', true)->count();
                unset($group['sort_order']);

                return $group;
            })
            ->values()
            ->all();
    }

    /**
     * แจ้งเตือนผู้โดยสารว่าคนขับเริ่มออกเดินทางแล้ว เรียกตอนคนขับกด "เริ่มติดตาม".
     * ส่งครั้งเดียวต่อรอบเดินทางต่อวัน (idempotent ด้วย cache).
     */
    /**
     * Mark a pickup point as picked-up (or undo it). On completion, passengers
     * waiting at the next pending pickup point are notified the van is on its
     * way so they can be ready. Returns refreshed pickup-point statuses.
     */
    public function completePickup(Request $request, int $id, int $pointId): JsonResponse
    {
        if (! $this->hasDriverAccess($request)) {
            return $this->error('บัญชีนี้ยังไม่ได้รับสิทธิ์คนขับหรือสตาฟ', 403);
        }

        $schedule = TripSchedule::with(['trip', 'pickupPoints', 'vehicle', 'staff'])->find($id);

        if (! $schedule) {
            return $this->error('ไม่พบรอบเดินทางนี้', 404);
        }

        if (! $this->canAccessSchedule($request, $schedule)) {
            return $this->error('คุณไม่มีสิทธิ์อัปเดตรอบเดินทางนี้', 403);
        }

        $validated = $request->validate([
            'completed' => ['nullable', 'boolean'],
        ]);
        $completed = $validated['completed'] ?? true;

        $point = $schedule->pickupPoints->firstWhere('id', $pointId);
        if (! $point) {
            return $this->error('ไม่พบจุดรับนี้ในรอบเดินทาง', 404);
        }

        if (! $completed) {
            $point->update(['completed_at' => null]);

            return $this->success([
                'point_id' => $point->id,
                'completed_at' => null,
                'next_point' => null,
                'notified' => 0,
                'pickup_points' => $this->pickupPointStatuses($schedule),
            ], 'ยกเลิกการรับจุดนี้แล้ว');
        }

        $result = $this->markPickupCompleted($schedule, $point);

        return $this->success([
            'point_id' => $point->id,
            'completed_at' => $point->completed_at?->toIso8601String(),
            'next_point' => $result['next'],
            'notified' => $result['notified'],
            'pickup_points' => $this->pickupPointStatuses($schedule),
        ], $result['next']
            ? "แจ้งจุดรับถัดไปแล้ว: {$result['next']['label']}"
            : 'รับครบทุกจุดแล้ว');
    }

    /**
     * Auto-complete a booking's pickup point once everyone confirmed there has
     * checked in (e.g. via QR), firing the next-stop notification. Returns the
     * markPickupCompleted result, or null when nothing was triggered.
     */
    private function maybeAutoCompletePickup(Booking $booking): ?array
    {
        $pointId = $booking->pickup_point_id;
        if (! $pointId) {
            return null;
        }

        $schedule = $booking->schedule;
        $point = $schedule?->pickupPoints->firstWhere('id', $pointId);
        if (! $point || $point->completed_at) {
            return null;
        }

        $stillWaiting = Booking::where('schedule_id', $schedule->id)
            ->where('status', 'confirmed')
            ->where('pickup_point_id', $pointId)
            ->where('checked_in', false)
            ->exists();

        if ($stillWaiting) {
            return null;
        }

        return $this->markPickupCompleted($schedule, $point);
    }

    /**
     * Mark a pickup point completed (idempotent). On the transition into
     * completed, notify passengers waiting at the next pending point that the
     * van is on its way. Returns ['next' => ?array, 'notified' => int].
     */
    private function markPickupCompleted(TripSchedule $schedule, SchedulePickupPoint $point): array
    {
        $justCompleted = ! $point->completed_at;
        if ($justCompleted) {
            $point->update(['completed_at' => now()]);
        }

        $next = $schedule->pickupPoints
            ->whereNull('completed_at')
            ->sortBy('sort_order')
            ->first();

        if (! $next) {
            return ['next' => null, 'notified' => 0];
        }

        $nextLabel = $next->pickup_location ?: $next->region_label ?: 'จุดรับถัดไป';
        $notified = 0;

        // Only fire notifications on the transition into completed.
        if ($justCompleted) {
            $tripTitle = $schedule->trip?->title ?? 'ทริปของคุณ';
            $bookings = Booking::where('schedule_id', $schedule->id)
                ->where('status', 'confirmed')
                ->whereNotNull('user_id')
                ->where('pickup_point_id', $next->id)
                ->get(['id', 'booking_ref', 'user_id']);

            foreach ($bookings as $booking) {
                SmartNotification::send(
                    $booking->user_id,
                    'pickup_approaching',
                    'รถกำลังมารับคุณ 🚐',
                    "รถทริป \"{$tripTitle}\" กำลังมุ่งหน้าไปยังจุดรับ \"{$nextLabel}\" กรุณาเตรียมตัวให้พร้อม",
                    ['booking_ref' => $booking->booking_ref, 'schedule_id' => $schedule->id],
                );
                $notified++;
            }
        }

        return ['next' => ['id' => $next->id, 'label' => $nextLabel], 'notified' => $notified];
    }

    /** Pickup-point completion statuses for a schedule, ordered by route. */
    private function pickupPointStatuses(TripSchedule $schedule): array
    {
        return $schedule->pickupPoints
            ->sortBy('sort_order')
            ->map(fn ($p) => [
                'id' => $p->id,
                'label' => $p->pickup_location ?: $p->region_label ?: 'จุดรับ',
                'completed_at' => $p->completed_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

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

        // Drop a system notice into the trip's group chat so the departure is
        // visible in-thread alongside the push notification.
        app(ChatService::class)->postSystem(
            $schedule,
            'คนขับเริ่มออกเดินทางแล้ว 🚐 ติดตามตำแหน่งรถแบบเรียลไทม์ได้เลย',
        );

        Cache::put($cacheKey, true, now()->endOfDay());

        return $this->success(
            ['notified' => $bookings->count(), 'already_sent' => false],
            'ส่งแจ้งเตือนออกเดินทางให้ผู้โดยสารแล้ว'
        );
    }
}
