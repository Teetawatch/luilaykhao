<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // หารบิล: สตาฟจ่ายร้านรวมทีเดียว ใส่ราคาต่อเมนู แต่ละคนโอนคืนตามยอดของตัวเอง
        Schema::table('chat_food_rounds', function (Blueprint $table) {
            // {ชื่อเมนูแบบตัดช่องว่าง+ตัวพิมพ์เล็ก: ราคาต่อจาน}
            $table->json('prices')->nullable()->after('note');
            $table->string('promptpay_id', 20)->nullable()->after('prices');
            $table->string('payee_name', 80)->nullable()->after('promptpay_id');
            $table->timestamp('billed_at')->nullable()->after('announced_at');
        });

        Schema::table('chat_food_orders', function (Blueprint $table) {
            // ลูกค้ากด "โอนแล้ว" → รอสตาฟเช็กยอดเข้า → สตาฟยืนยัน
            $table->timestamp('paid_claimed_at')->nullable()->after('skipped');
            $table->timestamp('paid_at')->nullable()->after('paid_claimed_at');
            // ยอดที่สตาฟยืนยันว่าได้รับ — ราคาเปลี่ยนทีหลังจะรู้ว่ายังขาดอีกเท่าไร
            $table->decimal('paid_amount', 10, 2)->nullable()->after('paid_at');
            $table->foreignId('paid_marked_by_id')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_food_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_marked_by_id');
            $table->dropColumn(['paid_claimed_at', 'paid_at', 'paid_amount']);
        });

        Schema::table('chat_food_rounds', function (Blueprint $table) {
            $table->dropColumn(['prices', 'promptpay_id', 'payee_name', 'billed_at']);
        });
    }
};
