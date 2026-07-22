<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\TripTrack;
use App\Services\RouteTrackService;
use App\Support\ThaiDate;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * แทร็ก GPS ที่ลูกค้าบันทึกเองระหว่างเดิน — ทำให้สถิติใน Passport/Recap เป็น
 * "ระยะที่คุณเดินจริง" ไม่ใช่ตัวเลขประมาณการของเส้นทางที่แอดมินกรอกไว้
 */
class TripTrackController extends Controller
{
    use ApiResponse;

    public function __construct(private RouteTrackService $routeTracks) {}

    /**
     * อัปโหลด/อัปเดตแทร็กของรอบหนึ่ง — สถิติทั้งหมดคำนวณใหม่ฝั่งเซิร์ฟเวอร์เสมอ
     * เพราะตัวเลขนี้ถูกเอาไปเทียบกับคนอื่นในรอบเดียวกัน
     */
    public function store(Request $request, string $ref): JsonResponse
    {
        $data = $request->validate([
            'points' => ['required', 'array', 'min:2', 'max:20000'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.ele' => ['nullable', 'numeric'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'moving_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $booking = Booking::where('booking_ref', $ref)->with('schedule')->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        if (! $booking->schedule) {
            return $this->error('ไม่พบรอบเดินทางของการจองนี้', 404);
        }

        $points = array_map(fn (array $p) => [
            'lat' => (float) $p['lat'],
            'lng' => (float) $p['lng'],
            'ele' => isset($p['ele']) ? (float) $p['ele'] : null,
        ], $data['points']);

        $computed = $this->routeTracks->build($points);

        $track = TripTrack::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'schedule_id' => $booking->schedule->id,
            ],
            [
                'booking_id' => $booking->id,
                'points' => $computed['points'],
                'distance_km' => $computed['distance_km'],
                'elevation_gain_m' => $computed['elevation_gain_m'],
                'elevation_loss_m' => $computed['elevation_loss_m'],
                'max_elevation_m' => $computed['max_elevation_m'],
                'moving_seconds' => $data['moving_seconds'] ?? 0,
                'started_at' => $data['started_at'] ?? null,
                'ended_at' => $data['ended_at'] ?? null,
            ],
        );

        return $this->success($this->present($track, $computed), 'บันทึกเส้นทางของคุณแล้ว');
    }

    /**
     * แทร็กของผู้ใช้ในรอบนี้ พร้อมบริบทว่าเทียบกับคนอื่นในรอบเดียวกันเป็นอย่างไร
     */
    public function show(Request $request, string $ref): JsonResponse
    {
        $booking = Booking::where('booking_ref', $ref)->with('schedule')->firstOrFail();

        if (! $booking->isAccessibleByUser($request->user()->id)) {
            return $this->error('ไม่พบการจองนี้', 404);
        }

        $track = TripTrack::where('user_id', $request->user()->id)
            ->where('schedule_id', $booking->schedule?->id)
            ->first();

        if (! $track) {
            return $this->success(null);
        }

        return $this->success($this->present($track));
    }

    /**
     * แทร็กทั้งหมดของผู้ใช้ (ไม่รวมจุดพิกัด เพื่อให้ payload เบา)
     */
    public function index(Request $request): JsonResponse
    {
        $tracks = TripTrack::where('user_id', $request->user()->id)
            ->with('schedule.trip')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (TripTrack $track) => [
                'id' => $track->id,
                'schedule_id' => $track->schedule_id,
                'trip_title' => $track->schedule?->trip?->title,
                'distance_km' => (float) $track->distance_km,
                'elevation_gain_m' => $track->elevation_gain_m,
                'moving_seconds' => $track->moving_seconds,
                'started_at' => $track->started_at?->toIso8601String(),
                'started_at_label' => $track->started_at ? ThaiDate::short($track->started_at) : null,
            ]);

        return $this->success($tracks);
    }

    /**
     * ประกอบข้อมูลที่ส่งกลับ — รวม "อันดับในรอบนี้" ซึ่งเป็นบริบทที่ทำให้ตัวเลข
     * มีความหมาย แต่ไม่เปิดเผยว่าใครเป็นใคร
     */
    private function present(TripTrack $track, ?array $computed = null): array
    {
        $peers = TripTrack::where('schedule_id', $track->schedule_id)->get();

        $rankByDistance = $peers
            ->sortByDesc(fn (TripTrack $t) => (float) $t->distance_km)
            ->values()
            ->search(fn (TripTrack $t) => $t->id === $track->id);

        $movingHours = $track->moving_seconds > 0 ? $track->moving_seconds / 3600 : null;

        return [
            'id' => $track->id,
            'schedule_id' => $track->schedule_id,
            'points' => $track->points,
            'distance_km' => (float) $track->distance_km,
            'elevation_gain_m' => $track->elevation_gain_m,
            'elevation_loss_m' => $track->elevation_loss_m,
            'max_elevation_m' => $track->max_elevation_m,
            'moving_seconds' => $track->moving_seconds,
            // ความเร็วเฉลี่ยตอน "กำลังเดิน" — ตัวเลขที่คนเดินป่าใช้วางแผนรอบหน้า
            'average_pace_kmh' => $movingHours !== null && $movingHours > 0
                ? round((float) $track->distance_km / $movingHours, 2)
                : null,
            'steepest' => $computed['steepest'] ?? null,
            'peers_count' => $peers->count(),
            'rank_by_distance' => $rankByDistance === false ? null : $rankByDistance + 1,
            'started_at' => $track->started_at?->toIso8601String(),
            'ended_at' => $track->ended_at?->toIso8601String(),
        ];
    }
}
