<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingRentalOrder;
use App\Models\SmartNotification;
use App\Models\User;
use App\Support\ThaiDate;
use App\Support\TripRentalItems;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ลูกค้าเช่าอุปกรณ์เพิ่มเองหลังจอง แล้วจ่ายในแอปได้เลย — แทนการทักแอดมินให้แก้ใบจอง
 *
 * ของที่สั่งถูกรวมเข้า `selected_rentals` + `total_amount` ของใบจองทันที ยอดจึง
 * กลายเป็น "ยอดเพิ่มเติม" ([Booking::extraDueAmount]) แล้วจ่ายผ่านทางเดิมทั้งหมด
 * (สลิป payments/charge-extra หรือ Beam purpose=extra) ไม่ต้องมีทางจ่ายใหม่
 *
 * ก้อนที่ยังไม่จ่ายเมื่อถึงเวลาปิดรับถูกถอดออกจากใบจอง ([expireOverdue]) — ใบเตรียม
 * ของจะได้ไม่มีของที่ไม่มีใครจ่าย
 */
class RentalOrderService
{
    /** สั่งได้ถึงสิ้นวัน "2 วันก่อนเดินทาง" (ออกวันเสาร์ = สั่งได้ถึงพฤหัสฯ) */
    public const CUTOFF_DAYS = 2;

    /** ก้อนที่สั่งตอนใกล้ปิด ยังมีเวลาโอนก่อนถูกถอดออก */
    public const PAYMENT_GRACE_MINUTES = 60;

    private const TIMEZONE = 'Asia/Bangkok';

    /** เวลาปิดรับ (เวลาไทย) — null เมื่อรอบไม่มีวันเดินทาง */
    public function closesAt(Booking $booking): ?Carbon
    {
        $date = $booking->schedule?->departure_date;
        if (! $date) {
            return null;
        }

        return Carbon::parse($date->toDateString(), self::TIMEZONE)
            ->subDays(self::CUTOFF_DAYS - 1)
            ->startOfDay();
    }

    /** เหตุที่สั่งไม่ได้ตอนนี้ — null = สั่งได้ */
    public function closedReason(Booking $booking): ?string
    {
        if (in_array($booking->status, ['cancelled', 'refunded'], true)) {
            return 'การจองนี้ถูกยกเลิกแล้ว';
        }

        if ($booking->status !== 'confirmed') {
            return 'ชำระค่าทริปให้เรียบร้อยก่อน จึงจะเช่าอุปกรณ์เพิ่มได้';
        }

        if ($booking->awaitsNewRound()) {
            return 'รอเลือกรอบเดินทางใหม่ก่อน จึงจะเช่าอุปกรณ์เพิ่มได้';
        }

        if ($this->catalog($booking) === []) {
            return 'ทริปนี้ไม่มีอุปกรณ์ให้เช่า';
        }

        $closesAt = $this->closesAt($booking);
        if (! $closesAt || now(self::TIMEZONE)->greaterThanOrEqualTo($closesAt)) {
            return 'ปิดรับเช่าอุปกรณ์ในแอปแล้ว (เช่าได้ถึง '.self::CUTOFF_DAYS.' วันก่อนเดินทาง) ทักทีมงานในแชทได้หากยังต้องการ';
        }

        return null;
    }

    /**
     * ก้อนสั้น ๆ สำหรับ BookingResource — แอปใช้ตัดสินว่าจะโชว์ปุ่ม "เช่าอุปกรณ์เพิ่ม" ไหม
     *
     * ไม่ query เพิ่ม: อ่านจาก schedule.trip ที่โหลดมาแล้วเท่านั้น
     *
     * @return array{open: bool, closes_at: ?string, last_day: ?string, item_count: int}|null
     */
    public function summaryFor(Booking $booking): ?array
    {
        $count = count($this->catalog($booking));
        if ($count === 0) {
            return null;
        }

        $closesAt = $this->closesAt($booking);

        return [
            'open' => $this->closedReason($booking) === null,
            'closes_at' => $closesAt?->toIso8601String(),
            'last_day' => $closesAt?->copy()->subDay()->toDateString(),
            'item_count' => $count,
        ];
    }

