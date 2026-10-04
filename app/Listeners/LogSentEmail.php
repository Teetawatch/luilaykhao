<?php

namespace App\Listeners;

use App\Models\EmailLog;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Email;

/**
 * เติมหลักฐานลงแถว email_logs ตอนที่ SMTP (Brevo) รับอีเมลไปแล้วจริง
 *
 * เก็บ HTML จากข้อความที่ส่งออกไป ไม่ได้ render เทมเพลตใหม่ตอนเปิดดู — เทมเพลต
 * ถูกแก้ทีหลังได้ แต่หลักฐานต้องเป็นฉบับที่ลูกค้าได้รับในวันนั้น
 */
class LogSentEmail
{
    public function handle(MessageSent $event): void
    {
        $logId = $event->data['emailLogId'] ?? null;

        if (! $logId) {
            return;
        }

        $message = $event->sent->getOriginalMessage();

        EmailLog::whereKey($logId)->update([
            'status' => EmailLog::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'error_message' => null,
            'message_id' => mb_substr($event->sent->getMessageId(), 0, 255),
            'subject' => $message instanceof Email ? $message->getSubject() : null,
            'html_body' => $message instanceof Email && is_string($message->getHtmlBody())
                ? $message->getHtmlBody()
                : null,
        ]);
    }
}
