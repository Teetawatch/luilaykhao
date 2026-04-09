<?php

namespace Database\Seeders;

use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'operator']);
        Role::firstOrCreate(['name' => 'customer']);

        // Admin user (Primary)
        $admin = User::firstOrCreate(
            ['email' => 'admin@gmail.com'],
            ['name' => 'Admin User', 'phone' => '0800000000', 'password' => Hash::make('12345678')],
        );
        $admin->assignRole('admin');

        // Admin user (Secondary/Backup)
        $admin2 = User::firstOrCreate(
            ['email' => 'admin@traildive.com'],
            ['name' => 'Admin', 'phone' => '0800000000', 'password' => Hash::make('password')],
        );
        $admin2->assignRole('admin');

        // Demo customer
        $customer = User::firstOrCreate(
            ['email' => 'demo@traildive.com'],
            ['name' => 'สมชาย ทดสอบ', 'phone' => '0812345678', 'password' => Hash::make('password')],
        );
        $customer->assignRole('customer');

        // Vehicles
        $van1 = Vehicle::updateOrCreate(['name' => 'รถตู้ VIP-01'], [
            'type' => 'van',
            'capacity' => 12,
            'seat_layout' => [
                'rows' => 4,
                // A, B, C = left group | '' = aisle | D, E = right group
                // Row 1: A1 B1 C1 (3 seats together, no aisle)
                // Row 2: A2 | aisle | D2 E2  (1 left, 2 right)
                // Row 3: A3 | aisle | D3 E3  (1 left, 2 right)
                // Row 4: A4 B4 C4 (3 seats bench, no aisle)
                'columns' => ['A', 'B', 'C', '', 'D', 'E'],
                'seats' => [
                    // Row 1 — 3 seats side by side (next to driver)
                    ['id' => 'A1', 'row' => 1, 'col' => 'A', 'label' => 'A1'],
                    ['id' => 'B1', 'row' => 1, 'col' => 'B', 'label' => 'B1'],
                    ['id' => 'C1', 'row' => 1, 'col' => 'C', 'label' => 'C1'],
                    // Row 2 — 1 left, 2 right (window)
                    ['id' => 'A2', 'row' => 2, 'col' => 'A', 'label' => 'A2'],
                    ['id' => 'D2', 'row' => 2, 'col' => 'D', 'label' => 'D2'],
                    ['id' => 'E2', 'row' => 2, 'col' => 'E', 'label' => 'E2'],
                    // Row 3 — 1 left, 2 right (window)
                    ['id' => 'A3', 'row' => 3, 'col' => 'A', 'label' => 'A3'],
                    ['id' => 'D3', 'row' => 3, 'col' => 'D', 'label' => 'D3'],
                    ['id' => 'E3', 'row' => 3, 'col' => 'E', 'label' => 'E3'],
                    // Row 4 — 3 seats back bench
                    ['id' => 'A4', 'row' => 4, 'col' => 'A', 'label' => 'A4'],
                    ['id' => 'B4', 'row' => 4, 'col' => 'B', 'label' => 'B4'],
                    ['id' => 'C4', 'row' => 4, 'col' => 'C', 'label' => 'C4'],
                ],
            ],
        ]);

        $boat1 = Vehicle::firstOrCreate(['name' => 'เรือสปีดโบ๊ท-01'], [
            'type' => 'boat',
            'capacity' => 20,
            'seat_layout' => null,
        ]);

        // Trips
        $trekkingTrip = Trip::firstOrCreate(['slug' => 'doi-inthanon-trekking'], [
            'title' => 'เดินป่าดอยอินทนนท์ 2 วัน 1 คืน',
            'type' => 'trekking',
            'location' => 'เชียงใหม่',
            'description' => 'สัมผัสธรรมชาติยอดดอยสูงสุดของประเทศไทย เดินป่าผ่านเส้นทาง กิ่วแม่ปาน ชมทะเลหมอก น้ำตกวชิรธาร พร้อมไกด์ท้องถิ่นผู้เชี่ยวชาญ',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 3500.00,
            'departure_point' => 'เชียงใหม่ ประตูท่าแพ',
            'status' => 'active',
            'cover_image' => '/images/hiking.png',
        ]);

        $divingTrip = Trip::firstOrCreate(['slug' => 'similan-diving-3d2n'], [
            'title' => 'ดำน้ำหมู่เกาะสิมิลัน 3 วัน 2 คืน',
            'type' => 'diving',
            'location' => 'พังงา',
            'description' => 'ดำน้ำสัมผัสโลกใต้ท้องทะเลหมู่เกาะสิมิลัน จุดดำน้ำระดับโลก พบปลาฉลาม กระเบนราหู และปะการังหลากสี',
            'difficulty' => 'medium',
            'duration_days' => 3,
            'max_participants' => 20,
            'price_per_person' => 8900.00,
            'departure_point' => 'ท่าเรือทับละมุ พังงา',
            'status' => 'active',
            'cover_image' => '/images/trips/similan.jpg',
        ]);

        $snorkelTrip = Trip::firstOrCreate(['slug' => 'koh-tao-snorkeling'], [
            'title' => 'ดำน้ำตื้นเกาะเต่า 1 วัน',
            'type' => 'snorkeling',
            'location' => 'สุราษฎร์ธานี',
            'description' => 'ดำน้ำตื้นชมปะการังเกาะเต่า เกาะนางยวน น้ำใสราวกระจก เหมาะสำหรับทุกคน',
            'difficulty' => 'easy',
            'duration_days' => 1,
            'max_participants' => 20,
            'price_per_person' => 2500.00,
            'departure_point' => 'ท่าเรือแม่น้ำ สุราษฎร์ธานี',
            'status' => 'active',
            'cover_image' => '/images/trips/koh-tao.jpg',
        ]);

        $climbTrip = Trip::firstOrCreate(['slug' => 'railay-rock-climbing'], [
            'title' => 'ปีนผาไร่เลย์ 1 วัน',
            'type' => 'climbing',
            'location' => 'กระบี่',
            'description' => 'ปีนผาหินปูนริมทะเลที่ไร่เลย์ กระบี่ มีเส้นทางหลายระดับตั้งแต่มือใหม่ถึงมือโปร พร้อมอุปกรณ์ครบชุด',
            'difficulty' => 'hard',
            'duration_days' => 1,
            'max_participants' => 8,
            'price_per_person' => 2800.00,
            'departure_point' => 'อ่าวนาง กระบี่',
            'status' => 'active',
            'cover_image' => '/images/trips/railay.jpg',
        ]);

        // Schedules
        $baseDate = now()->addDays(14);

        TripSchedule::updateOrCreate(
            ['trip_id' => $trekkingTrip->id, 'departure_date' => $baseDate->toDateString()],
            [
                'return_date' => $baseDate->copy()->addDay()->toDateString(),
                'total_seats' => 12,
                'transport_type' => 'van',
                'vehicle_id' => $van1->id,
                'status' => 'open',
            ],
        );

        TripSchedule::updateOrCreate(
            ['trip_id' => $trekkingTrip->id, 'departure_date' => $baseDate->copy()->addDays(7)->toDateString()],
            [
                'return_date' => $baseDate->copy()->addDays(8)->toDateString(),
                'total_seats' => 12,
                'transport_type' => 'van',
                'vehicle_id' => $van1->id,
                'status' => 'open',
            ],
        );

        TripSchedule::firstOrCreate(
            ['trip_id' => $divingTrip->id, 'departure_date' => $baseDate->toDateString()],
            [
                'return_date' => $baseDate->copy()->addDays(2)->toDateString(),
                'total_seats' => 20,
                'transport_type' => 'boat',
                'vehicle_id' => $boat1->id,
                'status' => 'open',
            ],
        );

        TripSchedule::firstOrCreate(
            ['trip_id' => $snorkelTrip->id, 'departure_date' => $baseDate->copy()->addDays(3)->toDateString()],
            [
                'return_date' => $baseDate->copy()->addDays(3)->toDateString(),
                'total_seats' => 20,
                'transport_type' => 'boat',
                'vehicle_id' => $boat1->id,
                'status' => 'open',
            ],
        );

        TripSchedule::updateOrCreate(
            ['trip_id' => $climbTrip->id, 'departure_date' => $baseDate->copy()->addDays(5)->toDateString()],
            [
                'return_date' => $baseDate->copy()->addDays(5)->toDateString(),
                'total_seats' => 12,
                'transport_type' => 'van',
                'vehicle_id' => $van1->id,
                'status' => 'open',
            ],
        );
    }
}
