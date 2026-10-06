<?php

namespace App\Mail;

use App\Models\GiftVoucher;
use App\Support\ThaiDate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ส่งให้ผู้ซื้อเมื่อบัตรของขวัญชำระเงินเรียบร้อย — รหัสและลิงก์สำหรับส่งต่อ
 */
class GiftVoucherIssuedMail extends QueuedMail
{
    use Queueable, SerializesModels;

    public function __construct(
        public GiftVoucher $voucher,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'บัตรของขวัญ ฿'.number_format((float) $this->voucher->amount).' พร้อมใช้แล้วครับ 🎁 - Luilaykhao',
        );
    }

    public function content(): Content
    {
        $forSelf = $this->voucher->owner_user_id !== null
            && $this->voucher->owner_user_id === $this->voucher->purchaser_user_id;

        return new Content(
            view: 'emails.gift-voucher-issued',
            with: [
                'displayCode' => $this->voucher->displayCode(),
                'shareUrl' => $this->voucher->shareUrl(),
                'forSelf' => $forSelf,
                'expiresLabel' => $this->voucher->expires_at
                    ? ThaiDate::full($this->voucher->expires_at->copy()->setTimezone('Asia/Bangkok'))
                    : null,
            ],
        );
    }
}
