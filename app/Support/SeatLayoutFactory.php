<?php

namespace App\Support;

/**
 * ผังที่นั่งตั้งต้นตาม "ชนิดรถ" — ใช้เมื่อคันนั้นยังไม่มีผังที่แอดมินวาดเอง
 *
 * ของเดิมปั้นตารางสี่คอลัมน์ติดกันหมดให้ทุกคัน ซึ่งไม่ตรงกับรถจริงสักแบบ:
 * รถตู้เป็น 1 + ทางเดิน + 2 และมีแถวหลังนั่งเรียงกลาง ส่วนรถบัสเป็น 2 + ทางเดิน
 * + 2 และแถวหลังสุดนั่งยาว 5 ที่ ผังที่ไม่มีทางเดินทำให้ลูกค้าอ่านไม่ออกว่า
 * ที่นั่งที่เลือกอยู่ตรงไหนของรถ — ซึ่งเป็นเหตุผลเดียวที่ต้องมีผังที่นั่ง
 *
 * ผังที่คืนออกไปเป็น "ผังดิบ" (ไม่มีสถานะที่นั่ง) รูปแบบเดียวกับที่แอดมินวาดเอง
 * ใน SeatMapEditor.vue ฝั่งที่เรียกจึงเอาไปทับสถานะจริงต่อได้เลย
 */
class SeatLayoutFactory
{
    public const KIND_VAN = 'van';

    public const KIND_BUS = 'bus';

    public const KIND_BOAT = 'boat';

    public const KINDS = [self::KIND_VAN, self::KIND_BUS, self::KIND_BOAT];

