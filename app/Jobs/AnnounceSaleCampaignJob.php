<?php

namespace App\Jobs;

use App\Models\SaleCampaign;
use App\Services\BroadcastNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * ประกาศแคมเปญวันพิเศษทันทีที่ถึงเวลาเริ่ม
 *
 * แคมเปญตั้งล่วงหน้าได้และเงียบสนิทจนกว่าจะถึงเวลา (ราคายังปกติ ไม่มี push)
 * งานนี้วิ่งทุกนาทีเพื่อจับจังหวะที่มันเพิ่งมีผล แล้วยิงประกาศครั้งเดียว —
 * announced_at กันซ้ำในระดับแถว และคีย์กันซ้ำของ BroadcastDispatch กันอีกชั้น
 */
class AnnounceSaleCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(BroadcastNotificationService $broadcast): void
    {
        $campaigns = SaleCampaign::live()
            ->where('announce_on_start', true)
            ->whereNull('announced_at')
            ->get();

        foreach ($campaigns as $campaign) {
            $broadcast->broadcastSaleCampaign($campaign);
            $campaign->update(['announced_at' => now()]);

            Log::info('AnnounceSaleCampaignJob announced a campaign', [
                'campaign_id' => $campaign->id,
                'name' => $campaign->name,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AnnounceSaleCampaignJob failed permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
