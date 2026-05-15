<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminExtendedController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AppVersionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DistanceController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SeatController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\VehicleTrackingController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);

        Route::get('{provider}/redirect', [AuthController::class, 'socialRedirect']);
        Route::get('{provider}/callback', [AuthController::class, 'socialCallback']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('profile', [AuthController::class, 'updateProfile']);
        });
    });

    // Reverb / Pusher channel authentication for the mobile app's WebSocket
    // client. Sanctum-authenticated; channel rules live in routes/channels.php.
    Route::middleware('auth:sanctum')->post('broadcasting/auth', function () {
        return Broadcast::auth(request());
    });

    // Trips (public)
    Route::get('trips', [TripController::class, 'index']);
    Route::get('trips/featured', [TripController::class, 'featured']);
    Route::get('trips/{slug}', [TripController::class, 'show']);
    Route::get('trips/{slug}/schedules', [TripController::class, 'schedules']);

    // Vehicles (public for driver app)
    Route::get('vehicles', [VehicleTrackingController::class, 'vehicles']);
    Route::get('vehicles/{id}/schedules/today', [VehicleTrackingController::class, 'vehicleTodaySchedules']);
    Route::post('driver/pin-login', [DriverController::class, 'pinLogin']);

    // Reviews (public read)
    Route::get('reviews', [ReviewController::class, 'index']);

    // Categories (public)
    Route::get('categories', [CategoryController::class, 'index']);

    // Promotions (public active list)
    Route::get('promotions/active', [PromotionController::class, 'publicActive']);

    // Schedules (public)
    Route::get('schedules/{id}', [ScheduleController::class, 'show']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function () {

        // Seat lock
        Route::get('seat-locks/active', [SeatController::class, 'active']);
        Route::delete('seat-locks/{scheduleId}', [SeatController::class, 'cancelActive']);
        Route::get('schedules/{id}/seats', [ScheduleController::class, 'seats']);
        Route::post('schedules/{id}/seats/lock', [SeatController::class, 'lock']);
        Route::delete('schedules/{id}/seats/lock', [SeatController::class, 'unlock']);

        // Bookings
        Route::post('bookings', [BookingController::class, 'store']);
        Route::get('bookings', [BookingController::class, 'index']);
        Route::get('bookings/{ref}', [BookingController::class, 'show']);
        Route::post('bookings/{ref}/cancel', [BookingController::class, 'cancel']);
        Route::get('bookings/{ref}/tracking', [VehicleTrackingController::class, 'bookingTracking']);

        // Promotions validation
        Route::post('promotions/validate', [PromotionController::class, 'validateCode']);

        // Payments
        Route::post('payments/charge', [PaymentController::class, 'charge']);
        Route::post('payments/charge-installment', [PaymentController::class, 'chargeInstallment']);
        Route::get('payments/{booking_ref}', [PaymentController::class, 'status']);

        // Reviews (authenticated)
        Route::get('reviews/my', [ReviewController::class, 'myReviews']);
        Route::post('reviews', [ReviewController::class, 'store']);
        Route::put('reviews/{id}', [ReviewController::class, 'update']);
        Route::delete('reviews/{id}', [ReviewController::class, 'destroy']);
        Route::post('reviews/upload-image', [ReviewController::class, 'uploadImage']);

        // Staff assignment and reviews
        Route::get('staff/schedules/my', [StaffController::class, 'mySchedules']);
        Route::get('staff/reviews/my', [StaffController::class, 'myReviews']);
        Route::post('staff/reviews', [StaffController::class, 'storeReview']);
        Route::post('staff/check-in/lookup', [DriverController::class, 'lookupCheckIn']);
        Route::post('staff/check-in/confirm', [DriverController::class, 'checkIn']);

        // Loyalty program
        Route::get('loyalty/account', [LoyaltyController::class, 'account']);
        Route::get('loyalty/rewards', [LoyaltyController::class, 'rewards']);
        Route::post('loyalty/redeem', [LoyaltyController::class, 'redeem']);
        Route::get('loyalty/coupons', [LoyaltyController::class, 'myCoupons']);

        // Smart notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::delete('notifications', [NotificationController::class, 'destroyAll']);
        Route::post('notifications/push-token', [NotificationController::class, 'storePushToken']);
        Route::delete('notifications/push-token', [NotificationController::class, 'destroyPushToken']);
        Route::put('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::put('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        // Driver app
        Route::prefix('driver')->group(function () {
            Route::get('me', [DriverController::class, 'me']);
            Route::get('schedules', [DriverController::class, 'schedules']);
            Route::post('check-in/lookup', [DriverController::class, 'lookupCheckIn']);
            Route::post('check-in', [DriverController::class, 'checkIn']);
        });
    });

    // Payment webhook (no auth, verify signature)
    Route::post('payments/webhook', [PaymentController::class, 'webhook']);

    // Customer Tracking is authenticated above; booking refs are not public lookup keys.

    // Distance Matrix (public)
    Route::post('distance', [DistanceController::class, 'calculate']);
    Route::get('schedules/{id}/pickup-distances', [DistanceController::class, 'pickupDistances']);

    // Vehicle GPS Tracking (Public - for testing without auth)
    Route::prefix('tracking')->group(function () {
        Route::post('update', [VehicleTrackingController::class, 'updateLocation']);
        Route::post('batch', [VehicleTrackingController::class, 'batchUpdateLocation']);
        Route::get('current', [VehicleTrackingController::class, 'currentLocations']);
        Route::get('current/{vehicleId}', [VehicleTrackingController::class, 'currentLocation']);
        Route::get('history/{vehicleId}', [VehicleTrackingController::class, 'locationHistory']);
        Route::get('{vehicleId}/eta', [DistanceController::class, 'vehicleETA']);
        Route::get('{vehicleId}/eta/schedule/{scheduleId}', [DistanceController::class, 'vehicleETAToPickups']);
    });

    // Contacts
    Route::post('contacts', [ContactController::class, 'store']);

    // Analytics (public)
    Route::get('stats', [AnalyticsController::class, 'publicStats']);
    Route::get('app/version', [AppVersionController::class, 'show']);

    // Admin routes
    Route::middleware(['auth:sanctum', 'role:admin|operator'])->prefix('admin')->group(function () {
        // Dashboard
        Route::get('dashboard', [AdminController::class, 'dashboard']);

        // Trips CRUD
        Route::get('trips', [AdminController::class, 'trips']);
        Route::get('trips/{id}', [AdminController::class, 'showTrip']);
        Route::post('trips', [AdminController::class, 'storeTrip']);
        Route::put('trips/{id}', [AdminController::class, 'updateTrip']);
        Route::patch('trips/bulk-update-field', [AdminController::class, 'bulkUpdateTripField']);
        Route::delete('trips/{id}', [AdminController::class, 'deleteTrip']);

        // Schedules CRUD
        Route::get('schedules', [AdminController::class, 'schedules']);
        Route::post('schedules', [AdminController::class, 'storeSchedule']);
        Route::put('schedules/{id}', [AdminController::class, 'updateSchedule']);
        Route::patch('schedules/bulk-update', [AdminController::class, 'bulkUpdateSchedules']);
        Route::delete('schedules/{id}', [AdminController::class, 'deleteSchedule']);
        Route::post('schedules/move-bookings', [AdminController::class, 'moveBookings']);
        Route::get('schedules/{id}/staff', [AdminController::class, 'scheduleStaff']);
        Route::put('schedules/{id}/staff', [AdminController::class, 'syncScheduleStaff']);

        // Schedule Pickup Points
        Route::get('schedules/{id}/pickup-points', [AdminController::class, 'pickupPoints']);
        Route::post('schedules/{id}/pickup-points', [AdminController::class, 'storePickupPoint']);
        Route::put('schedules/{id}/pickup-points/{pointId}', [AdminController::class, 'updatePickupPoint']);
        Route::delete('schedules/{id}/pickup-points/{pointId}', [AdminController::class, 'deletePickupPoint']);

        // Bookings
        Route::get('bookings', [AdminController::class, 'bookings']);
        Route::post('bookings/manual', [AdminController::class, 'storeManualBooking']);
        Route::get('bookings/{ref}', [AdminController::class, 'showBooking']);
        Route::post('bookings/{ref}', [AdminController::class, 'updateBooking']);
        Route::put('bookings/{ref}/status', [AdminController::class, 'updateBookingStatus']);
        Route::delete('bookings/{ref}', [AdminController::class, 'deleteBooking']);
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
        Route::get('staff/users', [AdminController::class, 'staffUsers']);
        Route::post('users', [AdminController::class, 'storeUser']);
        Route::put('users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('users/{id}', [AdminController::class, 'deleteUser']);

        // Upload
        Route::post('upload-image', [AdminController::class, 'uploadMedia']);
        Route::get('media', [AdminController::class, 'listMedia']);
        Route::delete('media', [AdminController::class, 'deleteMedia']);

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

        // Categories CRUD
        Route::get('categories', [CategoryController::class, 'adminIndex']);
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{id}', [CategoryController::class, 'update']);
        Route::delete('categories/{id}', [CategoryController::class, 'destroy']);

        // Analytics Dashboard
        Route::get('analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('analytics/seat-alerts', [AnalyticsController::class, 'seatAlerts']);

        // Vehicle GPS Tracking
        Route::prefix('tracking')->group(function () {
            Route::post('update', [VehicleTrackingController::class, 'updateLocation']);
            Route::post('batch', [VehicleTrackingController::class, 'batchUpdateLocation']);
            Route::get('current', [VehicleTrackingController::class, 'currentLocations']);
            Route::get('current/{vehicleId}', [VehicleTrackingController::class, 'currentLocation']);
            Route::get('history/{vehicleId}', [VehicleTrackingController::class, 'locationHistory']);
        });

        // Contacts Management
        Route::get('contacts', [ContactController::class, 'index']);
        Route::put('contacts/{id}/read', [ContactController::class, 'markAsRead']);
        Route::delete('contacts/{id}', [ContactController::class, 'destroy']);

        // Promotions CRUD
        Route::get('promotions', [PromotionController::class, 'index']);
        Route::post('promotions', [PromotionController::class, 'store']);
        Route::get('promotions/{id}', [PromotionController::class, 'show']);
        Route::put('promotions/{id}', [PromotionController::class, 'update']);
        Route::delete('promotions/{id}', [PromotionController::class, 'destroy']);
    });
});
