<?php

namespace App\Console\Commands;

use App\Jobs\ClearEndedTripDriverPinsJob;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleDriverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ClearEndedTripDriverPins extends Command
{
    protected $signature = 'driver:clear-pins
                            {--whose= : บอกว่ารหัส PIN นี้ค้างอยู่กับใคร (ไม่ล้างอะไรทั้งสิ้น)}';

    protected $description = 'คืนรหัสส่ง GPS (PIN) ของรถที่รอบเดินทางจบไปแล้ว — งานเดียวกับที่รันอัตโนมัติทุกคืนตี 3 ครึ่ง ใช้เมื่อต้องการล้างทันที';

    public function handle(): int
    {
        if ($pin = $this->option('whose')) {
            return $this->whoHolds($pin);
        }

        $cleared = app(ClearEndedTripDriverPinsJob::class)->handle(app(VehicleDriverService::class));

        $this->info($cleared > 0
            ? "ล้างรหัสแล้ว {$cleared} รายการ"
            : 'ไม่มีรหัสที่ต้องล้าง');

        return self::SUCCESS;
    }

    /**
     * ตัวช่วยแก้ปัญหา "รหัสนี้ถูกใช้กับคนขับคนอื่นแล้ว" — บอกว่าใครถืออยู่
     */
    private function whoHolds(string $pin): int
    {
        $holders = User::whereNotNull('driver_pin_hash')
            ->get()
            ->filter(fn (User $u) => Hash::check($pin, $u->driver_pin_hash));

        if ($holders->isEmpty()) {
            $this->info("รหัส {$pin} ว่างอยู่ ตั้งได้เลย");

            return self::SUCCESS;
        }

        foreach ($holders as $holder) {
            $vehicle = Vehicle::where('driver_user_id', $holder->id)->first();

            $this->line(sprintf(
                '  #%d  %s  (%s)  →  %s',
                $holder->id,
                $holder->name,
                $holder->email,
                $vehicle ? "รถ: {$vehicle->name}" : 'ไม่ผูกกับรถคันไหน (ตั้งจากเมนูผู้ใช้งานระบบ หรือรถถูกลบไปแล้ว)',
            ));
        }

        return self::SUCCESS;
    }
}
