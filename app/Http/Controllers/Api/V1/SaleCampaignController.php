<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\SaleCampaign;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Services\SaleCampaignService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * แคมเปญวันพิเศษ 9.9 / 10.10 — ตั้งครั้งเดียว ลดราคาทริปทุกรอบที่ยังเปิดขาย
 *
 * ราคาที่ลดจริงเกิดขึ้นใน TripSchedule::effective_price ตัวคอนโทรลเลอร์นี้แค่
 * ดูแลตัวแคมเปญ กับตอบคำถามที่แอดมินอยากรู้ก่อนกดเปิด — "จะลดกี่รอบ ราคาจาก
 * เท่าไหร่เหลือเท่าไหร่ และเราจะเสียส่วนลดรวมประมาณเท่าไหร่"
 */
class SaleCampaignController extends Controller
{
    public function index()
    {
        $campaigns = SaleCampaign::orderByDesc('starts_at')->get()
            ->map(fn (SaleCampaign $campaign) => $this->present($campaign));

        return response()->json(['data' => $campaigns]);
    }

    /**
     * แคมเปญที่กำลังลดราคาอยู่ตอนนี้ — เว็บและแอปใช้ขึ้นแบนเนอร์ + นับถอยหลัง
     * ไม่มีแคมเปญคืน data: null ไม่ใช่ 404 ไคลเอนต์จะได้ไม่ต้องดักสถานะ
     */
    public function publicActive(SaleCampaignService $campaigns)
    {
        $campaign = $campaigns->active();

        return response()->json([
            'data' => $campaign ? [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'badge_label' => $campaign->badge_label,
                'tagline' => $campaign->tagline,
                'discount_label' => $campaign->discountLabel(),
                'discount_type' => $campaign->discount_type,
                'discount_value' => (float) $campaign->discount_value,
                'max_discount' => $campaign->max_discount !== null ? (float) $campaign->max_discount : null,
                'theme_color' => $campaign->theme_color,
                'ends_at' => $campaign->ends_at?->toISOString(),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $campaign = SaleCampaign::create($data);
        app(SaleCampaignService::class)->flush();

        return response()->json($this->present($campaign), 201);
    }

    public function show($id)
    {
        return response()->json($this->present(SaleCampaign::findOrFail($id)));
    }

    public function update(Request $request, $id)
    {
        $campaign = SaleCampaign::findOrFail($id);
        $data = $this->validated($request);

        // แก้เงื่อนไขแล้วต้องประกาศใหม่ ถ้าแคมเปญยังไม่เริ่ม
        if ($campaign->isUpcoming()) {
            $data['announced_at'] = null;
        }

        $campaign->update($data);
        app(SaleCampaignService::class)->flush();

        return response()->json($this->present($campaign->fresh()));
    }

    public function destroy($id)
    {
        SaleCampaign::findOrFail($id)->delete();
        app(SaleCampaignService::class)->flush();

        return response()->json(['message' => 'ลบแคมเปญแล้ว']);
    }

    /**
     * พรีวิวก่อนกดเปิด — รอบที่จะโดนลด พร้อมราคาก่อน/หลัง เรียงจากส่วนลดมากไปน้อย
     *
     * คิดจากแคมเปญที่ยังไม่ได้บันทึกก็ได้ (ส่ง discount_type/value มาใน query)
     * แอดมินจะได้ลองตัวเลขก่อนว่าลด 20% แล้วทริปไหนเหลือเท่าไหร่
     */
    public function preview(Request $request)
    {
        $draft = new SaleCampaign([
            'discount_type' => $request->input('discount_type', 'percent'),
            'discount_value' => (float) $request->input('discount_value', 0),
            'max_discount' => $request->filled('max_discount') ? (float) $request->input('max_discount') : null,
            'excluded_trip_ids' => $request->input('excluded_trip_ids', []),
        ]);

        $schedules = TripSchedule::with('trip')
            ->where('status', 'open')
            ->whereDate('departure_date', '>=', now('Asia/Bangkok')->toDateString())
            ->orderBy('departure_date')
            ->get()
            ->filter(fn (TripSchedule $s) => $s->trip && $s->available_seats > 0);

        $rows = $schedules
            ->filter(fn (TripSchedule $s) => $draft->appliesToTrip($s->trip_id))
            ->map(function (TripSchedule $s) use ($draft) {
                // priceWithoutCampaign = ราคาที่ขายอยู่จริงวันนี้ (รวม flash รายรอบ)
                $before = $s->priceWithoutCampaign();
                $after = min($before, $draft->priceFor($before));

                return [
                    'schedule_id' => $s->id,
                    'trip_id' => $s->trip_id,
                    'trip_title' => $s->trip->title,
                    'departure_date' => $s->departure_date?->toDateString(),
                    'seats_left' => $s->available_seats,
                    'price_before' => round($before, 2),
                    'price_after' => round($after, 2),
                    'discount' => round($before - $after, 2),
                ];
            })
            ->sortByDesc('discount')
            ->values();

        return response()->json([
            'data' => [
                'schedules_count' => $rows->count(),
                'excluded_count' => $schedules->count() - $rows->count(),
                'total_seats_left' => (int) $rows->sum('seats_left'),
                // ส่วนลดสูงสุดที่จะเสียถ้าที่นั่งที่เหลือขายหมดทุกที่
                'max_discount_exposure' => round(
                    $rows->sum(fn ($row) => $row['discount'] * $row['seats_left']),
                    2,
                ),
                'schedules' => $rows,
            ],
        ]);
    }

    /** ทริปทั้งหมดไว้ให้แอดมินติ๊กยกเว้น */
    public function tripOptions()
    {
        return response()->json([
            'data' => Trip::where('status', 'active')
                ->orderBy('title')
                ->get(['id', 'title', 'price_per_person']),
        ]);
    }

    private function present(SaleCampaign $campaign): array
    {
        $bookings = Booking::where('sale_campaign_id', $campaign->id)
            ->whereNotIn('status', ['cancelled', 'expired']);

        return array_merge($campaign->toArray(), [
            'is_live' => $campaign->isLive(),
            'is_upcoming' => $campaign->isUpcoming(),
            'discount_label' => $campaign->discountLabel(),
            // ผลลัพธ์จริงหลังแคมเปญเริ่ม — จองไปกี่ใบ และส่วนลดที่ให้ไปรวมเท่าไหร่
            'bookings_count' => (clone $bookings)->count(),
            'revenue' => round((float) (clone $bookings)->sum('total_amount'), 2),
            'discount_given' => round((float) (clone $bookings)->sum('campaign_discount'), 2),
        ]);
    }

    private function validated(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:120',
            'badge_label' => 'nullable|string|max:20',
            'tagline' => 'nullable|string|max:255',
            'discount_type' => 'required|in:percent,amount',
            'discount_value' => 'required|numeric|min:0',
            'max_discount' => 'nullable|numeric|min:0',
            'starts_at' => 'required|date',
            'ends_at' => 'required|date|after:starts_at',
            'is_active' => 'boolean',
            'excluded_trip_ids' => 'nullable|array',
            'excluded_trip_ids.*' => 'integer|exists:trips,id',
            'theme_color' => 'nullable|string|max:20',
            'announce_on_start' => 'boolean',
        ], [], [
            'name' => 'ชื่อแคมเปญ',
            'discount_value' => 'ส่วนลด',
            'starts_at' => 'เวลาเริ่ม',
            'ends_at' => 'เวลาสิ้นสุด',
        ]);

        $data = $validator->validate();

        if ($data['discount_type'] === SaleCampaign::TYPE_PERCENT && $data['discount_value'] > 100) {
            abort(422, 'ส่วนลดเป็นเปอร์เซ็นต์เกิน 100% ไม่ได้');
        }

        return $data;
    }
}
