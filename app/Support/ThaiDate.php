<?php

namespace App\Support;

use Carbon\CarbonInterface;

class ThaiDate
{
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
