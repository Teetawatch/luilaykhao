<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminExtendedController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SeatController;
use App\Http\Controllers\Api\V1\TripController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    // Trips (public)
    Route::get('trips', [TripController::class, 'index']);
    Route::get('trips/featured', [TripController::class, 'featured']);
    Route::get('trips/{slug}', [TripController::class, 'show']);
    Route::get('trips/{slug}/schedules', [TripController::class, 'schedules']);

    // Reviews (public read)
    Route::get('reviews', [ReviewController::class, 'index']);

    // Schedules (public)
    Route::get('schedules/{id}', [ScheduleController::class, 'show']);
    Route::get('schedules/{id}/seats', [ScheduleController::class, 'seats']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {

        // Seat lock
        Route::post('schedules/{id}/seats/lock', [SeatController::class, 'lock']);
        Route::delete('schedules/{id}/seats/lock', [SeatController::class, 'unlock']);

        // Bookings
        Route::post('bookings', [BookingController::class, 'store']);
        Route::get('bookings', [BookingController::class, 'index']);
        Route::get('bookings/{ref}', [BookingController::class, 'show']);
        Route::post('bookings/{ref}/cancel', [BookingController::class, 'cancel']);

        // Payments
        Route::post('payments/charge', [PaymentController::class, 'charge']);
        Route::get('payments/{booking_ref}', [PaymentController::class, 'status']);

        // Reviews (authenticated)
        Route::get('reviews/my', [ReviewController::class, 'myReviews']);
        Route::post('reviews', [ReviewController::class, 'store']);
        Route::put('reviews/{id}', [ReviewController::class, 'update']);
        Route::delete('reviews/{id}', [ReviewController::class, 'destroy']);
        Route::post('reviews/upload-image', [ReviewController::class, 'uploadImage']);

        // Loyalty program
        Route::get('loyalty/account', [LoyaltyController::class, 'account']);
        Route::get('loyalty/rewards', [LoyaltyController::class, 'rewards']);
        Route::post('loyalty/redeem', [LoyaltyController::class, 'redeem']);
        Route::get('loyalty/coupons', [LoyaltyController::class, 'myCoupons']);

        // Smart notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::put('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);
    });

    // Payment webhook (no auth, verify signature)
    Route::post('payments/webhook', [PaymentController::class, 'webhook']);

    // Admin routes
    Route::middleware(['auth:sanctum', 'role:admin|operator'])->prefix('admin')->group(function () {
        // Dashboard
        Route::get('dashboard', [AdminController::class, 'dashboard']);

        // Trips CRUD
        Route::get('trips', [AdminController::class, 'trips']);
        Route::post('trips', [AdminController::class, 'storeTrip']);
        Route::put('trips/{id}', [AdminController::class, 'updateTrip']);
        Route::delete('trips/{id}', [AdminController::class, 'deleteTrip']);

        // Schedules CRUD
        Route::get('schedules', [AdminController::class, 'schedules']);
        Route::post('schedules', [AdminController::class, 'storeSchedule']);
        Route::put('schedules/{id}', [AdminController::class, 'updateSchedule']);
        Route::delete('schedules/{id}', [AdminController::class, 'deleteSchedule']);

        // Schedule Pickup Points
        Route::get('schedules/{id}/pickup-points', [AdminController::class, 'pickupPoints']);
        Route::post('schedules/{id}/pickup-points', [AdminController::class, 'storePickupPoint']);
        Route::put('schedules/{id}/pickup-points/{pointId}', [AdminController::class, 'updatePickupPoint']);
        Route::delete('schedules/{id}/pickup-points/{pointId}', [AdminController::class, 'deletePickupPoint']);

        // Bookings
        Route::get('bookings', [AdminController::class, 'bookings']);
        Route::get('bookings/{ref}', [AdminController::class, 'showBooking']);
        Route::put('bookings/{ref}/status', [AdminController::class, 'updateBookingStatus']);
        Route::get('schedules/{id}/manifest', [AdminController::class, 'manifest']);

        // Vehicles CRUD
        Route::get('vehicles', [AdminController::class, 'vehicles']);
        Route::post('vehicles', [AdminController::class, 'storeVehicle']);
        Route::put('vehicles/{id}', [AdminController::class, 'updateVehicle']);
        Route::delete('vehicles/{id}', [AdminController::class, 'deleteVehicle']);

        // Vehicle Pickup Points
        Route::get('vehicles/{id}/pickup-points', [AdminController::class, 'vehiclePickupPoints']);
        Route::post('vehicles/{id}/pickup-points', [AdminController::class, 'storeVehiclePickupPoint']);
        Route::put('vehicles/{id}/pickup-points/{pointId}', [AdminController::class, 'updateVehiclePickupPoint']);
        Route::delete('vehicles/{id}/pickup-points/{pointId}', [AdminController::class, 'deleteVehiclePickupPoint']);

        // Users management
        Route::get('users', [AdminController::class, 'users']);
        Route::post('users', [AdminController::class, 'storeUser']);
        Route::put('users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('users/{id}', [AdminController::class, 'deleteUser']);

        // Upload
        Route::post('upload-image', [AdminController::class, 'uploadMedia']);

        // Calendar
        Route::get('calendar/schedules', [AdminExtendedController::class, 'calendarSchedules']);

        // Customers
        Route::get('customers', [AdminExtendedController::class, 'customers']);
        Route::get('customers/{id}', [AdminExtendedController::class, 'customerDetail']);

        // Vehicle Maintenance
        Route::get('maintenances', [AdminExtendedController::class, 'maintenances']);
        Route::post('maintenances', [AdminExtendedController::class, 'storeMaintenance']);
        Route::put('maintenances/{id}', [AdminExtendedController::class, 'updateMaintenance']);
        Route::delete('maintenances/{id}', [AdminExtendedController::class, 'deleteMaintenance']);

        // Reports
        Route::get('reports/bookings', [AdminExtendedController::class, 'reportBookings']);
        Route::get('reports/revenue', [AdminExtendedController::class, 'reportRevenue']);
        Route::get('reports/vehicles', [AdminExtendedController::class, 'reportVehicles']);

        // QR Check-in
        Route::post('check-in', [AdminExtendedController::class, 'checkIn']);
        Route::post('check-in/{ref}', [AdminExtendedController::class, 'checkInByRef']);

        // Admin Reviews management
        Route::get('reviews', [AdminExtendedController::class, 'adminReviews']);
        Route::post('reviews/{id}/reply', [AdminExtendedController::class, 'adminReplyReview']);
        Route::put('reviews/{id}/toggle-approval', [AdminExtendedController::class, 'adminToggleReviewApproval']);
        Route::delete('reviews/{id}', [AdminExtendedController::class, 'adminDeleteReview']);

        // Admin Loyalty Rewards
        Route::get('loyalty/rewards', [AdminExtendedController::class, 'adminRewards']);
        Route::post('loyalty/rewards', [AdminExtendedController::class, 'adminStoreReward']);
        Route::put('loyalty/rewards/{id}', [AdminExtendedController::class, 'adminUpdateReward']);
        Route::delete('loyalty/rewards/{id}', [AdminExtendedController::class, 'adminDeleteReward']);
        Route::get('loyalty/stats', [AdminExtendedController::class, 'adminLoyaltyStats']);

        // Analytics Dashboard
        Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('analytics/seat-alerts', [AnalyticsController::class, 'seatAlerts']);
    });
});
