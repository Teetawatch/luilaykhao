<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // รายการประจำบางอย่างสตาฟเป็นคนควักจ่ายหน้างาน (ค่าผ่านด่านลาว ค่าเข้าอุทยาน)
        // และคิดตามหัว — ให้แอดมินตั้งว่าคูณกับใคร และเลือกได้ว่ารายการไหนสตาฟเห็น
        // (ค่าจ้างไกด์/ค่าเช่ารถเป็นเรื่องของบริษัท ไม่ควรโผล่ในแอปสตาฟ)
        Schema::table('expense_templates', function (Blueprint $table) {
            $table->string('amount_basis', 16)->default('flat')->after('default_amount');
            $table->boolean('staff_visible')->default(false)->after('amount_basis');
            $table->string('staff_note', 500)->nullable()->after('staff_visible');
        });
    }

    public function down(): void
    {
        Schema::table('expense_templates', function (Blueprint $table) {
            $table->dropColumn(['amount_basis', 'staff_visible', 'staff_note']);
        });
    }
};
