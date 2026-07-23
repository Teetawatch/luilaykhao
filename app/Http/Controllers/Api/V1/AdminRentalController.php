<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ใบรวมอุปกรณ์เช่าที่ต้องขนไปในแต่ละรอบ
 *
 * ลูกค้าเช่าอุปกรณ์ตอนจอง (ดู `rental_items` บนทริป และ `selected_rentals` ที่
 * แช่แข็งไว้บนการจอง) แต่เดิมข้อมูลนี้อ่านได้จากใบจองรายคนเท่านั้น ทีมงานจึงต้อง
 * เปิดทีละใบมานับเองว่าต้องเตรียมถุงนอนกี่ใบ หน้านี้รวมยอดต่อรอบให้ พร้อมแจกแจง
 * ว่าของชิ้นไหนของใคร เพื่อใช้เป็นเช็กลิสต์ตอนขนของขึ้นรถและตอนคืนของ
 */
class AdminRentalController extends Controller
{
    use ApiResponse;

    /** สถานะการจองที่ถือว่าต้องเตรียมของจริง */
    private const LIVE_STATUSES = ['confirmed', 'completed'];

    /**
     * รอบที่มีคนเช่าอุปกรณ์ — ตั้งต้นเฉพาะรอบที่ยังไม่ออกเดินทาง
     */
    public function schedules(Request $request): JsonResponse
    {
        $includePast = $request->boolean('include_past');
        $today = now('Asia/Bangkok')->toDateString();

        $rentalTotals = Booking::query()
            ->whereIn('status', self::LIVE_STATUSES)
            ->where('rentals_total', '>', 0)
            ->selectRaw('schedule_id, COUNT(*) as bookings, SUM(rentals_total) as revenue')
            ->groupBy('schedule_id')
            ->get()
            ->keyBy('schedule_id');

        if ($rentalTotals->isEmpty()) {
            return $this->success(['schedules' => []]);
        }

        $schedules = TripSchedule::with('trip')
            ->whereIn('id', $rentalTotals->keys())
            ->when(! $includePast, fn ($q) => $q->whereDate('departure_date', '>=', $today))
            ->orderBy('departure_date')
            ->get();

        return $this->success([
            'schedules' => $schedules->map(fn (TripSchedule $s) => [
                'id' => $s->id,
                'trip_title' => $s->trip?->title,
                'departure_date' => $s->departure_date?->toDateString(),
                'departure_date_thai' => ThaiDate::full($s->departure_date),
                'is_past' => $s->departure_date?->toDateString() < $today,
                'bookings_with_rentals' => (int) ($rentalTotals[$s->id]->bookings ?? 0),
                'rentals_revenue' => (float) ($rentalTotals[$s->id]->revenue ?? 0),
            ])->values(),
        ]);
    }

    /**
     * ใบรวมของรอบเดียว — ยอดรวมต่อชิ้น + รายชื่อคนที่เช่า
     */
    public function show(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        $bookings = Booking::with('user')
            ->where('schedule_id', $scheduleId)
            ->whereIn('status', self::LIVE_STATUSES)
            ->where('rentals_total', '>', 0)
            ->orderBy('booking_ref')
            ->get();

        // รวมยอดต่อชื่ออุปกรณ์ — ใช้ snapshot บนการจอง ไม่ใช่ catalog ปัจจุบัน
        // เพราะราคา/รายการบนทริปอาจถูกแก้ไปแล้วหลังลูกค้าจอง
        $items = [];
        $perBooking = [];

        foreach ($bookings as $booking) {
            $rentals = collect($booking->selected_rentals ?? [])
                ->map(fn ($r) => [
                    'name' => (string) ($r['name'] ?? ''),
                    'quantity' => (int) ($r['quantity'] ?? 0),
                    'unit_price' => (float) ($r['unit_price'] ?? 0),
                    'total_price' => (float) ($r['total_price'] ?? 0),
                    'image_url' => (string) ($r['image_url'] ?? ''),
                ])
                ->filter(fn ($r) => $r['name'] !== '' && $r['quantity'] > 0)
                ->values();

            if ($rentals->isEmpty()) {
                continue;
            }

            foreach ($rentals as $rental) {
                $key = $rental['name'];
                $items[$key] ??= [
                    'name' => $rental['name'],
                    'image_url' => $rental['image_url'],
                    'quantity' => 0,
                    'revenue' => 0.0,
                    'renters' => 0,
                ];
                $items[$key]['quantity'] += $rental['quantity'];
                $items[$key]['revenue'] += $rental['total_price'];
                $items[$key]['renters']++;
                if ($items[$key]['image_url'] === '' && $rental['image_url'] !== '') {
                    $items[$key]['image_url'] = $rental['image_url'];
                }
            }

            $perBooking[] = [
                'booking_ref' => $booking->booking_ref,
                'customer_name' => $booking->user?->name ?? 'ลูกค้า',
                'phone' => $booking->user?->phone,
                'status' => $booking->status,
                'rentals_total' => (float) $booking->rentals_total,
                'items' => $rentals,
            ];
        }

        $items = collect($items)->sortByDesc('quantity')->values();

        return $this->success([
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_date_thai' => ThaiDate::full($schedule->departure_date),
            ],
            'items' => $items,
            'bookings' => $perBooking,
            'totals' => [
                'pieces' => (int) $items->sum('quantity'),
                'revenue' => (float) $items->sum('revenue'),
                'bookings' => count($perBooking),
            ],
        ]);
    }
}
