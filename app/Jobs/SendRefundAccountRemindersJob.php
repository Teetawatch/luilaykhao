<?php

namespace App\Jobs;

use App\Services\RefundService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * เตือนลูกค้าที่มีเงินคืนรออยู่แต่ยังไม่กรอกบัญชี — วันที่ 2 และ 5 หลังเปิดเรื่อง
 * ทุกวัน 10:00 (Asia/Bangkok) ดู RefundService::remindMissingAccounts
 */
class SendRefundAccountRemindersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(RefundService $refunds): void
    {
        $reminded = $refunds->remindMissingAccounts();

        if ($reminded > 0) {
            Log::info('SendRefundAccountRemindersJob completed', ['reminded' => $reminded]);
        }
    }
}
