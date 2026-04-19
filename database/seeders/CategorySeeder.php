<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'ดำน้ำตื้น (Snorkeling)',
                'slug' => 'snorkeling',
                'icon' => 'scuba_diving',
                'order' => 1,
            ],
            [
                'name' => 'เดินป่า (Trekking)',
                'slug' => 'trekking',
                'icon' => 'hiking',
                'order' => 2,
            ],
            [
                'name' => 'ดำน้ำ (Diving)',
                'slug' => 'diving',
                'icon' => 'waves',
                'order' => 3,
            ],
            [
                'name' => 'บริการรถตู้ (Van Service)',
                'slug' => 'van-service',
                'icon' => 'airport_shuttle',
                'order' => 4,
            ],
        ];

        foreach ($categories as $cat) {
            Category::updateOrCreate(['slug' => $cat['slug']], $cat);
        }
    }
}
