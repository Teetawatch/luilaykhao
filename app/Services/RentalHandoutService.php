<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRentalHandout;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * ใบแจกอุปกรณ์เช่าของรอบเดินทาง — สตาฟใช้หน้างานเพื่อแจกก่อนออกเดินทาง
 * และไล่รับคืนตอนจบทริป
 *
 * รายการอุปกรณ์อ่านจาก snapshot บน bookings.selected_rentals (ไม่ใช่ catalog
 * ปัจจุบันของทริป เพราะแอดมินแก้ราคา/รายการทีหลังได้) ส่วนสถานะแจก/คืนเก็บใน
 * booking_rental_handouts ที่สร้างแบบ lazy ตอนสตาฟติ๊กครั้งแรก
 */
class RentalHandoutService
{
    /** สถานะการจองที่ยังนับเป็นผู้ร่วมทริปจริง */
    private const LIVE_STATUSES = ['pending', 'confirmed'];

    /**
     * ใบรวมของทั้งรอบ: ยอดต่อชิ้น + รายการรายการจอง
     *
     * @return array<string, mixed>
     */
    public function forSchedule(TripSchedule $schedule): array
    {
        $bookings = Booking::with(['user', 'passengers'])
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', self::LIVE_STATUSES)
            ->orderBy('booking_ref')
            ->get()
            ->filter(fn (Booking $b) => $this->rentalsOf($b)->isNotEmpty())
            ->values();

        $states = BookingRentalHandout::whereIn('booking_id', $bookings->pluck('id'))
            ->get()
            ->groupBy('booking_id');

        $items = [];
        $rows = [];

        foreach ($bookings as $booking) {
            $byName = ($states[$booking->id] ?? collect())->keyBy('item_name');
            $rentals = [];

            foreach ($this->rentalsOf($booking) as $rental) {
                $state = $byName->get($rental['name']);
                $handedOut = $state?->handed_out_at !== null;
                $returned = $state?->returned_at !== null;

                $rentals[] = [
                    'name' => $rental['name'],
                    'quantity' => $rental['quantity'],
                    'image_url' => $rental['image_url'],
                    'handed_out' => $handedOut,
                    'handed_out_at' => $state?->handed_out_at?->toISOString(),
                    'returned' => $returned,
                    'returned_at' => $state?->returned_at?->toISOString(),
                ];

                $key = $rental['name'];
                $items[$key] ??= [
                    'name' => $rental['name'],
                    'image_url' => $rental['image_url'],
                    'quantity' => 0,
                    'handed_out' => 0,
                    'returned' => 0,
                ];
                $items[$key]['quantity'] += $rental['quantity'];
                $items[$key]['handed_out'] += $handedOut ? $rental['quantity'] : 0;
                $items[$key]['returned'] += $returned ? $rental['quantity'] : 0;
                if ($items[$key]['image_url'] === '' && $rental['image_url'] !== '') {
                    $items[$key]['image_url'] = $rental['image_url'];
                }
            }

            $rows[] = [
                'booking_id' => $booking->id,
                'booking_ref' => $booking->booking_ref,
                // การจองแบบไม่มีบัญชี (guest) ให้ใช้ชื่อผู้โดยสารคนแรกแทน
                'customer_name' => $booking->user?->name ?? $booking->passengers->first()?->name,
                'customer_phone' => $booking->user?->phone ?? $booking->passengers->first()?->phone,
                'checked_in' => (bool) $booking->checked_in,
                'items' => $rentals,
                // ใบจองนี้แจกครบ/รับคืนครบแล้วหรือยัง — ใช้จัดกลุ่ม "ยังไม่แจก" ในแอป
                'all_handed_out' => collect($rentals)->every(fn ($r) => $r['handed_out']),
                'all_returned' => collect($rentals)->every(fn ($r) => $r['returned']),
            ];
        }

        $totals = collect($items)->values();

        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
            ],
            'summary' => [
                'bookings' => count($rows),
                'total_pieces' => (int) $totals->sum('quantity'),
                'handed_out_pieces' => (int) $totals->sum('handed_out'),
                'returned_pieces' => (int) $totals->sum('returned'),
            ],
            'items' => $totals->all(),
            'bookings' => $rows,
        ];
    }

    /**
     * ติ๊กแจก/รับคืนอุปกรณ์หนึ่งชิ้นของการจองหนึ่งใบ (ติ๊กซ้ำ = ยกเลิกการติ๊ก)
     *
     * @param  'handout'|'return'  $action
     */
    public function mark(User $staff, Booking $booking, string $itemName, string $action, bool $done): BookingRentalHandout
    {
        $rental = $this->rentalsOf($booking)->firstWhere('name', $itemName);

        if (! $rental) {
            throw new \Exception('ไม่พบอุปกรณ์ชิ้นนี้ในรายการเช่าของการจอง');
        }

        $handout = BookingRentalHandout::firstOrNew([
            'booking_id' => $booking->id,
            'item_name' => $itemName,
        ]);
        $handout->quantity = $rental['quantity'];

        if ($action === 'handout') {
            $handout->handed_out_at = $done ? now() : null;
            $handout->handed_out_by_id = $done ? $staff->id : null;

            // ยังไม่ได้แจก จะรับคืนไม่ได้ — ยกเลิกการแจกจึงต้องล้างสถานะรับคืนด้วย
            if (! $done) {
                $handout->returned_at = null;
                $handout->returned_by_id = null;
            }
        } else {
            if ($done && $handout->handed_out_at === null) {
                throw new \Exception('ต้องติ๊ก "แจกแล้ว" ก่อนจึงจะรับคืนได้');
            }

            $handout->returned_at = $done ? now() : null;
            $handout->returned_by_id = $done ? $staff->id : null;
        }

        $handout->save();

        return $handout;
    }

    /**
     * รายการอุปกรณ์ที่การจองนี้เช่า (จาก snapshot) — ตัดรายการที่ชื่อว่างหรือจำนวน 0
     *
     * @return Collection<int, array{name: string, quantity: int, image_url: string}>
     */
    private function rentalsOf(Booking $booking): Collection
    {
        return collect($booking->selected_rentals ?? [])
            ->map(fn ($r) => [
                'name' => trim((string) ($r['name'] ?? '')),
                'quantity' => (int) ($r['quantity'] ?? 0),
                'image_url' => (string) ($r['image_url'] ?? ''),
            ])
            ->filter(fn ($r) => $r['name'] !== '' && $r['quantity'] > 0)
            ->values();
    }
}