    /**
     * แปลงชนิดพาหนะ (vehicles.type / schedule_vehicle_options.transport_type /
     * trip_schedules.transport_type) เป็นชนิดผังที่รู้จัก — ไม่รู้จักก็ถือเป็นรถตู้
     * ซึ่งเป็นพาหนะหลักของเรา
     */
    public static function normaliseKind(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        return in_array($type, self::KINDS, true) ? $type : self::KIND_VAN;
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(?string $type, int $capacity): array
    {
        $kind = self::normaliseKind($type);
        $capacity = max(1, min(60, $capacity));

        return match ($kind) {
            self::KIND_BUS => self::bus($capacity),
            self::KIND_BOAT => self::boat($capacity),
            default => self::van($capacity),
        };
    }

    /**
     * รถตู้ — ที่นั่งคู่คนขับ 1 ที่ (พวงมาลัยขวาแบบไทย คนขับอยู่ขวา ที่นั่งคู่คนขับ
     * จึงอยู่ซ้าย) แล้วตามด้วยแถว 1 + ทางเดิน + 2 และปิดท้ายด้วยแถวหลังนั่งเรียงกัน
     * 3-4 ที่ ตรงกับรถตู้ VIP ที่ใช้กันจริง ประตูเลื่อนอยู่ฝั่งซ้ายข้างแถวแรก
     */
    private static function van(int $capacity): array
    {
        $columns = ['A', 'B', 'C', '', 'D', 'E'];

        // รถเล็กมาก (จ้างรถเก๋ง/กระบะ) — วาดเป็นแถวเรียงกลางแถวเดียวพอ ไม่ต้องมีทางเดิน
        if ($capacity <= 4) {
            return self::centreOnly($capacity, 'van');
        }

        $frontSeat = 'A1';
        $body = $capacity - 1;

        // แถวหลังรับ 3 ที่ตามปกติ แต่ถ้าเหลือเศษ 1 ที่ให้แถวหลังรับ 4 แทน
        // จะได้ไม่มีแถวกลางที่มีที่นั่งเดียวลอยอยู่
        $back = ((($body - 3) % 3) === 1) ? 4 : 3;
        $back = min($back, $body);
        $middle = $body - $back;

        $seats = [[
            'id' => $frontSeat,
            'row' => 1,
            'column' => 1,
            'col' => 'A',
            'label' => $frontSeat,
        ]];

        $row = 2;
        $placed = 0;
        while ($placed < $middle) {
            $left = ['A'];
            $right = ['D', 'E'];
            $remaining = $middle - $placed;
            $take = array_slice([...$left, ...$right], 0, min(3, $remaining));

            foreach ($take as $col) {
                $seats[] = self::seat($col, $row, $columns);
                $placed++;
            }
            $row++;
        }

        $backRow = $row;
        $backCols = array_slice(['A', 'B', 'C', 'D'], 0, $back);
        $lastRowCentre = [];
        foreach ($backCols as $col) {
            $seats[] = self::seat($col, $backRow, $columns);
            $lastRowCentre[] = $col.$backRow;
        }

        return [
            'layout_kind' => self::KIND_VAN,
            'rows' => $backRow,
            'columns' => $columns,
            'seats' => $seats,
            'front_seat' => $frontSeat,
            'last_row_center' => $lastRowCentre,
            'front_label' => 'หน้ารถ',
            'rear_label' => 'ท้ายรถ (สำหรับเก็บสัมภาระ)',
            'driver_icon' => 'directions_car',
            'show_driver' => true,
            // ประตูเลื่อนฝั่งซ้าย อยู่ข้างแถวผู้โดยสารแถวแรก
            'door_rows' => [2],
        ];
    }

    /**
     * รถบัส — 2 + ทางเดิน + 2 ทุกแถว และแถวหลังสุดนั่งยาว 5 ที่ ไม่มีที่นั่ง
     * คู่คนขับ (ที่ตรงนั้นเป็นบันไดขึ้นลง) ประตูอยู่ฝั่งซ้ายทั้งหน้าและกลางคัน
     */
    private static function bus(int $capacity): array
    {
        $columns = ['A', 'B', '', 'C', 'D', 'E'];

        if ($capacity <= 5) {
            return self::centreOnly($capacity, 'bus');
        }

        // แถวหลังยาว 5 ที่มีเฉพาะบัสที่ใหญ่พอ คันเล็ก (มินิบัส) ไม่มี
        $back = $capacity >= 16 ? 5 : 0;
        $middle = $capacity - $back;

        $seats = [];
        $row = 1;
        $placed = 0;
        while ($placed < $middle) {
            $remaining = $middle - $placed;
            $take = array_slice(['A', 'B', 'C', 'D'], 0, min(4, $remaining));

            foreach ($take as $col) {
                $seats[] = self::seat($col, $row, $columns);
                $placed++;
            }
            $row++;
        }

        $lastRowCentre = [];
        $lastRow = $row - 1;
        if ($back > 0) {
            $lastRow = $row;
            foreach (['A', 'B', 'C', 'D', 'E'] as $col) {
                $seats[] = self::seat($col, $lastRow, $columns);
                $lastRowCentre[] = $col.$lastRow;
            }
        }

        // ประตูหน้าเสมอ คันยาวมีประตูกลางเพิ่ม
        $doorRows = [1];
        if ($lastRow >= 8) {
            $doorRows[] = (int) ceil($lastRow / 2);
        }

        return [
            'layout_kind' => self::KIND_BUS,
            'rows' => $lastRow,
            'columns' => $columns,
            'seats' => $seats,
            'front_seat' => null,
            'last_row_center' => $lastRowCentre,
            'front_label' => 'หน้ารถ',
            'rear_label' => 'ท้ายรถ (ห้องน้ำ / สัมภาระ)',
            'driver_icon' => 'directions_bus',
            'show_driver' => true,
            'door_rows' => $doorRows,
        ];
    }

    /**
     * เรือ — นั่งเรียงสองฝั่ง มีทางเดินกลาง ไม่มีที่นั่งคู่คนขับและไม่มีแถวหลังพิเศษ
     */
    private static function boat(int $capacity): array
    {
        $columns = ['A', 'B', '', 'C', 'D'];

        $seats = [];
        $row = 1;
        $placed = 0;
        while ($placed < $capacity) {
            $remaining = $capacity - $placed;
            $take = array_slice(['A', 'B', 'C', 'D'], 0, min(4, $remaining));

            foreach ($take as $col) {
                $seats[] = self::seat($col, $row, $columns);
                $placed++;
            }
            $row++;
        }

        return [
            'layout_kind' => self::KIND_BOAT,
            'rows' => max(1, $row - 1),
            'columns' => $columns,
            'seats' => $seats,
            'front_seat' => null,
            'last_row_center' => [],
            'front_label' => 'หัวเรือ',
            'rear_label' => 'ท้ายเรือ',
            'driver_icon' => 'sailing',
            'show_driver' => true,
            'door_rows' => [],
        ];
    }

    /**
     * พาหนะเล็กเกินกว่าจะมีทางเดิน — ที่นั่งทั้งหมดเรียงเป็นแถวกลางแถวเดียว
     */
    private static function centreOnly(int $capacity, string $kind): array
    {
        $columns = ['A', 'B', 'C', 'D', 'E'];
        $seats = [];
        $lastRowCentre = [];

        foreach (array_slice($columns, 0, $capacity) as $col) {
            $seats[] = self::seat($col, 1, $columns);
            $lastRowCentre[] = $col.'1';
        }

        return [
            'layout_kind' => $kind,
            'rows' => 1,
            'columns' => $columns,
            'seats' => $seats,
            'front_seat' => null,
            'last_row_center' => $lastRowCentre,
            'front_label' => 'หน้ารถ',
            'rear_label' => 'ท้ายรถ',
            'driver_icon' => $kind === self::KIND_BUS ? 'directions_bus' : 'directions_car',
            'show_driver' => true,
            'door_rows' => [],
        ];
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    private static function seat(string $col, int $row, array $columns): array
    {
        return [
            'id' => $col.$row,
            'row' => $row,
            'column' => array_search($col, $columns, true) + 1,
            'col' => $col,
            'label' => $col.$row,
        ];
    }
}
