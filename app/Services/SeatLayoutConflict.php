<?php

namespace App\Services;

use App\Models\BookingSeat;
use Illuminate\Support\Collection;

/**
 * การแก้ผัง/เปลี่ยนรถที่จะทำให้ที่นั่งที่ขายไปแล้วหลุดออกจากผัง — ข้อความบอก
 * ใบจองกับรหัสที่นั่งที่ติดไว้ แอดมินจะได้ย้ายที่นั่งของใบเหล่านั้นก่อน
 * หรือวาดผังให้มีรหัสเดิมอยู่ครบ
 */
class SeatLayoutConflict extends \Exception
{
    /** @param Collection<int, BookingSeat> $seats */
    public function __construct(public readonly Collection $seats)
    {
        $byBooking = $seats
            ->groupBy(fn (BookingSeat $seat) => $seat->booking?->booking_ref ?? '#'.$seat->booking_id)
            ->map(fn (Collection $rows, string $ref) => $ref.' ('.$rows->pluck('seat_id')->join(', ').')')
            ->values();

        $list = $byBooking->take(5)->join(', ')
            .($byBooking->count() > 5 ? ' และอีก '.($byBooking->count() - 5).' ใบ' : '');

        parent::__construct(
            'บันทึกไม่ได้ เพราะที่นั่งที่ลูกค้าจองไว้แล้วจะหายไปจากผัง: '.$list
            .' — ย้ายที่นั่งของใบจองเหล่านี้ในหน้าแก้ไขการจองก่อน หรือวาดผังให้มีรหัสที่นั่งเหล่านี้อยู่'
        );
    }
}
