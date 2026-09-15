<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TripSchedule;
use App\Support\ThaiDate;
use Illuminate\Support\Collection;

/**
 * ใบเตรียมของต่อรอบเดินทาง
 *
 * ลูกค้าเช่าเป็น "รายการ" (ชุดเต็นท์ 2 ชุด) แต่คนที่ไปหยิบของในโกดังต้องการ
 * "ชิ้น" (เต็นท์ 2 · ถุงนอน 2 · แผ่นรองนอน 2) เพราะของวางแยกชั้นกัน บริการนี้จึง
 * คืนสองมุมพร้อมกัน: รายการตามที่ลูกค้าเช่า และรายการที่แตกเป็นชิ้นแล้ว
 *
 * ส่วนประกอบของชุดอ่านจาก `rental_items[].parts` ของทริป ณ ปัจจุบัน ไม่ใช่
 * snapshot บนการจอง — ต่างจากราคาโดยตั้งใจ: ราคาต้องเป็นราคาที่ตกลงกันวันจอง
 * แต่ "ชุดเต็นท์ประกอบด้วยอะไร" คือของที่อยู่ในโกดังวันนี้ แก้ที่ทริปครั้งเดียว
 * รอบที่ยังไม่ออกเดินทางต้องได้รายการใหม่ทั้งหมด ถ้าอุปกรณ์ชิ้นนั้นถูกถอดออกจาก
 * ทริปไปแล้ว ค่อยถอยไปใช้ส่วนประกอบที่แช่ไว้บนการจอง
 */
class RentalPickListService
{
    /** สถานะการจองที่ถือว่าต้องเตรียมของจริง */
    public const LIVE_STATUSES = ['confirmed', 'completed'];

    public function forSchedule(TripSchedule $schedule): array
    {
        $bookings = Booking::with('user')
            ->where('schedule_id', $schedule->id)
            ->whereIn('status', self::LIVE_STATUSES)
            ->where('rentals_total', '>', 0)
            ->orderBy('booking_ref')
            ->get();

        $catalog = $this->catalogParts($schedule);

        $items = [];      // รวมตามรายการที่ลูกค้าเช่า
        $picking = [];    // รวมตามชิ้นที่ต้องหยิบจริง
        $perBooking = [];

        foreach ($bookings as $booking) {
            $lines = $this->linesOf($booking, $catalog);

            if ($lines->isEmpty()) {
                continue;
            }

            foreach ($lines as $line) {
                $key = $line['name'];
                $items[$key] ??= [
                    'name' => $line['name'],
                    'image_url' => $line['image_url'],
                    'quantity' => 0,
                    'revenue' => 0.0,
                    'renters' => 0,
                    'is_set' => $line['parts'] !== [],
                    'parts' => [],
                    'pieces_each' => $this->piecesEach($line['parts']),
                ];
                $items[$key]['quantity'] += $line['quantity'];
                $items[$key]['revenue'] += $line['total_price'];
                $items[$key]['renters']++;

                if ($items[$key]['image_url'] === '' && $line['image_url'] !== '') {
                    $items[$key]['image_url'] = $line['image_url'];
                }

                foreach ($line['parts'] as $part) {
                    $total = $part['quantity'] * $line['quantity'];

                    $items[$key]['parts'][$part['name']] ??= [
                        'name' => $part['name'],
                        'quantity_each' => $part['quantity'],
                        'quantity' => 0,
                    ];
                    $items[$key]['parts'][$part['name']]['quantity'] += $total;

                    $this->addPiece($picking, $part['name'], $total, $line['name'], true);
                }

                if ($line['parts'] === []) {
                    $this->addPiece($picking, $line['name'], $line['quantity'], $line['name'], false);
                }
            }

            $perBooking[] = [
                'booking_ref' => $booking->booking_ref,
                'customer_name' => $booking->user?->name ?? 'ลูกค้า',
                'phone' => $booking->user?->phone,
                'status' => $booking->status,
                'rentals_total' => (float) $booking->rentals_total,
                'items' => $lines->values()->all(),
            ];
        }

        $items = collect($items)
            ->map(function (array $item) {
                $item['parts'] = collect($item['parts'])->values()->all();

                return $item;
            })
            ->sortByDesc('quantity')
            ->values();

        $picking = collect($picking)
            ->map(function (array $piece) {
                $piece['sources'] = collect($piece['sources'])
                    ->sortByDesc('quantity')
                    ->values()
                    ->all();

                return $piece;
            })
            ->sortByDesc('quantity')
            ->values();

        return [
            'schedule' => [
                'id' => $schedule->id,
                'trip_title' => $schedule->trip?->title,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'departure_date_thai' => ThaiDate::full($schedule->departure_date),
            ],
            'items' => $items->all(),
            'picking' => $picking->all(),
            'bookings' => $perBooking,
            'totals' => [
                'pieces' => (int) $items->sum('quantity'),
                'revenue' => (float) $items->sum('revenue'),
                'bookings' => count($perBooking),
                'items' => $items->count(),
                'picking_pieces' => (int) $picking->sum('quantity'),
                'picking_lines' => $picking->count(),
            ],
        ];
    }

