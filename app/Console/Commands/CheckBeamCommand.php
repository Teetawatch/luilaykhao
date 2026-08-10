<?php

namespace App\Console\Commands;

use App\Services\Beam\BeamClient;
use App\Services\Beam\BeamException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * ตรวจว่า credential ของ Beam ใช้ได้จริง ก่อนจะไปพึ่งมันตอนลูกค้ากำลังจ่ายเงิน
 *
 * สร้าง charge จริง 20 บาทบนปลายทางที่ตั้งไว้ แล้วอ่านกลับด้วย chargeId
 * ไม่ต้องมีการจองในระบบ ไม่แตะฐานข้อมูล และ (บน playground) ไม่มีเงินจริง
 *
 * เตือน: ถ้า BEAM_BASE_URL ชี้ production อยู่ คำสั่งนี้จะสร้าง charge จริง
 * บนบัญชีจริง — QR จะหมดอายุเองใน 5 นาทีถ้าไม่มีใครจ่าย
 */
class CheckBeamCommand extends Command
{
    protected $signature = 'beam:check';

    protected $description = 'Verify the Beam Checkout credentials can create and read back a charge.';

    public function handle(BeamClient $beam): int
    {
        $baseUrl = (string) config('payment.beam.base_url');
        $isProduction = ! Str::contains($baseUrl, 'playground');

        $this->line('');
        $this->line('  Provider      '.config('payment.provider'));
        $this->line('  Merchant ID   '.(config('payment.beam.merchant_id') ?: '—'));
        $this->line('  API key       '.(config('payment.beam.api_key') ? 'ตั้งไว้แล้ว' : '—'));
        $this->line('  Webhook key   '.(config('payment.beam.webhook_secret') ? 'ตั้งไว้แล้ว' : '— (webhook จะถูกปฏิเสธทุกใบ)'));
        $this->line('  ปลายทาง        '.$baseUrl.($isProduction ? '  ⚠️  production — charge นี้เป็นของจริง' : '  (playground)'));
        $this->line('');

        if (! $beam->enabled()) {
            $this->error('✗ ยังไม่ได้ตั้ง BEAM_MERCHANT_ID / BEAM_API_KEY ใน .env');

            return self::FAILURE;
        }

        if ($isProduction && ! $this->confirm('ปลายทางเป็น production — สร้าง charge จริง 20 บาทต่อไหม?', false)) {
            $this->line('  ยกเลิก');

            return self::SUCCESS;
        }

        $reference = 'beamcheck-'.Str::lower(Str::random(12));

        try {
            $created = $beam->createCharge([
                'amount' => 20 * BeamClient::SATANG_PER_BAHT,
                'currency' => 'THB',
                'referenceId' => $reference,
                'returnUrl' => (string) config('payment.beam.return_url'),
                'paymentMethod' => [
                    'paymentMethodType' => 'QR_PROMPT_PAY',
                    'qrPromptPay' => [
                        'expiryTime' => now()->addMinutes(5)->toIso8601ZuluString(),
                    ],
                ],
            ]);
        } catch (BeamException $e) {
            $this->error('✗ สร้าง charge ไม่สำเร็จ: '.$e->getMessage());
            if ($e->body !== null) {
                $this->line('  '.json_encode($e->body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return self::FAILURE;
        }

        $chargeId = $created['chargeId'] ?? null;
        $qrLength = strlen((string) data_get($created, 'encodedImage.imageBase64Encoded'));

        $this->info('✓ สร้าง charge สำเร็จ');
        $this->line('  chargeId        '.($chargeId ?: '—'));
        $this->line('  referenceId     '.$reference);
        $this->line('  actionRequired  '.($created['actionRequired'] ?? '—'));
        $this->line('  QR              '.($qrLength > 0 ? $qrLength.' ตัวอักษร base64 (PNG)' : '— ไม่มีรูป QR กลับมา'));
        $this->line('  QR หมดอายุ       '.(data_get($created, 'encodedImage.expiry') ?: '—'));

        if (! $chargeId) {
            $this->error('✗ Beam ไม่ได้คืน chargeId — ตามด้วยมือไม่ได้');

            return self::FAILURE;
        }

        try {
            $fetched = $beam->getCharge($chargeId);
        } catch (BeamException $e) {
            $this->error('✗ อ่าน charge กลับไม่ได้: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info('✓ อ่าน charge กลับได้');
        $this->line('  status          '.($fetched['status'] ?? '—'));
        $this->line('');
        $this->line('  ขั้นต่อไป: ตั้ง webhook URL ใน Beam Lighthouse ให้ชี้มาที่');
        $this->line('  '.rtrim((string) config('app.url'), '/').'/api/v1/payments/beam/webhook');
        $this->line('');

        return self::SUCCESS;
    }
}
