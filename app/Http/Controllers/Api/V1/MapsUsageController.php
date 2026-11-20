<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Maps\GoogleRoutesClient;
use App\Services\Maps\MapsUsageMeter;
use App\Services\Maps\VehicleEtaService;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapsUsageController extends Controller
{
    use ApiResponse;

    /**
     * แอดมิน: ยอดเรียก Google Maps Platform เดือนนี้ เทียบโควตาฟรี/เพดาน + ETA ที่กำลังคิด
     * GET /api/v1/admin/tracking/maps-usage?month=2026-11
     */
    public function show(
        Request $request,
        MapsUsageMeter $meter,
        VehicleEtaService $eta,
        GoogleRoutesClient $routes,
    ): JsonResponse {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? Carbon::createFromFormat('Y-m-d', $request->query('month').'-01', MapsUsageMeter::TIMEZONE)
            : null;

        return $this->success($meter->summary($month) + [
            'configured' => $routes->configured(),
            'settings' => [
                'refresh_seconds' => max(180, min(300, (int) config('maps.eta.refresh_seconds'))),
                'moved_meters' => (int) config('maps.eta.moved_meters'),
                'traffic_aware' => (bool) config('maps.eta.traffic_aware'),
                'alert_percent' => (int) config('maps.alert_percent'),
            ],
            'eta_sessions' => $eta->activeSessions(),
        ]);
    }
}
