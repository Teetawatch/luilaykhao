<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Receipt;
use App\Support\ThaiDate;
use Barryvdh\DomPDF\Facade\Pdf;

class ReceiptService
{
    public function __construct(private QrCodeService $qr) {}

    private const KIND_LABELS = [
        'full' => 'ชำระเต็มจำนวน',
        'deposit' => 'ชำระเงินมัดจำ',
        'installment' => 'ชำระงวดแรก',
        'split' => 'ชำระส่วนของผู้จอง (แบ่งจ่าย)',
        'balance' => 'ชำระยอดคงเหลือ',
    ];

    /**
     * ออกใบเสร็จให้การจอง — idempotent ต่อ (booking, kind): ยิงซ้ำได้ใบเดิม
     * ไม่ออกใหม่ (กัน webhook/อนุมัติซ้ำ). snapshot ถูกแช่ไว้ ณ วันออก
     */
    public function issueForBooking(Booking $booking, string $kind = 'full', ?float $amount = null): Receipt
    {
        $existing = Receipt::where('booking_id', $booking->id)->where('kind', $kind)->first();
        if ($existing) {
            return $existing;
        }

        $amount = $amount ?? (float) $booking->paid_amount ?? (float) $booking->total_amount;

        return Receipt::create([
            'booking_id' => $booking->id,
            'receipt_no' => Receipt::generateNumber(),
            'verify_token' => Receipt::generateToken(),
            'kind' => $kind,
            'amount' => $amount,
            'currency' => 'THB',
            'status' => 'paid',
            'issued_at' => now(),
            'snapshot' => $this->buildSnapshot($booking, $kind, $amount),
        ]);
    }

    public function verifyUrl(Receipt $receipt): string
    {
        return rtrim((string) config('app.url'), '/').'/receipt/'.$receipt->verify_token;
    }

    public function qrDataUri(Receipt $receipt): string
    {
        return $this->qr->svgDataUri($this->verifyUrl($receipt), 320);
    }

    /** สร้าง PDF ใบเสร็จ (Barryvdh\DomPDF\PDF) */
    public function pdf(Receipt $receipt)
    {
        return Pdf::loadView('receipts.pdf', [
            'receipt' => $receipt,
            'd' => $receipt->snapshot ?? [],
            'qr' => $this->qrDataUri($receipt),
            'verifyUrl' => $this->verifyUrl($receipt),
            'kindLabel' => self::KIND_LABELS[$receipt->kind] ?? $receipt->kind,
            'fontRegular' => storage_path('fonts/Sarabun-Regular.ttf'),
            'fontBold' => storage_path('fonts/Sarabun-Bold.ttf'),
            'fontSemibold' => storage_path('fonts/Sarabun-SemiBold.ttf'),
        ])->setPaper('a4');
    }

    public function pdfFilename(Receipt $receipt): string
    {
        return 'receipt-'.$receipt->receipt_no.'.pdf';
    }

    public function kindLabel(Receipt $receipt): string
    {
        return self::KIND_LABELS[$receipt->kind] ?? $receipt->kind;
    }

    /**
     * แช่ข้อมูลที่ต้องใช้แสดงผลไว้ในใบเสร็จ เพื่อให้เอกสารคงที่แม้การจองเปลี่ยนภายหลัง
     */
    private function buildSnapshot(Booking $booking, string $kind, float $amount): array
    {
        $booking->loadMissing(['user', 'passengers', 'schedule.trip']);

        $total = (float) $booking->total_amount;
        $addonsTotal = (float) $booking->addons_total;
        $rentalsTotal = (float) $booking->rentals_total;
        $flexi = (float) $booking->flexi_surcharge;
        $discount = (float) $booking->discount_amount;
        $paxCount = $booking->passengers->count() ?: 1;

        // ยอดค่าทริปก่อนรวมของเสริม/เช่า/ส่วนลด (แกะกลับจากยอดรวมสุทธิ)
        $ticketsGross = round($total - $addonsTotal - $rentalsTotal - $flexi + $discount, 2);

        $items = [];
        $items[] = [
            'label' => 'ค่าแพ็กเกจทริป',
            'detail' => $booking->schedule?->trip?->title,
            'qty' => $paxCount,
            'unit' => 'ท่าน',
            'amount' => $ticketsGross,
        ];
        foreach ((array) $booking->selected_addons as $a) {
            $items[] = [
                'label' => 'ตัวเลือกเสริม: '.($a['name'] ?? '-'),
                'detail' => null,
                'qty' => (int) ($a['quantity'] ?? 1),
                'unit' => 'ชิ้น',
                'amount' => (float) ($a['total_price'] ?? 0),
            ];
        }
        foreach ((array) $booking->selected_rentals as $r) {
            $items[] = [
                'label' => 'เช่าอุปกรณ์: '.($r['name'] ?? '-'),
                'detail' => null,
                'qty' => (int) ($r['quantity'] ?? 1),
                'unit' => 'ชิ้น',
                'amount' => (float) ($r['total_price'] ?? 0),
            ];
        }
        if ($flexi > 0) {
            $items[] = ['label' => 'ค่าส่วนต่างเพื่อการันตีออกเดินทาง', 'detail' => null, 'qty' => 1, 'unit' => '', 'amount' => $flexi];
        }

        $firstPassenger = $booking->passengers->first();

        return [
            'company' => config('company'),
            'customer' => [
                'name' => $booking->user?->name ?: ($firstPassenger?->name ?? '-'),
                'email' => $booking->user?->email,
                'phone' => $firstPassenger?->phone ?: $booking->user?->phone,
            ],
            'trip' => [
                'title' => $booking->schedule?->trip?->title,
                'location' => $booking->schedule?->trip?->location,
                'departure_date' => $booking->schedule?->departure_date
                    ? ThaiDate::full($booking->schedule->departure_date)
                    : null,
            ],
            'items' => $items,
            'summary' => [
                'subtotal' => round($ticketsGross + $addonsTotal + $rentalsTotal + $flexi, 2),
                'discount' => $discount,
                'total' => $total,
                'paid' => $amount,
                'balance' => round(max(0, $total - (float) $booking->paid_amount), 2),
                'balance_due_at' => $booking->balance_due_at ? ThaiDate::full($booking->balance_due_at) : null,
            ],
            'payment' => [
                'kind' => $kind,
                'method' => $booking->payment_method,
                'ref' => $booking->payment_ref,
                'paid_at' => $booking->paid_at ? ThaiDate::full($booking->paid_at).' '.$booking->paid_at->format('H:i') : null,
                'transfer_datetime' => $booking->transfer_datetime
                    ? ThaiDate::full($booking->transfer_datetime).' '.$booking->transfer_datetime->format('H:i')
                    : null,
            ],
            'booking_ref' => $booking->booking_ref,
        ];
    }
}
