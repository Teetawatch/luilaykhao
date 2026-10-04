<?php

namespace App\Mail;

use App\Models\EmailLog;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Mailable;

/**
 * Base for every mail this app sends.
 *
 * Mail goes out on the queue. A plain Mailable sent with Mail::to()->send() opens
 * an SMTP connection to Brevo inline, so creating a booking blocked the customer's
 * response on two round-trips to an external host — for mail whose delivery is
 * already best-effort (callers log failures and carry on).
 *
 * ShouldQueueAfterCommit rather than ShouldQueue: mail queued inside a DB
 * transaction must not be picked up by a worker before the row it renders exists,
 * which would fail the job with a ModelNotFoundException. Callers today all send
 * after their transaction closes; this keeps that from being load-bearing.
 */
abstract class QueuedMail extends Mailable implements ShouldQueueAfterCommit
{
    /** SMTP blips are transient — retry before writing the mail off. */
    public $tries = 3;

    /**
     * แถว email_logs ที่ฉบับนี้เป็นหลักฐานให้ — public เพราะ Laravel ส่ง public
     * property ไปกับ MessageSent ทำให้ LogSentEmail ผูกกลับมาที่แถวได้โดยไม่ต้อง
     * แปะ header อะไรลงในอีเมลที่ลูกค้าเห็น
     */
    public ?int $emailLogId = null;

    /** @return int[] seconds to wait between attempts */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function logAs(EmailLog $log): static
    {
        $this->emailLogId = $log->id;

        return $this;
    }

    /** คิวลองครบทุกรอบแล้วยังส่งไม่ออก — บันทึกไว้ให้หน้าหลักฐานเห็นว่าไม่ถึง */
    public function failed(\Throwable $e): void
    {
        if ($this->emailLogId === null) {
            return;
        }

        EmailLog::whereKey($this->emailLogId)
            ->where('status', '!=', EmailLog::STATUS_SENT)
            ->update([
                'status' => EmailLog::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);
    }
}
