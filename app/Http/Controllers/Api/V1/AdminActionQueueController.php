<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Contact;
use App\Models\Incident;
use App\Models\InstallmentPayment;
use App\Models\Review;
use App\Models\SosAlert;
use App\Models\SupportConversation;
use App\Models\TripPost;
use App\Services\AtRiskScheduleService;
use App\Services\SlipOcrService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "สิ่งที่รอคุณ" — รวมงานที่ยังไม่มีใครตัดสินใจไว้หน้าเดียว
 *
 * ไอคอนที่ส่งกลับเป็นชื่อ Material Symbols (ไม่ใช่ FontAwesome) เพราะสไตล์
 * .admin-page บังคับฟอนต์ทับ <i> ทำให้ไอคอน FA เพี้ยนในหน้าหลังบ้าน
 *
 * งานค้างของทีมงานกระจายอยู่ทั่วระบบ (สลิปรอตรวจอยู่หน้าการจอง คำขอจุดรับอยู่อีกที่
 * โพสต์ที่ถูกรายงานอยู่หน้าฟีด ฯลฯ) ทำให้ของตกหล่นง่ายเพราะต้องไล่เปิดทีละหน้า
 * ที่นี่รวมทุกกองไว้เป็นการ์ดเดียวกัน เรียงตามความเร่งด่วน พร้อมลิงก์ไปหน้าที่จัดการจริง
 */
class AdminActionQueueController extends Controller
{
    use ApiResponse;

    /** สลิปที่ต้องให้คนตรวจ — OCR อ่านไม่ผ่านหรือยังอ่านไม่เสร็จ */
    private const SLIP_NEEDS_REVIEW = [SlipOcrService::STATUS_PENDING, SlipOcrService::STATUS_FAILED];

    public function index(): JsonResponse
    {
        $groups = [
            $this->sosGroup(),
            $this->incidentGroup(),
            $this->atRiskScheduleGroup(),
            $this->slipGroup(),
            $this->customPickupGroup(),
            $this->supportGroup(),
            $this->postReportGroup(),
            $this->reviewGroup(),
            $this->contactGroup(),
        ];

        return $this->success([
            'groups' => $groups,
            'total' => array_sum(array_column($groups, 'count')),
            // เร่งด่วนจริง = ความปลอดภัย ใช้เด้งเตือนบนหน้าจอ
            'urgent' => array_sum(array_map(
                fn ($g) => $g['severity'] === 'critical' ? $g['count'] : 0,
                $groups
            )),
        ]);
    }

    private function sosGroup(): array
    {
        $alerts = SosAlert::with(['user', 'schedule.trip'])
            ->where('status', 'active')
            ->latest('id')
            ->limit(5)
            ->get();

        return $this->group(
            'sos', 'สัญญาณ SOS ที่ยังไม่ปิดเคส', 'e911_emergency', 'critical',
            SosAlert::where('status', 'active')->count(),
            '/admin/sos',
            $alerts->map(fn (SosAlert $a) => [
                'title' => $a->user?->name ?? 'ลูกค้า',
                'detail' => $a->schedule?->trip?->title ?? 'ไม่ทราบทริป',
                'at' => $a->created_at?->toISOString(),
            ])->values(),
        );
    }

    private function incidentGroup(): array
    {
        $incidents = Incident::with(['schedule.trip'])
            ->where('status', 'open')
            ->latest('id')
            ->limit(5)
            ->get();

        return $this->group(
            'incidents', 'เหตุการณ์ในทริปที่ยังไม่ปิดเคส', 'warning', 'high',
            Incident::where('status', 'open')->count(),
            '/admin/incidents',
            $incidents->map(fn (Incident $i) => [
                'title' => $i->schedule?->trip?->title ?? 'ทริป',
                'detail' => Str::limit((string) $i->description, 60),
                'at' => $i->created_at?->toISOString(),
            ])->values(),
        );
    }

    /**
     * รอบที่ใกล้วันเดินทางแต่ยังจองไม่ถึงขั้นต่ำที่รถออก — ถ้าไม่มีใครลงมือ
     * รอบจะล่มและต้องคืนเงินลูกค้าที่จองไว้แล้ว
     *
     * นับเฉพาะรอบที่มีคนจองแล้ว เพราะรอบว่างเปล่ายกเลิกได้โดยไม่มีใครเสียหาย
     * (หน้าเรดาร์แสดงครบทุกรอบ ที่นี่เอาเฉพาะที่ต้องตัดสินใจจริง)
     */
    private function atRiskScheduleGroup(): array
    {
        $rows = app(AtRiskScheduleService::class)
            ->atRisk()
            ->where('booked_seats', '>', 0);

        return $this->group(
            'at_risk_schedules', 'รอบเสี่ยงไม่ได้ออกเดินทาง', 'group_off', 'high',
            $rows->count(),
            '/admin/at-risk',
            $rows->take(5)->map(fn (array $row) => [
                'title' => $row['trip_title'],
                'detail' => "{$row['departure_label']} · {$row['booked_seats']}/{$row['min_seats']} ท่าน"
                    ." · เหลือ {$row['days_left']} วัน",
                'at' => null,
            ])->values(),
        );
    }

