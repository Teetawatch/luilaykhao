<?php

namespace Tests\Unit;

use App\Support\SeatLayoutFactory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeatLayoutFactoryTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $layout
     * @return array<int, array<string, list<string>>>
     */
    private function rowsOf(array $layout): array
    {
        $centre = array_flip($layout['last_row_center'] ?? []);
        $front = $layout['front_seat'] ?? null;
        $byId = collect($layout['seats'])->keyBy('id');
        $rows = [];

        for ($r = 1; $r <= $layout['rows']; $r++) {
            $left = $right = $middle = [];
            $inRight = false;
            foreach ($layout['columns'] as $col) {
                if ($col === '') {
                    $inRight = true;

                    continue;
                }
                $id = $col.$r;
                if ($id === $front || ! $byId->has($id)) {
                    continue;
                }
                if (isset($centre[$id])) {
                    $middle[] = $id;
                } elseif ($inRight) {
                    $right[] = $id;
                } else {
                    $left[] = $id;
                }
            }
            if ($left || $right || $middle) {
                $rows[] = ['left' => $left, 'centre' => $middle, 'right' => $right];
            }
        }

        return $rows;
    }

    #[Test]
    public function van_seats_one_beside_the_aisle_and_two_on_the_right(): void
    {
        $layout = SeatLayoutFactory::make('van', 10);

        $this->assertSame(SeatLayoutFactory::KIND_VAN, $layout['layout_kind']);
        $this->assertCount(10, $layout['seats']);
        $this->assertSame('A1', $layout['front_seat']);
        $this->assertContains('', $layout['columns'], 'ผังรถตู้ต้องมีทางเดิน');

        $rows = $this->rowsOf($layout);
        // สองแถวกลางเป็น 1 + ทางเดิน + 2
        $this->assertSame(['A2'], $rows[0]['left']);
        $this->assertSame(['D2', 'E2'], $rows[0]['right']);
        $this->assertSame(['A3'], $rows[1]['left']);
        $this->assertSame(['D3', 'E3'], $rows[1]['right']);
        // แถวหลังนั่งเรียงกลาง 3 ที่
        $this->assertSame(['A4', 'B4', 'C4'], $rows[2]['centre']);
        $this->assertSame(['A4', 'B4', 'C4'], $layout['last_row_center']);
    }

    #[Test]
    public function van_with_an_awkward_capacity_widens_the_back_row_instead_of_leaving_a_lone_seat(): void
    {
        $layout = SeatLayoutFactory::make('van', 11);
        $rows = $this->rowsOf($layout);

        $this->assertCount(11, $layout['seats']);
        $this->assertCount(4, $rows[count($rows) - 1]['centre']);
        foreach (array_slice($rows, 0, -1) as $row) {
            $this->assertCount(3, [...$row['left'], ...$row['right']]);
        }
    }

    #[Test]
    public function bus_seats_two_by_two_with_a_five_across_back_row(): void
    {
        $layout = SeatLayoutFactory::make('bus', 45);

        $this->assertSame(SeatLayoutFactory::KIND_BUS, $layout['layout_kind']);
        $this->assertCount(45, $layout['seats']);
        $this->assertNull($layout['front_seat'], 'รถบัสไม่มีที่นั่งคู่คนขับ');

        $rows = $this->rowsOf($layout);
        foreach (array_slice($rows, 0, -1) as $row) {
            $this->assertCount(2, $row['left']);
            $this->assertCount(2, $row['right']);
        }
        $this->assertCount(5, $rows[count($rows) - 1]['centre']);
    }

    #[Test]
    public function a_minibus_skips_the_five_across_back_row(): void
    {
        $layout = SeatLayoutFactory::make('bus', 14);

        $this->assertCount(14, $layout['seats']);
        $this->assertSame([], $layout['last_row_center']);
    }

    #[Test]
    public function boat_has_no_front_seat_and_no_back_row(): void
    {
        $layout = SeatLayoutFactory::make('boat', 18);

        $this->assertSame(SeatLayoutFactory::KIND_BOAT, $layout['layout_kind']);
        $this->assertCount(18, $layout['seats']);
        $this->assertNull($layout['front_seat']);
        $this->assertSame([], $layout['last_row_center']);
        $this->assertSame('หัวเรือ', $layout['front_label']);
    }

    #[Test]
    public function every_kind_and_capacity_produces_exactly_that_many_unique_seats(): void
    {
        foreach (['van', 'bus', 'boat', null, 'flight'] as $kind) {
            foreach (range(1, 55) as $capacity) {
                $layout = SeatLayoutFactory::make($kind, $capacity);
                $ids = array_column($layout['seats'], 'id');

                $this->assertCount($capacity, $ids, "$kind/$capacity ได้ที่นั่งไม่ครบ");
                $this->assertSame($ids, array_unique($ids), "$kind/$capacity มีรหัสที่นั่งซ้ำ");

                // ทุกที่นั่งต้องอยู่ในกริดที่ผังประกาศไว้ ไม่งั้นวาดไม่ออก
                foreach ($layout['seats'] as $seat) {
                    $this->assertLessThanOrEqual($layout['rows'], $seat['row']);
                    $this->assertContains($seat['col'], $layout['columns']);
                }
            }
        }
    }

    #[Test]
    public function an_unknown_vehicle_type_falls_back_to_the_van_layout(): void
    {
        $this->assertSame(SeatLayoutFactory::KIND_VAN, SeatLayoutFactory::normaliseKind('flight'));
        $this->assertSame(SeatLayoutFactory::KIND_VAN, SeatLayoutFactory::normaliseKind(null));
        $this->assertSame(SeatLayoutFactory::KIND_BUS, SeatLayoutFactory::normaliseKind('BUS'));
    }
}