    /**
     * ทุกอย่างที่หน้า "เช่าอุปกรณ์เพิ่ม" ต้องใช้
     *
     * @return array<string, mixed>
     */
    public function overview(Booking $booking): array
    {
        $booking->loadMissing('schedule.trip');
        $closesAt = $this->closesAt($booking);
        $reason = $this->closedReason($booking);

        $orders = $booking->rentalOrders()->latest('id')->get();

        return [
            'booking_ref' => $booking->booking_ref,
            'open' => $reason === null,
            'closed_reason' => $reason,
            'closes_at' => $closesAt?->toIso8601String(),
            'last_day' => $closesAt?->copy()->subDay()->toDateString(),
            'last_day_label' => $closesAt ? ThaiDate::full($closesAt->copy()->subDay()) : null,
            'cutoff_days' => self::CUTOFF_DAYS,
            'catalog' => array_map(fn (array $item) => [
                'key' => $item['key'],
                'name' => $item['name'],
                'price' => $item['price'],
                'description' => $item['description'],
                'image_url' => $item['image_url'],
                'parts' => $item['parts'],
            ], $this->catalog($booking)),
            'passenger_count' => $booking->passengers()->count(),
            'selected_rentals' => $booking->selected_rentals ?? [],
            'rentals_total' => (float) $booking->rentals_total,
            'extra_due' => $booking->extraDueAmount(),
            'orders' => $orders->map(fn (BookingRentalOrder $order) => $this->present($order))->all(),
        ];
    }

    /**
     * สั่งเช่า — รวมของเข้าใบจองแล้วคืนก้อนที่ต้องจ่าย
     *
     * @param  array<string, int>  $quantities  key ของอุปกรณ์ → จำนวน
     *
     * @throws \Exception ข้อความไทยพร้อมโชว์ลูกค้า
     */
    public function place(Booking $booking, User $user, array $quantities): BookingRentalOrder
    {
        $booking->loadMissing('schedule.trip');

        if ($reason = $this->closedReason($booking)) {
            throw new \Exception($reason);
        }

        $catalog = $this->catalog($booking);
        $catalogKeys = array_column($catalog, 'key');
        foreach (array_keys($quantities) as $key) {
            if (! in_array((string) $key, $catalogKeys, true)) {
                throw new \Exception('อุปกรณ์ที่เลือกไม่มีให้เช่าในทริปนี้แล้ว ลองเปิดหน้านี้ใหม่อีกครั้ง');
            }
        }

        $items = TripRentalItems::snapshotSelection($quantities, $catalog);
        if ($items === []) {
            throw new \Exception('เลือกอุปกรณ์อย่างน้อย 1 ชิ้น');
        }

        $amount = round(array_sum(array_column($items, 'total_price')), 2);

        $order = DB::transaction(function () use ($booking, $user, $items, $amount) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            // ก้อนเดียวต่อครั้ง — สองก้อนค้างจ่ายพร้อมกันทำให้ยอดเพิ่มเติมรวมกัน
            // แล้วตอบไม่ได้ว่าเงินที่โอนมาจ่ายก้อนไหน
            if ($locked->rentalOrders()->where('status', BookingRentalOrder::STATUS_AWAITING)->exists()) {
                throw new \Exception('มีรายการเช่าที่รอชำระอยู่ ชำระหรือยกเลิกรายการเดิมก่อนสั่งเพิ่ม');
            }

            $locked->update([
                'selected_rentals' => $this->mergeInto($locked->selected_rentals ?? [], $items),
                'rentals_total' => round((float) $locked->rentals_total + $amount, 2),
                'total_amount' => round((float) $locked->total_amount + $amount, 2),
            ]);

            return BookingRentalOrder::create([
                'booking_id' => $locked->id,
                'user_id' => $user->id,
                'items' => $items,
                'amount' => $amount,
                'status' => BookingRentalOrder::STATUS_AWAITING,
            ]);
        });

