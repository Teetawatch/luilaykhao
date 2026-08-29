<?php

namespace App\Support;

use Carbon\CarbonInterface;

class ThaiDate
{
    private const MONTH_NAMES = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
    ];

    /**
     * วันที่ภาษาไทยแบบเต็ม: "25 กรกฎาคม 2569"
     * — วัน + ชื่อเดือนไทยเต็ม + ปี พ.ศ. (ค.ศ. + 543). ใช้กับจุดที่มีพื้นที่กว้าง.
     */
    public static function full(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->locale('th')->isoFormat('D MMMM').' '.($date->year + 543);
    }

    /**
     * วันที่ภาษาไทยแบบสั้น: "25 ก.ค. 2569"
     * — วัน + ชื่อเดือนย่อ + ปี พ.ศ. ใช้กับจุดที่พื้นที่จำกัด (SMS, ตาราง, ป้าย).
     */
    public static function short(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->locale('th')->isoFormat('D MMM').' '.($date->year + 543);
    }

    /**
     * เดือน+ปีภาษาไทย: "กรกฎาคม 2569" — ใช้เป็นหัวข้อกลุ่มเวลาที่ไม่ต้องระบุวัน.
     */
    public static function monthYear(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->locale('th')->isoFormat('MMMM').' '.($date->year + 543);
    }

    /**
     * ชื่อเดือนไทยล้วน: "กรกฎาคม" — ใช้ตอนจัดกลุ่มข้ามปี (ฤดูกาลของเดือนนั้น).
     */
    public static function monthName(int $month): string
    {
        return self::MONTH_NAMES[$month] ?? '-';
    }

    /**
     * ช่วงวันที่ภาษาไทย: "25 – 27 กรกฎาคม 2569" — ย่อส่วนที่ซ้ำกันออก
     * ("30 กรกฎาคม – 2 สิงหาคม 2569" เมื่อข้ามเดือน, เขียนเต็มทั้งสองฝั่งเมื่อข้ามปี)
     * ทริปส่วนใหญ่ค้างคืน การบอกแค่วันออกเดินทางทำให้ลูกค้าไม่รู้ว่ากลับวันไหน
     */
    public static function range(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if ($from === null) {
            return $to === null ? '-' : self::full($to);
        }

        if ($to === null || $to->isSameDay($from)) {
            return self::full($from);
        }

        if ($from->year !== $to->year) {
            return self::full($from).' – '.self::full($to);
        }

        $head = $from->month === $to->month
            ? $from->locale('th')->isoFormat('D')
            : $from->locale('th')->isoFormat('D MMMM');

        return $head.' – '.self::full($to);
    }

    /**
     * วันที่+เวลาแบบสั้น: "25 ก.ค. 2569 14:30" (ยังไม่รวม " น.")
     */
    public static function shortTime(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return self::short($date).' '.$date->format('H:i');
    }
}
