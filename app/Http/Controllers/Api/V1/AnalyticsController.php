<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    use ApiResponse;

    public function overview(Request $request): JsonResponse
    {
        $from = $request->get('from', now()->startOfMonth()->format('Y-m-d'));
        $to   = $request->get('to', now()->format('Y-m-d'));

        $bookings = Booking::whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to);

        $totalBookings   = (clone $bookings)->count();
        $confirmedCount  = (clone $bookings)->where('status', 'confirmed')->count();
        $cancelledCount  = (clone $bookings)->where('status', 'cancelled')->count();
        $pendingCount    = (clone $bookings)->where('status', 'pending')->count();
        $totalRevenue    = (clone $bookings)->where('status', 'confirmed')->sum('paid_amount');
        $avgOrderValue   = $confirmedCount > 0 ? $totalRevenue / $confirmedCount : 0;

        $newCustomers = User::role('customer')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->count();

        $avgRating = Review::where('is_approved', true)->avg('rating');
        $totalReviews = Review::where('is_approved', true)->count();

        // Revenue trend (daily for short ranges, monthly for longer)
        $days = \Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to));
        $groupFormat = $days <= 31 ? '%Y-%m-%d' : '%Y-%m';
        $labelFormat = $days <= 31 ? 'Y-m-d' : 'Y-m';

        $revenueTrend = Booking::where('status', 'confirmed')
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw("DATE_FORMAT(created_at, '{$groupFormat}') as period, SUM(paid_amount) as revenue, COUNT(*) as bookings")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(fn($r) => [
                'period'   => $r->period,
                'revenue'  => (float) $r->revenue,
                'bookings' => (int) $r->bookings,
            ]);

        // Top trips
        $topTrips = Booking::where('bookings.status', 'confirmed')
            ->whereDate('bookings.created_at', '>=', $from)
            ->whereDate('bookings.created_at', '<=', $to)
            ->join('trip_schedules', 'bookings.schedule_id', '=', 'trip_schedules.id')
            ->join('trips', 'trip_schedules.trip_id', '=', 'trips.id')
            ->selectRaw('trips.id, trips.title, trips.type, COUNT(bookings.id) as bookings_count, SUM(bookings.paid_amount) as revenue')
            ->groupBy('trips.id', 'trips.title', 'trips.type')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn($t) => [
                'trip_id'       => $t->id,
                'title'         => $t->title,
                'type'          => $t->type,
                'bookings_count' => (int) $t->bookings_count,
                'revenue'       => (float) $t->revenue,
            ]);

        // Bookings by day of week
        $byDow = Booking::whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->selectRaw('DAYOFWEEK(created_at) as dow, COUNT(*) as count')
            ->groupBy('dow')
            ->orderBy('dow')
            ->pluck('count', 'dow')
            ->toArray();

        $dowLabels = ['', 'อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
        $bookingsByDow = [];
        for ($i = 1; $i <= 7; $i++) {
            $bookingsByDow[] = ['day' => $dowLabels[$i], 'count' => (int) ($byDow[$i] ?? 0)];
        }

        // Rating distribution
        $ratingDist = Review::where('is_approved', true)
            ->selectRaw('rating, COUNT(*) as count')
            ->groupBy('rating')
            ->orderBy('rating')
            ->pluck('count', 'rating')
            ->toArray();

        $ratingDistribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $ratingDistribution[] = ['stars' => $i, 'count' => (int) ($ratingDist[$i] ?? 0)];
        }

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'total_bookings'   => $totalBookings,
                'confirmed'        => $confirmedCount,
                'cancelled'        => $cancelledCount,
                'pending'          => $pendingCount,
                'total_revenue'    => (float) $totalRevenue,
                'avg_order_value'  => round($avgOrderValue, 2),
                'new_customers'    => $newCustomers,
                'avg_rating'       => round((float) $avgRating, 1),
                'total_reviews'    => $totalReviews,
                'conversion_rate'  => $totalBookings > 0 ? round($confirmedCount / $totalBookings * 100, 1) : 0,
            ],
            'revenue_trend'       => $revenueTrend,
            'top_trips'           => $topTrips,
            'bookings_by_dow'     => $bookingsByDow,
            'rating_distribution' => $ratingDistribution,
        ]);
    }

    public function seatAlerts(): JsonResponse
    {
        $schedules = TripSchedule::with('trip')
            ->where('status', 'open')
            ->where('departure_date', '>=', now())
            ->where('departure_date', '<=', now()->addDays(7))
            ->get()
            ->filter(fn($s) => ($s->total_seats - $s->booked_seats) <= max(3, $s->total_seats * 0.15))
            ->map(fn($s) => [
                'schedule_id'       => $s->id,
                'trip_title'        => $s->trip->title,
                'departure_date'    => $s->departure_date->format('Y-m-d'),
                'total_seats'       => $s->total_seats,
                'booked_seats'      => $s->booked_seats,
                'available_seats'   => $s->total_seats - $s->booked_seats,
                'occupancy_percent' => $s->total_seats > 0
                    ? round($s->booked_seats / $s->total_seats * 100, 1)
                    : 0,
            ])
            ->values();

        return $this->success($schedules);
    }
    public function publicStats(): JsonResponse
    {
        $totalTrips = Trip::count();
        $avgRating = Review::where('is_approved', true)->avg('rating') ?: 5.0;
        $totalReviews = Review::where('is_approved', true)->count();
        $totalCustomers = User::role('customer')->count();

        return $this->success([
            'total_trips' => $totalTrips,
            'avg_rating' => round((float) $avgRating, 1),
            'total_reviews' => $totalReviews,
            'total_customers' => $totalCustomers,
        ]);
    }
}