        return $order;
    }

    /**
     * ยกเลิกก้อนที่ยังไม่จ่าย — ถอดของกับยอดออกจากใบจองคืนตามเดิม
     *
     * @throws \Exception
     */
    public function cancel(BookingRentalOrder $order, string $reason = 'ลูกค้ายกเลิกเอง'): BookingRentalOrder
    {
        DB::transaction(function () use ($order, $reason) {
            $fresh = BookingRentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== BookingRentalOrder::STATUS_AWAITING) {
                throw new \Exception('รายการนี้ยกเลิกไม่ได้แล้ว');
            }

            $booking = Booking::whereKey($fresh->booking_id)->lockForUpdate()->firstOrFail();
            $amount = (float) $fresh->amount;
            $remaining = $this->removeFrom($booking->selected_rentals ?? [], $fresh->items ?? []);

            $booking->update([
                'selected_rentals' => $remaining,
                'rentals_total' => max(0, round((float) $booking->rentals_total - $amount, 2)),
                'total_amount' => max(0, round((float) $booking->total_amount - $amount, 2)),
            ]);

            // บรรทัดที่หายไปทั้งบรรทัดต้องไม่ค้างสถานะ "แจกแล้ว" ไว้ (ผูกด้วยชื่อ)
            $booking->rentalHandouts()
                ->whereNotIn('item_name', array_column($remaining, 'name') ?: [''])
                ->delete();

            $fresh->update([
                'status' => BookingRentalOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
        });

        return $order->refresh();
    }

    /**
     * เรียกหลังรับยอดเพิ่มเติม (สลิปหรือ Beam) — ถ้าใบจองไม่ค้างอะไรแล้ว ก้อนที่รอจ่าย
     * ทั้งหมดถือว่าจ่ายแล้ว
     */
    public function settle(Booking $booking): void
    {
        $booking = $booking->fresh(['installmentPayments', 'schedule.trip']);
        if (! $booking || $booking->extraDueAmount() > 0) {
            return;
        }

        $booking->rentalOrders()
            ->where('status', BookingRentalOrder::STATUS_AWAITING)
            ->get()
            ->each(function (BookingRentalOrder $order) use ($booking) {
                $order->update(['status' => BookingRentalOrder::STATUS_PAID, 'paid_at' => now()]);
                $this->notifyTeam($order->setRelation('booking', $booking));
            });
    }

    /**
     * ปิดรับแล้วยังไม่จ่าย = ถอดออกจากใบจอง แล้วบอกลูกค้า
     *
     * แอดมินอาจรับเงินให้นอกระบบ (แก้ paid_amount เอง) — ใบที่ไม่ค้างยอดแล้วจึงนับเป็นจ่าย
     *
     * @return array{paid: int, cancelled: int}
     */
    public function expireOverdue(): array
    {
        $result = ['paid' => 0, 'cancelled' => 0];
        $graceCutoff = now()->subMinutes(self::PAYMENT_GRACE_MINUTES);

        BookingRentalOrder::with(['booking.schedule.trip', 'booking.installmentPayments'])
            ->where('status', BookingRentalOrder::STATUS_AWAITING)
            ->where('created_at', '<=', $graceCutoff)
            ->get()
            ->each(function (BookingRentalOrder $order) use (&$result) {
                $booking = $order->booking;
                if (! $booking) {
                    return;
                }

                $closesAt = $this->closesAt($booking);
                $stillOpen = $booking->status === 'confirmed'
                    && $closesAt
                    && now(self::TIMEZONE)->lessThan($closesAt);
                if ($stillOpen) {
                    return;
                }

                if ($booking->status === 'confirmed' && $booking->extraDueAmount() <= 0) {
                    $order->update(['status' => BookingRentalOrder::STATUS_PAID, 'paid_at' => now()]);
                    $result['paid']++;

                    return;
                }

                try {
                    $this->cancel($order, 'ไม่ได้ชำระก่อนปิดรับ');
                    $result['cancelled']++;
                } catch (\Exception $e) {
                    Log::warning("RentalOrderService: could not expire order #{$order->id} — ".$e->getMessage());

                    return;
                }

                if ($booking->user_id && $booking->status === 'confirmed') {
                    SmartNotification::send(
                        $booking->user_id,
                        'rental_order_cancelled',
                        'ยกเลิกรายการเช่าอุปกรณ์แล้ว',
                        "รายการเช่าอุปกรณ์ของ {$booking->booking_ref} ยังไม่ได้ชำระก่อนปิดรับ ระบบจึงยกเลิกให้ หากยังต้องการ ทักทีมงานในแชทได้เลย",
                        ['booking_ref' => $booking->booking_ref, 'route' => 'booking'],
                    );
                }
            });

        return $result;
    }

    /** @return array<string, mixed> */
    public function present(BookingRentalOrder $order): array
    {
        return [
            'id' => $order->id,
            'status' => $order->status,
            'amount' => (float) $order->amount,
            'items' => array_map(fn (array $item) => [
                'key' => $item['key'] ?? '',
                'name' => $item['name'] ?? '',
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'total_price' => (float) ($item['total_price'] ?? 0),
                'image_url' => $item['image_url'] ?? '',
            ], $order->items ?? []),
            'created_at' => $order->created_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'cancel_reason' => $order->cancel_reason,
        ];
    }

    /** @return array<int, array<string, mixed>> แคตตาล็อกที่มีราคา — ของฟรีให้แอดมินจัดเอง */
    private function catalog(Booking $booking): array
    {
        $trip = $booking->schedule?->trip;
        if (! $trip) {
            return [];
        }

        return array_values(array_filter(
            $trip->rentalItems(),
            fn (array $item) => (float) $item['price'] > 0,
        ));
    }

    /**
     * รวมของใหม่เข้ารายการเดิมด้วย key — ใบแจกของผูกกับชื่อแบบหนึ่งชื่อหนึ่งแถว
     * ของชิ้นเดียวกันจึงต้องเป็นบรรทัดเดียว ไม่ใช่สองบรรทัดชื่อซ้ำ
     *
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function mergeInto(array $existing, array $items): array
    {
        foreach ($items as $item) {
            $index = $this->findLine($existing, $item);

            if ($index === null) {
                $existing[] = $item;

                continue;
            }

            $line = $existing[$index];
            $quantity = (int) ($line['quantity'] ?? 0) + (int) $item['quantity'];
            $total = round((float) ($line['total_price'] ?? 0) + (float) $item['total_price'], 2);

            $existing[$index] = array_merge($line, [
                'key' => $line['key'] ?? $item['key'],
                'quantity' => $quantity,
                'total_price' => $total,
                // ราคาเปลี่ยนระหว่างสองครั้งที่สั่ง — เฉลี่ยให้ unit × qty ยังเท่ากับยอดรวม
                'unit_price' => $quantity > 0 ? round($total / $quantity, 2) : (float) $item['unit_price'],
            ]);
        }

        return array_values($existing);
    }

    /**
     * @param  array<int, array<string, mixed>>  $existing
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function removeFrom(array $existing, array $items): array
    {
        foreach ($items as $item) {
            $index = $this->findLine($existing, $item);
            if ($index === null) {
                continue;
            }

            $line = $existing[$index];
            $quantity = (int) ($line['quantity'] ?? 0) - (int) ($item['quantity'] ?? 0);

            if ($quantity <= 0) {
                unset($existing[$index]);
                $existing = array_values($existing);

                continue;
            }

            $total = max(0, round((float) ($line['total_price'] ?? 0) - (float) ($item['total_price'] ?? 0), 2));
            $existing[$index] = array_merge($line, [
                'quantity' => $quantity,
                'total_price' => $total,
                'unit_price' => round($total / $quantity, 2),
            ]);
        }

        return array_values($existing);
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    private function findLine(array $lines, array $item): ?int
    {
        $key = (string) ($item['key'] ?? '');

        foreach ($lines as $index => $line) {
            $lineKey = (string) ($line['key'] ?? '');
            if ($key !== '' && $lineKey !== '' ? $lineKey === $key : ($line['name'] ?? '') === ($item['name'] ?? '')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * บอกแอดมินกับคนจัดของ — ของที่สั่งหลังจัดของไปแล้วต้องมีคนรู้
     */
    private function notifyTeam(BookingRentalOrder $order): void
    {
        $booking = $order->booking;
        $schedule = $booking?->schedule;
        $summary = collect($order->items ?? [])
            ->map(fn (array $item) => ($item['name'] ?? '').' ×'.(int) ($item['quantity'] ?? 0))
            ->implode(', ');
        $round = $schedule?->departure_date ? ' รอบ '.ThaiDate::short($schedule->departure_date) : '';
        $title = 'ลูกค้าเช่าอุปกรณ์เพิ่ม';
        $body = "{$booking?->booking_ref}{$round}: {$summary} (ชำระแล้ว)";
        $data = [
            'booking_ref' => $booking?->booking_ref,
            'schedule_id' => $schedule?->id,
            'route' => 'admin.bookings',
        ];

        // คนเดียวมีได้ทั้ง admin และ packer — รวม id ก่อน จะได้ไม่เด้งสองครั้ง
        $recipients = collect();
        foreach ([['admin', 'operator'], ['packer']] as $roles) {
            try {
                $recipients = $recipients->merge(User::role($roles)->pluck('id'));
            } catch (\Throwable $e) {
                // ยังไม่มี role packer ในระบบ — ข้ามไปเงียบ ๆ
                Log::info('RentalOrderService: skip notifying '.implode('/', $roles).' — '.$e->getMessage());
            }
        }

        $recipients->unique()->each(fn (int $userId) => SmartNotification::send(
            $userId, 'rental_order_paid', $title, $body, $data,
        ));
    }
}