    /**
     * สลิปโอนเงินที่ระบบอ่านอัตโนมัติไม่ผ่าน — ต้องมีคนเปิดดูรูปแล้วกดอนุมัติ/ปฏิเสธ
     * นับทั้งสลิปยอดหลัก ยอดคงเหลือ และรายงวด เพราะทั้งสามค้างที่คนเดียวกัน
     */
    private function slipGroup(): array
    {
        $mainQuery = Booking::whereIn('slip_ocr_status', self::SLIP_NEEDS_REVIEW)
            ->whereIn('status', ['pending', 'confirmed']);
        $balanceQuery = Booking::whereIn('balance_slip_ocr_status', self::SLIP_NEEDS_REVIEW)
            ->whereIn('status', ['pending', 'confirmed']);
        $installmentCount = InstallmentPayment::whereIn('slip_ocr_status', self::SLIP_NEEDS_REVIEW)->count();

        $recent = (clone $mainQuery)->with('user')->latest('id')->limit(5)->get();

        return $this->group(
            'slips', 'สลิปโอนเงินรอตรวจสอบ', 'receipt_long', 'high',
            $mainQuery->count() + $balanceQuery->count() + $installmentCount,
            '/admin/bookings',
            $recent->map(fn (Booking $b) => [
                'title' => $b->booking_ref,
                'detail' => $b->user?->name ?? '',
                'at' => $b->created_at?->toISOString(),
            ])->values(),
        );
    }

    private function customPickupGroup(): array
    {
        $query = Booking::where('custom_pickup_status', Booking::CUSTOM_PICKUP_PENDING);
        $recent = (clone $query)->with('user')->latest('id')->limit(5)->get();

        return $this->group(
            'custom_pickups', 'คำขอจุดรับใหม่รออนุมัติ/ตั้งราคา', 'wrong_location', 'medium',
            $query->count(),
            '/admin/bookings',
            $recent->map(fn (Booking $b) => [
                'title' => $b->booking_ref,
                'detail' => $b->custom_pickup_label ?? '',
                'at' => $b->created_at?->toISOString(),
            ])->values(),
        );
    }

    private function supportGroup(): array
    {
        // ห้องที่ลูกค้าพิมพ์มาใหม่กว่าตัวชี้ "อ่านแล้ว" ของทีมงาน (ตรงกับหน้าศูนย์ช่วยเหลือ)
        $query = SupportConversation::query()
            ->whereHas('messages', fn ($q) => $q
                ->whereColumn('support_messages.id', '>', 'support_conversations.admin_last_read_id')
                ->where('sender_role', 'customer'));

        $recent = (clone $query)->with('user')->orderByDesc('last_message_at')->limit(5)->get();

        return $this->group(
            'support', 'ข้อความในศูนย์ช่วยเหลือที่ยังไม่ตอบ', 'headset_mic', 'high',
            $query->count(),
            '/admin/support',
            $recent->map(fn (SupportConversation $c) => [
                'title' => $c->user?->name ?? 'ลูกค้า',
                'detail' => Str::limit((string) $c->last_message_preview, 60),
                'at' => $c->last_message_at?->toISOString(),
            ])->values(),
        );
    }

    private function postReportGroup(): array
    {
        // โพสต์ที่ถูกรายงานแต่ยังไม่ถูกซ่อน — ระบบซ่อนอัตโนมัติที่ 5 ครั้ง
        // ที่เหลือต้องให้คนตัดสิน
        $query = TripPost::where('reports_count', '>', 0)->whereNull('hidden_at');
        $recent = (clone $query)->with('user')->orderByDesc('reports_count')->limit(5)->get();

        return $this->group(
            'post_reports', 'โพสต์ในฟีดที่ถูกรายงาน', 'flag', 'medium',
            $query->count(),
            '/admin/trip-posts',
            $recent->map(fn (TripPost $p) => [
                'title' => $p->user?->name ?? 'นักเดินทาง',
                'detail' => "ถูกรายงาน {$p->reports_count} ครั้ง",
                'at' => $p->created_at?->toISOString(),
            ])->values(),
        );
    }

    private function reviewGroup(): array
    {
        $query = Review::where('is_approved', false);
        $recent = (clone $query)->with(['user', 'trip'])->latest('id')->limit(5)->get();

        return $this->group(
            'reviews', 'รีวิวรออนุมัติแสดงผล', 'star', 'low',
            $query->count(),
            '/admin/reviews',
            $recent->map(fn (Review $r) => [
                'title' => $r->user?->name ?? 'ลูกค้า',
                'detail' => $r->trip?->title ?? '',
                'at' => $r->created_at?->toISOString(),
            ])->values(),
        );
    }

    private function contactGroup(): array
    {
        $query = Contact::whereNull('read_at');
        $recent = (clone $query)->latest('id')->limit(5)->get();

        return $this->group(
            'contacts', 'ข้อความติดต่อที่ยังไม่ได้เปิดอ่าน', 'mail', 'low',
            $query->count(),
            '/admin/inquiries',
            $recent->map(fn (Contact $c) => [
                'title' => $c->name,
                'detail' => $c->subject ?? '',
                'at' => $c->created_at?->toISOString(),
            ])->values(),
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     */
    private function group(
        string $key,
        string $label,
        string $icon,
        string $severity,
        int $count,
        string $route,
        $items,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'severity' => $severity,
            'count' => $count,
            'route' => $route,
            'items' => $items,
        ];
    }
}
