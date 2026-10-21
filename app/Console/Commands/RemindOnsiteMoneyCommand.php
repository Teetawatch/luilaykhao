<?php

namespace App\Console\Commands;

use App\Models\ChatCollection;
use App\Models\OnsiteMoneyReport;
use App\Models\TripSchedule;
use App\Services\FcmService;
use App\Services\OnsiteMoneyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * เช้าหลังจบทริป (และทุกเช้าจนกว่าจะส่ง ไม่เกิน REMIND_DAYS วัน) เตือนสตาฟที่ยังถือเงิน
 * หน้างานของรอบนั้นอยู่ให้กด "ส่งเงินแล้ว" — กดแล้ว/ยอดตรงแล้วไม่เตือนอีก
 */
class RemindOnsiteMoneyCommand extends Command
{
    protected $signature = 'onsite-money:remind';

    protected $description = 'เตือนสตาฟที่ยังไม่ส่งเงินหน้างานของรอบที่จบแล้ว';

    public const REMIND_DAYS = 14;

    public function handle(OnsiteMoneyService $money, FcmService $fcm): int
    {
        $today = now('Asia/Bangkok')->toDateString();
        $from = now('Asia/Bangkok')->subDays(self::REMIND_DAYS)->toDateString();

        $ids = ChatCollection::query()->select('schedule_id')
            ->union(OnsiteMoneyReport::query()->select('schedule_id'))
            ->pluck('schedule_id')
            ->unique();

        $sent = 0;
        TripSchedule::with('trip:id,title')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->whereNotNull('return_date')->whereDate('return_date', '<', $today)->whereDate('return_date', '>=', $from))
                ->orWhere(fn ($w) => $w->whereNull('return_date')->whereDate('departure_date', '<', $today)->whereDate('departure_date', '>=', $from)))
            ->get()
            ->each(function (TripSchedule $schedule) use ($money, $fcm, &$sent) {
                foreach ($money->unsentHolders($schedule) as $holder) {
                    if (! $holder['user_id']) {
                        continue;
                    }
                    try {
                        $fcm->sendToUser(
                            (int) $holder['user_id'],
                            '💰 ยังไม่ได้ส่งเงินหน้างาน ฿'.number_format($holder['total'], fmod($holder['total'], 1.0) === 0.0 ? 0 : 2),
                            ($schedule->trip?->title ?? 'ทริป').' — เปิดสรุปยอดแล้วกด "ส่งเงินแล้ว" เมื่อโอน/ส่งเงินให้บริษัท',
                            [
                                'type' => 'onsite_money',
                                'schedule_id' => (string) $schedule->id,
                            ],
                        );
                        $sent++;
                    } catch (\Throwable $e) {
                        Log::warning('onsite-money:remind ส่งไม่สำเร็จ', ['user_id' => $holder['user_id'], 'error' => $e->getMessage()]);
                    }
                }
            });

        $this->info("เตือน {$sent} คน");

        return self::SUCCESS;
    }
}
