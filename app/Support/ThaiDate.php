<?php

namespace App\Support;

use Carbon\CarbonInterface;

class ThaiDate
{
    /**
     * วันที่ภาษาไทยแบบเต็ม: "25 กรกฎาคม 2569"
     * — วัน + ชื่อเดือนไทยเต็ม + ปี พ.ศ. (ค.ศ. + 543)
     */
    public static function full(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->locale('th')->isoFormat('D MMMM').' '.($date->year + 543);
    }
}
