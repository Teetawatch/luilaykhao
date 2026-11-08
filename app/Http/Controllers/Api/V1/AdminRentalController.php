<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BookingRentalOrder;
use App\Models\TripSchedule;
use App\Services\RentalOrderService;
use App\Services\RentalPickListService;
use App\Support\ThaiDate;
use App\Traits\ApiResponse;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ใบรวมอุปกรณ์เช่าที่ต้องขนไปในแต่ละรอบ
 *
 * ลูกค้าเช่าอุปกรณ์ตอนจอง (ดู `rental_items` บนทริป และ `selected_rentals` ที่
 * แช่แข็งไว้บนการจอง) แต่เดิมข้อมูลนี้อ่านได้จากใบจองรายคนเท่านั้น ทีมงานจึงต้อง
 * เปิดทีละใบมานับเองว่าต้องเตรียมถุงนอนกี่ใบ หน้านี้รวมยอดต่อรอบให้ พร้อมแจกแจง
 * ว่าของชิ้นไหนของใคร เพื่อใช้เป็นเช็กลิสต์ตอนขนของขึ้นรถและตอนคืนของ
 *
 * การรวมยอดอยู่ใน RentalPickListService เพราะทั้ง JSON และ PDF ใช้ตัวเลขชุดเดียวกัน
 */
class AdminRentalController extends Controller
{
    use ApiResponse;

    public function __construct(
        private RentalPickListService $pickList,
        private RentalOrderService $orders,
    ) {}

    /**
     * รอบที่มีคนเช่าอุปกรณ์ — ตั้งต้นเฉพาะรอบที่ยังไม่ออกเดินทาง
     */
    public function schedules(Request $request): JsonResponse
    {
        return $this->success([
            'schedules' => $this->pickList->schedules($request->boolean('include_past')),
        ]);
    }

    /**
     * ใบรวมของรอบเดียว — ยอดรวมต่อชิ้น + รายชื่อคนที่เช่า
     */
    public function show(int $scheduleId): JsonResponse
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);

        return $this->success($this->pickList->forSchedule($schedule));
    }

    /**
     * ใบเตรียมของแบบ PDF — ส่งต่อให้คนที่ไปหยิบของในโกดังได้โดยไม่ต้องเปิดหลังบ้าน
     */
    public function pdf(int $scheduleId): Response
    {
        $schedule = TripSchedule::with('trip')->findOrFail($scheduleId);
        $data = $this->pickList->forSchedule($schedule);

        $pdf = Pdf::loadView('admin.rentals.picklist', [
            'd' => $data,
            'printedAt' => ThaiDate::full(now('Asia/Bangkok')).' เวลา '.now('Asia/Bangkok')->format('H:i').' น.',
            'fontRegular' => storage_path('fonts/Sarabun-Regular.ttf'),
            'fontSemibold' => storage_path('fonts/Sarabun-SemiBold.ttf'),
            'fontBold' => storage_path('fonts/Sarabun-Bold.ttf'),
        ])->setPaper('a4');

        $filename = 'rental-picklist-'.$schedule->id.'-'.($schedule->departure_date?->toDateString() ?? 'round').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * รายการที่ลูกค้าสั่งเช่าเพิ่มเองในแอปหลังจอง (booking_rental_orders)
     *
     * ใบเตรียมของด้านบนรวมของที่ "อยู่บนใบจอง" ซึ่งรวมก้อนที่ยังไม่จ่ายด้วย — หน้านี้
     * แยกให้เห็นว่าก้อนไหนสั่งเอง จ่ายแล้วหรือยัง และก้อนไหนถูกยกเลิกเพราะไม่จ่าย
     */
    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:awaiting_payment,paid,cancelled'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = BookingRentalOrder::query()
            ->with(['booking.schedule.trip', 'booking.user'])
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when(trim($data['q'] ?? ''), function ($q, $term) {
                $like = '%'.$term.'%';
                $q->whereHas('booking', fn ($b) => $b
                    ->where('booking_ref', 'like', $like)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                    ->orWhereHas('schedule.trip', fn ($t) => $t->where('title', 'like', $like)));
            })
            ->latest('id');

        $page = $query->paginate($data['per_page'] ?? 30);
        $page->setCollection($page->getCollection()->map(function (BookingRentalOrder $order) {
            $booking = $order->booking;
            $schedule = $booking?->schedule;
            $closesAt = $booking ? $this->orders->closesAt($booking) : null;

            return [
                ...$this->orders->present($order),
                'booking_ref' => $booking?->booking_ref,
                'booking_status' => $booking?->status,
                'customer_name' => $booking?->user?->name,
                'customer_phone' => $booking?->user?->phone,
                'trip_title' => $schedule?->trip?->title,
                'schedule_id' => $schedule?->id,
                'departure_date' => $schedule?->departure_date?->toDateString(),
                'departure_date_thai' => $schedule?->departure_date ? ThaiDate::full($schedule->departure_date) : null,
                'closes_at' => $closesAt?->toIso8601String(),
            ];
        }));

        $summary = BookingRentalOrder::query()
            ->selectRaw('status, count(*) as orders, coalesce(sum(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => ['orders' => (int) $row->orders, 'amount' => (float) $row->amount]]);

        return $this->paginated($page, 'สำเร็จ', [
            'summary' => [
                'awaiting_payment' => $summary[BookingRentalOrder::STATUS_AWAITING] ?? ['orders' => 0, 'amount' => 0.0],
                'paid' => $summary[BookingRentalOrder::STATUS_PAID] ?? ['orders' => 0, 'amount' => 0.0],
                'cancelled' => $summary[BookingRentalOrder::STATUS_CANCELLED] ?? ['orders' => 0, 'amount' => 0.0],
            ],
            'cutoff_days' => RentalOrderService::cutoffDays(),
        ]);
    }
}
