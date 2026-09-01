<?php

use App\Support\SiteSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ปิดตัวบล็อก "ทริปที่ค้างปิดงบ เปิดรอบใหม่ไม่ได้" บนเครื่องที่รันอยู่แล้ว
 *
 * ค่าตั้งต้นในโค้ดถูกเปลี่ยนเป็น false ไปแล้ว แต่ [SiteSettings::all()] เอาค่าที่
 * แอดมินกดบันทึกไว้ในตาราง settings มาทับค่าตั้งต้นทุกครั้ง — เครื่องที่เคยเปิดหน้า
 * /admin/settings แล้วกดบันทึกจะมีคีย์นี้เป็น true ค้างอยู่ และไม่รู้เลยว่าค่าตั้งต้น
 * เปลี่ยน ไมเกรชันนี้จึงต้องไปเขียนทับให้ด้วย ไม่ใช่แค่แก้โค้ด
 *
 * แตะเฉพาะคีย์เดียว ข้อบังคับบัญชีอื่น (บังคับสลิป/หมวด/ปิดงบต้องมีรายจ่าย) ไม่ขยับ
 */
return new class extends Migration
{
    private const FLAG = 'finance_block_new_rounds';

    public function up(): void
    {
        $this->write(false);
    }

    public function down(): void
    {
        $this->write(true);
    }

    private function write(bool $value): void
    {
        $row = DB::table('settings')->where('key', SiteSettings::KEY)->first();

        // ยังไม่มีแถว = เครื่องนี้ใช้ค่าตั้งต้นอยู่ ซึ่งตรงกับที่ต้องการแล้ว
        // การสร้างแถวขึ้นมาจะกลายเป็นการตรึงค่าไว้ ทำให้ค่าตั้งต้นในอนาคตไม่มีผล
        if (! $row) {
            return;
        }

        $stored = json_decode((string) $row->value, true);
        $stored = is_array($stored) ? $stored : [];

        if (($stored[self::FLAG] ?? null) === $value) {
            return;
        }

        $stored[self::FLAG] = $value;

        DB::table('settings')
            ->where('key', SiteSettings::KEY)
            ->update(['value' => json_encode($stored), 'updated_at' => now()]);

        Cache::forget('setting:'.SiteSettings::KEY);
    }
};
