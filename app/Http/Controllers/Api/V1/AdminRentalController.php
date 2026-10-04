<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TripSchedule;
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

    public function __construct(private RentalPickListService $pickList) {}

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
}
