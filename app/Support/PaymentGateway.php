<?php

namespace App\Support;

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
     * @return array{provider: string, methods: list<string>}
     */
    public static function publicConfig(): array
    {
        return [
            'provider' => self::provider(),
            'methods' => self::isBeam()
                ? array_values((array) config('payment.beam.methods'))
                : [],
        ];
    }
}
