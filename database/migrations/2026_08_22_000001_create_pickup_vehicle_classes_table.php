<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ประเภทรถรับ-ส่งที่ใช้วิ่งจากจุดรับต่างภูมิภาคมายังจุดขึ้นรถจุดแรก
     *
     * ตั้งครั้งเดียวใช้ได้ทุกทริปทุกรอบ — ตอนลูกค้าเลือกจุดรับเรายังไม่รู้ว่า
     * จุดนั้นจะมีคนรวมกี่คน (ขึ้นกับ booking อื่นที่เลือกจุดเดียวกัน) ตารางนี้
     * จึงเป็น "ไกด์ประเภทรถตามจำนวนผู้โดยสาร" ไม่ใช่คำสัญญาว่ารถคันไหนจะมารับ
     */
    public function up(): void
    {
        Schema::create('pickup_vehicle_classes', function (Blueprint $table) {
            $table->id();
            $table->string('label');                       // รถเก๋ง / SUV / PPV / รถตู้
            $table->unsignedTinyInteger('min_pax');         // จำนวนผู้โดยสารต่ำสุดที่ใช้รถแบบนี้
            $table->unsignedTinyInteger('max_pax')->nullable(); // null = ขึ้นไปไม่จำกัด
            $table->string('image_url', 2048)->nullable();
            $table->string('note')->nullable();             // เช่น "นั่งสบาย 2 ท่าน พร้อมสัมภาระ"
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // ค่าตั้งต้นแบบไม่ทับช่วงกัน แอดมินแก้ตัวเลข/อัปโหลดรูปทับได้ทีหลัง
        $now = now();
        DB::table('pickup_vehicle_classes')->insert([
            ['label' => 'รถเก๋ง', 'min_pax' => 1, 'max_pax' => 2, 'note' => 'เดินทาง 1-2 ท่าน พร้อมสัมภาระ', 'is_active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'รถ SUV', 'min_pax' => 3, 'max_pax' => 4, 'note' => 'เดินทาง 3-4 ท่าน สัมภาระเยอะขึ้น', 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'รถ PPV', 'min_pax' => 5, 'max_pax' => 5, 'note' => 'เดินทาง 5 ท่าน ห้องโดยสารสูงโปร่ง', 'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['label' => 'รถตู้', 'min_pax' => 6, 'max_pax' => null, 'note' => 'เดินทาง 6 ท่านขึ้นไป', 'is_active' => true, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_vehicle_classes');
    }
};
