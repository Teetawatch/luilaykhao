<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "สิ่งที่ต้องพกวันเดินทาง" — ข้อความสั้น ๆ ที่แอดมินพิมพ์เองต่อทริป
 *
 * ข้อความไทม์ไลน์คืนก่อนเดินทาง (`pickup_eve`) เคยสั่งให้พกบัตรประชาชนทุกทริป
 * ทั้งที่ทริปเดินป่าในประเทศส่วนมากไม่ได้ใช้ และทริปต่างประเทศต้องใช้พาสปอร์ต
 * ไม่ใช่บัตร ปล่อยให้แอดมินเขียนเองจึงตรงกับหน้างานกว่าเดารายทริป
 *
 * เว้นว่าง = ไม่ต้องพกอะไรเป็นพิเศษ → ประโยคนั้นหายไปจากห้องแชททั้งบรรทัด
 * ทริปที่มีอยู่แล้วเติมค่าเดิมไว้ให้ (ในประเทศ=บัตรประชาชน, ต่างประเทศ=พาสปอร์ต)
 * เพื่อไม่ให้ทริปที่กำลังจะออกเดินทางเงียบหายไปเพราะ migration นี้
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('checkin_bring')->nullable()->after('document_requirements');
        });

        DB::table('trips')->where('destination_type', 'international')->update(['checkin_bring' => 'พาสปอร์ต']);
        DB::table('trips')->whereNull('checkin_bring')->update(['checkin_bring' => 'บัตรประชาชน']);
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn('checkin_bring');
        });
    }
};
