<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingPassenger;
use App\Models\Receipt;
use App\Support\TermsAcceptanceSummary;
use App\Support\ThaiDate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

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
        $existing = Receipt::where('booking_id', $booking->id)
            ->where('kind', $kind)
            ->where('holder', Receipt::holderFor(null))
            ->first();
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

    /**
     * แตกใบรวมเป็นใบแยกรายบุคคล ให้ผู้เดินทางทุกคนในการจองมีใบในชื่อตัวเอง
     *
     * ยอดทุกบรรทัดหารเท่ากันตามจำนวนผู้เดินทาง (เหมือนแบ่งจ่ายแบบเท่า ๆ กัน)
     * เศษสตางค์ไปตกที่คนแรก ๆ — ใบแยกทุกใบรวมกันจึงเท่ากับใบแม่ทุกสตางค์
     * แต่ละใบอ้างเลขใบแม่ไว้ ใบชุดนี้จึงเป็น "ส่วนหนึ่งของใบรวม" ไม่ใช่รายรับเพิ่ม
     *
     * ไม่ออกให้: ใบที่เป็นใบแยกอยู่แล้ว, การจองคนเดียว, และการจองแบ่งจ่าย —
     * แบ่งจ่ายแต่ละคนจ่ายไม่เท่ากันและใบ "split" คือส่วนของผู้จองคนเดียว
     * หารเท่าจะได้ตัวเลขที่ไม่ตรงกับเงินที่แต่ละคนจ่ายจริง
     *
     * idempotent: เรียกซ้ำได้ใบชุดเดิม
     *
     * @return EloquentCollection<int, Receipt>
     */
    public function issuePersonalReceipts(Receipt $parent): EloquentCollection
    {
        if ($parent->isPersonal() || $parent->kind === 'split') {
            return new EloquentCollection;
        }

        $existing = $parent->personalReceipts()->get();
        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $booking = $parent->booking;
        if ($booking === null || $booking->splitShares()->exists()) {
            return new EloquentCollection;
        }

        $passengers = $booking->passengers()->orderBy('id')->get();
        $count = $passengers->count();
        if ($count < 2) {
            return new EloquentCollection;
        }

        $d = $parent->snapshot ?? [];
        $sum = fn (string $key) => (float) data_get($d, 'summary.'.$key, 0);

        // แบ่งเป็นก้อนที่บวกกันได้ยอดสุทธิพอดี แล้วรวมกลับเป็นยอดสุทธิของแต่ละคน
        // หารยอดสุทธิตรง ๆ แยกจากก้อนย่อยจะได้ "จ่าย + บัตรของขวัญ ≠ สุทธิ"
        // ไป 1 สตางค์บนบางใบ — ใบเสร็จที่บวกเลขไม่ลงตัวคือใบเสร็จที่เชื่อไม่ได้
        // prior = เงินที่รับไว้ในใบก่อนหน้า (เช่นมัดจำ บนใบยอดคงเหลือ) ไม่ได้แสดง
        $voucher = $this->divideSatang($sum('gift_voucher'), $count);
        $paid = $this->divideSatang((float) $parent->amount, $count);
        $balance = $this->divideSatang($sum('balance'), $count);
        $prior = $this->divideSatang(
            $sum('total') - $sum('gift_voucher') - (float) $parent->amount - $sum('balance'),
            $count,
        );
        $discount = $this->divideSatang($sum('discount'), $count);

        $multipleItems = count((array) data_get($d, 'items', [])) > 1;
        $payerName = data_get($d, 'customer.name');

        $created = new EloquentCollection;
        foreach ($passengers->values() as $i => $passenger) {
            $total = round($voucher[$i] + $paid[$i] + $balance[$i] + $prior[$i], 2);
            $subtotal = round($total + $discount[$i], 2);

            $snapshot = array_merge($d, [
                'customer' => [
                    'name' => $this->passengerName($passenger),
                    'email' => $passenger->email,
                    'phone' => $passenger->phone,
                ],
                'items' => [[
                    'label' => 'ค่าทริปส่วนของผู้เดินทาง',
                    'detail' => trim((string) data_get($d, 'trip.title'))
                        .($multipleItems ? ' · รวมตัวเลือกเสริม/อุปกรณ์เช่า หารเท่ากันทั้งคณะ' : ''),
                    'qty' => 1,
                    'unit' => 'ท่าน',
                    'amount' => $subtotal,
                ]],
                'summary' => array_merge((array) data_get($d, 'summary', []), [
                    'subtotal' => $subtotal,
                    'discount' => $discount[$i],
                    'total' => $total,
                    'gift_voucher' => $voucher[$i],
                    'paid' => $paid[$i],
                    'balance' => $balance[$i],
                ]),
                'personal' => [
                    'index' => $i + 1,
                    'count' => $count,
                    'parent_receipt_no' => $parent->receipt_no,
                    'payer_name' => $payerName,
                ],
            ]);

            $created->push(Receipt::create([
                'booking_id' => $booking->id,
                'parent_id' => $parent->id,
                'passenger_id' => $passenger->id,
                'holder' => Receipt::holderFor($passenger),
                'receipt_no' => $parent->receipt_no.'-'.($i + 1),
                'verify_token' => Receipt::generateToken(),
                'kind' => $parent->kind,
                'amount' => $paid[$i],
                'currency' => $parent->currency,
                'status' => $parent->status,
                'issued_at' => $parent->issued_at ?? now(),
                'snapshot' => $snapshot,
            ]));
        }

        return $created;
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

    private function passengerName(BookingPassenger $passenger): string
    {
        $name = trim((string) $passenger->name);

        return $name !== '' ? $name : '-';
    }

    /**
     * หารยอดเป็นสตางค์เท่า ๆ กัน เศษไปตกที่คนแรก ๆ — ผลรวมเท่ายอดเดิมพอดี
     *
     * @return list<float>
     */
    private function divideSatang(float $amount, int $count): array
    {
        $satang = (int) round($amount * 100);
        $sign = $satang < 0 ? -1 : 1;
        $satang = abs($satang);

        $base = intdiv($satang, $count);
        $remainder = $satang % $count;

        $parts = [];
        for ($i = 0; $i < $count; $i++) {
            $parts[] = $sign * ($base + ($i < $remainder ? 1 : 0)) / 100;
        }

        return $parts;
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
        // บัตรของขวัญไม่ใช่ส่วนลด — เป็นเงินที่จ่ายมาก่อนแล้ว total_amount ถูกหักไปแล้ว
        // จึงต้องบวกกลับเพื่อได้ยอดสุทธิจริงของการจอง
        $voucher = (float) $booking->voucher_amount;
        $paxCount = $booking->passengers->count() ?: 1;

        // ยอดค่าทริปก่อนรวมของเสริม/เช่า/ส่วนลด (แกะกลับจากยอดรวมสุทธิ)
        $ticketsGross = round($total + $voucher - $addonsTotal - $rentalsTotal - $flexi + $discount, 2);

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
        $terms = TermsAcceptanceSummary::forBooking($booking);

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
                'total' => round($total + $voucher, 2),
                'gift_voucher' => $voucher,
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
            // อ้างอิงเงื่อนไขที่ผู้ซื้อตกลงไว้ — ใบเสร็จเป็น snapshot จึงเก็บเป็นข้อความ
            // สำเร็จรูป ใบเสร็จเก่าที่ไม่มีคีย์นี้ก็แค่ไม่แสดงบรรทัดนี้
            'terms' => $terms['status'] === 'none' ? null : [
                'version' => ThaiDate::full(Carbon::parse($terms['version'])),
                'accepted_at' => ThaiDate::shortTime(Carbon::parse($terms['accepted_at'])->setTimezone('Asia/Bangkok')),
            ],
        ];
    }
}
