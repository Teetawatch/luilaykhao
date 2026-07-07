<?php

namespace App\Traits;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * แปลง transfer_date + transfer_time จากฟอร์มชำระเงินเป็น datetime string
 * รองรับปี พ.ศ. และตรวจความถูกต้องของวันเวลา — ใช้ร่วมกันทุก endpoint ที่รับสลิป
 */
trait ResolvesTransferDatetime
{
    private function resolveTransferDatetime(Request $request): ?string
    {
        $date = trim((string) $request->input('transfer_date', ''));
        $time = trim((string) $request->input('transfer_time', ''));

        if ($date === '') {
            return null;
        }

        if (! preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $dateParts)) {
            throw ValidationException::withMessages([
                'transfer_date' => 'รูปแบบวันที่โอนเงินไม่ถูกต้อง',
            ]);
        }

        $year = (int) $dateParts[1];
        if ($year > 2400) {
            $year -= 543;
        }

        $month = (int) $dateParts[2];
        $day = (int) $dateParts[3];

        if ($time === '') {
            $hour = 0;
            $minute = 0;
            $second = 0;
        } elseif (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $timeParts)) {
            $hour = (int) $timeParts[1];
            $minute = (int) $timeParts[2];
            $second = isset($timeParts[3]) ? (int) $timeParts[3] : 0;
        } else {
            throw ValidationException::withMessages([
                'transfer_time' => 'รูปแบบเวลาโอนเงินไม่ถูกต้อง',
            ]);
        }

        if (
            ! checkdate($month, $day, $year)
            || $hour > 23
            || $minute > 59
            || $second > 59
        ) {
            throw ValidationException::withMessages([
                'transfer_datetime' => 'วันที่หรือเวลาโอนเงินไม่ถูกต้อง',
            ]);
        }

        return CarbonImmutable::create($year, $month, $day, $hour, $minute, $second)
            ->format('Y-m-d H:i:s');
    }
}
