<?php

namespace App\Services;

use App\Models\SmsLog;
use App\Models\SosAlert;
use App\Support\SiteSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ส่งสัญญาณ SOS ทาง SMS
 *
 * ช่องทางแจ้งเตือนที่มีอยู่เดิม (FCM, Reverb, อีเมล) ต้องการ data บนเครื่อง
 * ผู้รับ ซึ่งเป็นสมมติฐานที่ใช้ไม่ได้กับคนที่อยู่บนดอยเดียวกันกับผู้ประสบเหตุ
 * SMS วิ่งบนช่องสัญญาณเสียงจึงยังไปถึงตรงที่ 4G ไม่ถึง และไปถึงเครื่องที่ปิด
 * แจ้งเตือนแอปไว้ด้วย
 *
 * แยกจาก [SmsService] เพราะ SMS ทั้งหมดในระบบเดิมผูกกับใบจอง (ยืนยันการจอง
 * เตือนชำระเงิน) ส่วน SOS ส่งหา "คนทำงาน" ไม่ใช่ "ลูกค้าของใบจองใด" —
 * `sms_logs.booking_id` จึงเป็น null และกลไกกันซ้ำต้องเป็นคนละตัว
 */
class SosSmsService
{
    public const SMS_TYPE = 'sos_alert';

    public function __construct(private ThaiBulkSmsClient $client) {}

    /**
     * เปิดใช้งานอยู่หรือไม่ — ปิดได้จาก /admin/settings โดยไม่ต้อง deploy
     *
     * SMS มีค่าใช้จ่ายต่อข้อความ ถ้าเครดิตหมดหรือผู้ให้บริการล่ม เราต้องปิด
     * ขาเดียวนี้ได้โดยไม่กระทบ push/อีเมลที่ยังทำงานปกติ
     */
    public function enabled(): bool
    {
        if (! SiteSettings::bool('sos_sms_enabled')) {
            return false;
        }

        $config = config('services.thaibulksms');

        return (bool) ($config['enabled'] ?? false)
            && filled($config['api_key'] ?? null)
            && filled($config['api_secret'] ?? null)
            && filled($config['sender'] ?? null);
    }

    /** ลองส่งได้กี่ครั้งต่อหนึ่งเบอร์ ก่อนยอมให้เคสนั้นค้างเป็น failed ไว้ในบันทึก */
    public const MAX_ATTEMPTS = 3;

    /**
     * ส่งให้ผู้รับหนึ่งเบอร์ — คืน null เมื่อข้าม (ส่งสำเร็จไปแล้ว หรือหมดสิทธิ์ลอง)
     *
     * กันซ้ำที่ระดับ (เคส, เบอร์) ไม่ใช่ระดับเคส เพราะงานถูกแตกเป็นรายคน สิ่งที่
     * ห้ามเกิดคือคนคนเดียวได้ข้อความเดิมสามรอบเพราะ worker ลองใหม่ ไม่ใช่การกัน
     * ไม่ให้เบอร์ที่สองได้รับ — และบันทึกที่ยัง failed อยู่ต้องส่งต่อได้ ไม่ใช่ถูก
     * ตัวมันเองบล็อกตอน retry
     */
    public function sendTo(SosAlert $alert, string $msisdn): ?SmsLog
    {
        $log = SmsLog::firstOrCreate(
            [
                'provider' => 'thaibulksms',
                'sms_type' => self::SMS_TYPE,
                'dedupe_key' => 'sos:'.$alert->id.':'.$msisdn,
            ],
            [
                'booking_id' => null,
                'recipient' => $msisdn,
                'message' => $this->compose($alert),
                'status' => 'pending',
                'scheduled_at' => now(),
            ],
        );

        if ($log->status === 'sent' || $log->attempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        return $this->dispatch($log, $alert->id);
    }

    /**
     * ข้อความ SOS — สั้นที่สุดเท่าที่ยังพาคนไปถึงที่เกิดเหตุได้
     *
     * SMS ภาษาไทยได้ 70 ตัวอักษรต่อหนึ่งข้อความ (ไม่ใช่ 160) ทุกคำจึงมีราคา
     * ลำดับจงใจ: ใครและอยู่ไหน มาก่อนรายละเอียด เพราะบางเครื่องแสดงเฉพาะ
     * บรรทัดแรกบนหน้าจอล็อก
     */
    public function compose(SosAlert $alert): string
    {
        $name = $alert->user?->name ?? 'ผู้เดินทาง';
        $trip = $alert->schedule?->trip?->title;

        $parts = ['[SOS] '.$name];

        if ($trip) {
            $parts[] = Str::limit($trip, 40, '');
        }

        if ($alert->latitude !== null && $alert->longitude !== null) {
            $parts[] = sprintf(
                'https://maps.google.com/?q=%s,%s',
                number_format((float) $alert->latitude, 5, '.', ''),
                number_format((float) $alert->longitude, 5, '.', ''),
            );
        } else {
            $parts[] = 'ไม่มีพิกัด';
        }

        if (filled($alert->message)) {
            $parts[] = Str::limit((string) $alert->message, 60, '');
        }

        if (filled($alert->contact_phone)) {
            $parts[] = 'โทร '.$alert->contact_phone;
        }

        // เคสที่ค้างอยู่ในเครื่องแล้วเพิ่งส่งออกมาได้ ต้องบอกว่ากดเมื่อไหร่ —
        // พิกัดที่แนบมาเก่าเท่ากับช่วงเวลานั้น ไม่ใช่ตำแหน่งปัจจุบัน
        if ($alert->delayMinutes() >= 5) {
            $parts[] = 'กดเมื่อ '.$alert->happenedAt()?->timezone('Asia/Bangkok')->format('H:i').' น.';
        }

        return implode(' ', $parts);
    }

    private function dispatch(SmsLog $log, int $alertId): SmsLog
    {
        try {
            $result = $this->client->send((string) $log->recipient, (string) $log->message);

            $log->update([
                'status' => $result['ok'] ? 'sent' : 'failed',
                'attempts' => $log->attempts + 1,
                'request_payload' => $result['payload'],
                'response_payload' => $result['body'],
                'provider_message_id' => data_get($result, 'body.id')
                    ?? data_get($result, 'body.message_id')
                    ?? data_get($result, 'body.data.0.id'),
                'sent_at' => $result['ok'] ? now() : null,
                'failed_at' => $result['ok'] ? null : now(),
                'error_message' => $result['ok'] ? null : 'ThaiBulkSMS returned HTTP '.$result['status'],
            ]);

            if (! $result['ok']) {
                // โยนต่อให้ job ลองใหม่ — SOS ที่ส่งไม่ออกไม่ควรจบเงียบ ๆ
                // เหมือน SMS เตือนชำระเงินที่ตกไปแล้วค่อยว่ากันพรุ่งนี้
                throw new \RuntimeException('SOS SMS rejected with HTTP '.$result['status']);
            }

            return $log;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'attempts' => $log->attempts + 1,
                'failed_at' => now(),
                'error_message' => Str::limit($e->getMessage(), 500),
            ]);

            Log::error('Failed to send SOS SMS', [
                'sms_log_id' => $log->id,
                'sos_alert_id' => $alertId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
