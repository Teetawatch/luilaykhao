<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * "ตอนนี้รับเงินด้วยวิธีไหน" — คำตอบเดียวที่ทุกฝั่งอ่าน
 *
 * เว็บ แอป และหน้า Blade ต้องตัดสินใจเหมือนกันว่าจะโชว์ QR ของ Beam (แล้วรอ webhook)
 * หรือโชว์ QR ที่เราสร้างเอง + ช่องอัปสลิป ถ้าต่างคนต่างเช็ค env กันเอง วันที่สลับ
 * provider จะมีบางหน้าที่ยังค้างอยู่วิธีเดิมโดยไม่มีใครรู้
 *
 * ตั้ง PAYMENT_PROVIDER=beam แต่ลืมใส่ credential = ยังเป็น manual อยู่ดี ไม่ใช่พัง
 */
class PaymentGateway
{
    public static function isBeam(): bool
    {
        return config('payment.provider') === 'beam'
            && filled(config('payment.beam.merchant_id'))
            && filled(config('payment.beam.api_key'));
    }

    public static function provider(): string
    {
        return self::isBeam() ? 'beam' : 'manual';
    }

    /**
     * ก้อนที่ส่งให้ client — ห้ามมี api key หรืออะไรที่เป็นความลับหลุดมาที่นี่
     *
     * installment_min_amount = ยอดต่ำสุดที่หน้าชำระเงินของ Beam จะมีผ่อนบัตรให้เลือก
     * (null = ไม่เปิดผ่อนบัตร) ให้ client ไม่โชว์ "ผ่อนบัตร" กับยอดที่ผ่อนไม่ได้
     *
     * @return array{provider: string, methods: list<string>, installment_min_amount: float|null}
     */
    public static function publicConfig(): array
    {
        return [
            'provider' => self::provider(),
            'methods' => self::isBeam()
                ? array_values((array) config('payment.beam.methods'))
                : [],
            'installment_min_amount' => self::isBeam() ? self::lowestInstallmentMinimum() : null,
        ];
    }

    /**
     * ยอดขั้นต่ำของการผ่อน N เดือน — ค่าที่ตั้งไว้ หรือค่าที่ Beam เคยบอกว่าสูงกว่า
     * (ดู BeamPaymentService::learnInstallmentMinimum) แล้วแต่อันไหนสูงกว่า
     */
    public static function installmentMinimum(int $months): float
    {
        return max(
            (float) config('payment.beam.checkout.installment_min_amount'),
            (float) Cache::get(self::installmentMinimumKey($months), 0),
        );
    }

    public static function installmentMinimumKey(int $months): string
    {
        return 'beam:installment-min:'.$months.'m';
    }

    private static function lowestInstallmentMinimum(): ?float
    {
        if (! config('payment.beam.checkout.groups.cardInstallments')) {
            return null;
        }

        $months = array_map('intval', (array) config('payment.beam.checkout.installment_months'));

        return $months === []
            ? null
            : min(array_map(fn (int $m) => self::installmentMinimum($m), $months));
    }
}