    /** ส่วนประกอบของอุปกรณ์แต่ละชื่อ ตามที่ทริปตั้งไว้ตอนนี้ */
    private function catalogParts(TripSchedule $schedule): array
    {
        $catalog = [];

        foreach ($schedule->trip?->rental_items ?? [] as $option) {
            if (! is_array($option) || empty($option['name'])) {
                continue;
            }

            $catalog[(string) $option['name']] = $this->normalizeParts($option['parts'] ?? []);
        }

        return $catalog;
    }

    /** บรรทัดของที่เช่าในใบจองหนึ่งใบ พร้อมส่วนประกอบที่ถอดออกมาแล้ว */
    private function linesOf(Booking $booking, array $catalog): Collection
    {
        return collect($booking->selected_rentals ?? [])
            ->map(function ($rental) use ($catalog) {
                $name = (string) ($rental['name'] ?? '');

                return [
                    'name' => $name,
                    'quantity' => (int) ($rental['quantity'] ?? 0),
                    'unit_price' => (float) ($rental['unit_price'] ?? 0),
                    'total_price' => (float) ($rental['total_price'] ?? 0),
                    'image_url' => (string) ($rental['image_url'] ?? ''),
                    'parts' => $catalog[$name] ?? $this->normalizeParts($rental['parts'] ?? []),
                ];
            })
            ->filter(fn ($line) => $line['name'] !== '' && $line['quantity'] > 0)
            ->values();
    }

    /** [{name, quantity}] ที่กรองแถวว่างและจำนวน 0 ออกแล้ว */
    private function normalizeParts($parts): array
    {
        if (! is_array($parts)) {
            return [];
        }

        return collect($parts)
            ->map(fn ($part) => [
                'name' => is_array($part) ? trim((string) ($part['name'] ?? '')) : '',
                'quantity' => is_array($part) ? max(0, (int) ($part['quantity'] ?? 0)) : 0,
            ])
            ->filter(fn ($part) => $part['name'] !== '' && $part['quantity'] > 0)
            ->values()
            ->all();
    }

    private function piecesEach(array $parts): int
    {
        return $parts === [] ? 1 : (int) collect($parts)->sum('quantity');
    }

    /**
     * บวกชิ้นเข้ารายการหยิบของ พร้อมจำว่าชิ้นนี้มาจากชุดไหนบ้าง — ถุงนอนอาจมาจาก
     * ทั้ง "ชุดเต็นท์" และจากการเช่าถุงนอนเดี่ยว ๆ ในรอบเดียวกัน
     */
    private function addPiece(array &$picking, string $name, int $quantity, string $source, bool $fromSet): void
    {
        $picking[$name] ??= [
            'name' => $name,
            'quantity' => 0,
            'from_set' => false,
            'sources' => [],
        ];

        $picking[$name]['quantity'] += $quantity;
        $picking[$name]['from_set'] = $picking[$name]['from_set'] || $fromSet;

        $picking[$name]['sources'][$source] ??= [
            'name' => $source,
            'quantity' => 0,
            'is_set' => $fromSet,
        ];
        $picking[$name]['sources'][$source]['quantity'] += $quantity;
    }
}
