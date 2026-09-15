<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Trip;
use App\Support\TripRentalItems;
use Illuminate\Console\Command;

/**
 * แปะ key ถาวรให้อุปกรณ์เช่าที่มีอยู่แล้ว ทั้งในแคตตาล็อกทริปและใน snapshot
 * บนใบจองเดิม
 *
 * ต้องรัน **ก่อน** แก้ชื่ออุปกรณ์ชิ้นไหนก็ตาม เพราะตัวที่ผูกใบจองเก่ากลับไปหา
 * แคตตาล็อกได้ตอนนี้มีแค่ชื่อ — วันที่ชื่อเปลี่ยนคือวันที่โอกาสนั้นหายไป
 * รันซ้ำได้ ไม่ทำอะไรกับแถวที่มี key แล้ว
 */
class BackfillRentalKeys extends Command
{
    protected $signature = 'rentals:backfill-keys
        {--dry-run : แสดงผลลัพธ์โดยไม่บันทึก}
        {--parts-from=* : คัดลอกของในชุดให้รายการที่ไม่มีในแคตตาล็อกแล้ว รูปแบบ "ชื่อบนใบจอง=ชื่อในแคตตาล็อกวันนี้" (ใส่ได้หลายคู่)}';

    protected $description = 'แปะ key ถาวรให้อุปกรณ์ให้เช่าในทริปและใบจองเดิม เพื่อให้เปลี่ยนชื่อรายการได้โดยใบจองไม่หลุด';

    /** ชื่อบนใบจอง => ชื่อในแคตตาล็อกที่จะคัดลอกของในชุดมาให้ */
    private array $partsFrom = [];

    private int $partsCopied = 0;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry-run] ' : '';

        if (! $this->readPartsFrom()) {
            return self::FAILURE;
        }

        $tripsTouched = $this->backfillTrips($dryRun);
        [$bookingsTouched, $linesTouched, $orphans] = $this->backfillBookings($dryRun);

        $this->newLine();
        $this->info("{$prefix}แคตตาล็อก: แก้ {$tripsTouched} ทริป");
        $this->info("{$prefix}ใบจอง: แก้ {$bookingsTouched} ใบ ({$linesTouched} รายการ)");

        if ($this->partsCopied > 0) {
            $this->info("{$prefix}คัดลอกของในชุดให้รายการที่ไม่มีในแคตตาล็อกแล้ว {$this->partsCopied} รายการ");
        }

        if ($orphans !== []) {
            $this->newLine();
            $this->warn('รายการที่หาในแคตตาล็อกไม่เจอ (ชื่อไม่ตรงกับทริปแล้ว) — ต้องไล่ดูเอง:');
            foreach ($orphans as $label => $count) {
                $this->line("  {$label} · {$count} ใบจอง");
            }
            $this->line('  ของชิ้นเดียวปล่อยไว้ได้ ใบเตรียมของนับถูกตามชื่อตัวเองอยู่แล้ว');
            $this->line('  ถ้าเป็นชุด ใช้ --parts-from="ชื่อข้างบน=ชื่อในแคตตาล็อกวันนี้" เพื่อคัดลอกของในชุดมาให้');
        }

        return self::SUCCESS;
    }

    /**
     * อ่านคู่ --parts-from ที่ผู้ใช้สั่งมา
     *
     * คัดลอกแค่ "ของในชุด" ไม่แตะชื่อ/ราคา/key — รายการที่เลิกขายไปแล้ว
     * (เช่นชุดเดียวกันแต่ "พร้อมแบกให้") จึงแตกเป็นชิ้นได้โดยไม่ต้องเอา
     * ของที่เลิกขายกลับเข้าแคตตาล็อกให้ลูกค้าเห็น
     */
    private function readPartsFrom(): bool
    {
        foreach ((array) $this->option('parts-from') as $pair) {
            $parts = explode('=', (string) $pair, 2);

            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                $this->error('รูปแบบ --parts-from ต้องเป็น "ชื่อบนใบจอง=ชื่อในแคตตาล็อกวันนี้"');

                return false;
            }

            $this->partsFrom[trim($parts[0])] = trim($parts[1]);
        }

        return true;
    }

    /** เขียน key ลงแคตตาล็อกของทุกทริปที่มีอุปกรณ์ให้เช่า */
    private function backfillTrips(bool $dryRun): int
    {
        $touched = 0;

        Trip::query()
            ->whereNotNull('rental_items')
            ->chunkById(100, function ($trips) use ($dryRun, &$touched) {
                foreach ($trips as $trip) {
                    $raw = $trip->rental_items;
                    if (! is_array($raw) || $raw === []) {
                        continue;
                    }

                    $normalized = TripRentalItems::normalize($raw);
                    if ($normalized === $raw) {
                        continue;
                    }

                    $touched++;
                    $this->line("  ทริป #{$trip->id} {$trip->title}: ".count($normalized).' รายการ');

                    if (! $dryRun) {
                        $trip->update(['rental_items' => $normalized]);
                    }
                }
            });

        return $touched;
    }

    /**
     * แปะ key (และของในชุด) ลง snapshot ของใบจอง โดยจับคู่จากชื่อที่ยังตรงกันวันนี้
     *
     * @return array{0: int, 1: int, 2: array<string, int>}
     */
    private function backfillBookings(bool $dryRun): array
    {
        $bookingsTouched = 0;
        $linesTouched = 0;
        $orphans = [];

        Booking::query()
            ->whereNotNull('selected_rentals')
            ->with('schedule.trip')
            ->chunkById(200, function ($bookings) use ($dryRun, &$bookingsTouched, &$linesTouched, &$orphans) {
                foreach ($bookings as $booking) {
                    $rentals = $booking->selected_rentals;
                    if (! is_array($rentals) || $rentals === []) {
                        continue;
                    }

                    $catalog = collect($booking->schedule?->trip?->rentalItems() ?? [])->keyBy('name');
                    $changed = false;

                    foreach ($rentals as $index => $rental) {
                        if (! is_array($rental) || trim((string) ($rental['key'] ?? '')) !== '') {
                            continue;
                        }

                        $name = trim((string) ($rental['name'] ?? ''));
                        $option = $catalog->get($name);

                        if (! $option) {
                            // ไม่มีในแคตตาล็อกแล้ว — ยังพอคัดลอกของในชุดมาให้ได้ ถ้าสั่งไว้
                            $source = $catalog->get($this->partsFrom[$name] ?? '');

                            if ($source && $source['parts'] !== [] && ($rental['parts'] ?? []) === []) {
                                $rentals[$index]['parts'] = $source['parts'];
                                $changed = true;
                                $this->partsCopied++;

                                continue;
                            }

                            if ($name !== '') {
                                $orphans[$name] = ($orphans[$name] ?? 0) + 1;
                            }

                            continue;
                        }

                        $rentals[$index]['key'] = $option['key'];
                        // แช่ของในชุดไว้ด้วย เผื่ออุปกรณ์ชิ้นนี้ถูกลบออกจากทริปวันหลัง
                        $rentals[$index]['parts'] = $option['parts'];
                        $changed = true;
                        $linesTouched++;
                    }

                    if (! $changed) {
                        continue;
                    }

                    $bookingsTouched++;
                    $this->line("  {$booking->booking_ref}");

                    if (! $dryRun) {
                        $booking->update(['selected_rentals' => $rentals]);
                    }
                }
            });

        return [$bookingsTouched, $linesTouched, $orphans];
    }
}
