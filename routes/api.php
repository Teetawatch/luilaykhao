<?php

use App\Http\Controllers\Api\V1\AdminArticleController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AdminExtendedController;
use App\Http\Controllers\Api\V1\AdminFinanceController;
use App\Http\Controllers\Api\V1\AdminPaymentController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Http\Controllers\Api\V1\AppVersionController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\BookingMemberController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DistanceController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\FlexiDepartureController;
use App\Http\Controllers\Api\V1\GroupPlanController;
use App\Http\Controllers\Api\V1\IncidentController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PassportController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PublicAlbumController;
use App\Http\Controllers\Api\V1\PublicArticleController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\ScheduleItineraryController;
use App\Http\Controllers\Api\V1\SeatController;
use App\Http\Controllers\Api\V1\SosController;
use App\Http\Controllers\Api\V1\SplitPaymentController;
use App\Http\Controllers\Api\V1\StaffController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TripAlertController;
use App\Http\Controllers\Api\V1\TripController;
use App\Http\Controllers\Api\V1\TripPostController;
use App\Http\Controllers\Api\V1\VehicleTrackingController;
use App\Http\Controllers\Api\V1\WaitlistController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth
    Route::prefix('auth')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);

        Route::get('{provider}/redirect', [AuthController::class, 'socialRedirect']);
        Route::get('{provider}/callback', [AuthController::class, 'socialCallback']);
        Route::post('apple/native', [AuthController::class, 'appleNativeLogin']);
        Route::post('line/liff', [AuthController::class, 'lineLiffLogin']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::post('profile', [AuthController::class, 'updateProfile']);
            Route::delete('account', [AuthController::class, 'deleteAccount']);
        });
    });

    // Reverb / Pusher channel authentication for the mobile app's WebSocket
    // client. Sanctum-authenticated; channel rules live in routes/channels.php.
    Route::middleware('auth:sanctum')->post('broadcasting/auth', function () {
        return Broadcast::auth(request());
    });

    // Trips (public)
    Route::middleware('throttle:api')->group(function () {
        Route::get('trips', [TripController::class, 'index']);
        Route::get('trips/featured', [TripController::class, 'featured']);
        Route::get('trips/almost-full', [TripController::class, 'almostFull']);
        Route::get('trips/flash-sale', [TripController::class, 'flashSale']);
        Route::get('trips/urgent-popup', [TripController::class, 'urgentPopup']);
        Route::get('trips/{slug}', [TripController::class, 'show']);
        Route::get('trips/{slug}/related', [TripController::class, 'related']);
        Route::get('trips/{slug}/schedules', [TripController::class, 'schedules']);
    });

    // Vehicles (public for driver app)
    Route::get('vehicles', [VehicleTrackingController::class, 'vehicles']);
    Route::get('vehicles/{id}/schedules/today', [VehicleTrackingController::class, 'vehicleTodaySchedules']);
    Route::post('driver/pin-login', [DriverController::class, 'pinLogin']);
    Route::post('driver/qr-login', [DriverController::class, 'qrLogin'])->middleware('throttle:auth');

    // Reviews (public read)
    Route::get('reviews', [ReviewController::class, 'index']);
    Route::get('reviews/stats', [ReviewController::class, 'stats']);
    Route::get('reviews/photos', [ReviewController::class, 'photos']);

    // Trip posts / ฟีดรูปหลังทริป (public read — ฟีดรวม + ฟีดต่อทริป + คอมเมนต์)
    Route::middleware('throttle:api')->group(function () {
        Route::get('trip-posts', [TripPostController::class, 'index']);
        Route::get('trips/{slug}/posts', [TripPostController::class, 'tripIndex']);
        Route::get('trip-posts/{postId}/comments', [TripPostController::class, 'comments']);
    });

    // Categories (public)
    Route::get('categories', [CategoryController::class, 'index']);

    // Articles / blog (public read — same content the app shows)
    Route::middleware('throttle:api')->group(function () {
        Route::get('articles', [PublicArticleController::class, 'index']);
        Route::get('articles/categories', [PublicArticleController::class, 'categories']);
        Route::get('articles/{slug}', [PublicArticleController::class, 'show']);
    });

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
        Route::post('schedules/{id}/seats/lock', [SeatController::class, 'lock'])->middleware('throttle:seat-lock');
        Route::delete('schedules/{id}/seats/lock', [SeatController::class, 'unlock']);

        // Passport / สมุดสะสมการเดินทาง (สถิติตลอดชีพ + ตราสะสม)
        Route::get('me/passport', [PassportController::class, 'show']);

        // Bookings
        Route::post('bookings', [BookingController::class, 'store']);
        Route::get('bookings', [BookingController::class, 'index']);
        Route::get('bookings/{ref}', [BookingController::class, 'show']);
        Route::post('bookings/{ref}/cancel', [BookingController::class, 'cancel']);
        Route::post('bookings/{ref}/reschedule', [BookingController::class, 'reschedule']);
        Route::post('bookings/{ref}/change-pickup', [BookingController::class, 'changePickup']);
        Route::get('bookings/{ref}/photos', [BookingController::class, 'photos']);
        Route::get('bookings/{ref}/recap', [BookingController::class, 'recap']);
        Route::get('bookings/{ref}/tracking', [VehicleTrackingController::class, 'bookingTracking']);

        // Booking members / companion invites (เชิญเพื่อนเข้าการจองเดียวกัน)
        Route::get('bookings/{ref}/members', [BookingMemberController::class, 'index']);
        Route::post('bookings/{ref}/invites', [BookingMemberController::class, 'store']);
        Route::delete('bookings/{ref}/members/{memberId}', [BookingMemberController::class, 'destroy']);
        Route::get('booking-invites/{token}', [BookingMemberController::class, 'showInvite']);
        Route::post('booking-invites/{token}/accept', [BookingMemberController::class, 'acceptInvite']);

        // Trip posts / ฟีดรูปหลังทริป (เขียน — โพสต์ได้เฉพาะคนที่เคยเดินทาง)
        Route::post('trips/{slug}/posts', [TripPostController::class, 'store'])->middleware('throttle:10,60');
        Route::delete('trip-posts/{postId}', [TripPostController::class, 'destroy']);
        Route::post('trip-posts/{postId}/like', [TripPostController::class, 'like'])->middleware('throttle:60,1');
        Route::post('trip-posts/{postId}/comments', [TripPostController::class, 'storeComment'])->middleware('throttle:20,1');
        Route::delete('trip-posts/{postId}/comments/{commentId}', [TripPostController::class, 'destroyComment']);
        Route::post('trip-posts/{postId}/report', [TripPostController::class, 'report'])->middleware('throttle:10,1');

        // Split payment (แบ่งจ่ายกลุ่ม) — เจ้าของแบ่งยอดคงเหลือให้เพื่อนช่วยจ่าย
        Route::get('bookings/{ref}/split', [SplitPaymentController::class, 'show']);
        Route::post('bookings/{ref}/split', [SplitPaymentController::class, 'store']);
        Route::put('bookings/{ref}/split', [SplitPaymentController::class, 'update']);
        Route::delete('bookings/{ref}/split', [SplitPaymentController::class, 'destroy']);
        Route::post('bookings/{ref}/split/shares/{shareId}/pay', [SplitPaymentController::class, 'pay'])->middleware('throttle:payment');
        Route::post('bookings/{ref}/split/shares/{shareId}/remind', [SplitPaymentController::class, 'remind'])->middleware('throttle:20,1');

        // Flexi-Price (Go Together) — ลูกค้าดู/ตอบรับข้อเสนอไปต่อ (จ่ายส่วนต่างค่ารถ)
        Route::get('bookings/{ref}/flexi-offer', [FlexiDepartureController::class, 'show']);
        Route::post('bookings/{ref}/flexi-offer/respond', [FlexiDepartureController::class, 'respond']);

        // Group chat per trip schedule (customers + assigned staff + admins)
        Route::get('chat/my-conversations', [ChatController::class, 'myConversations']);
        Route::get('schedules/{id}/chat/messages', [ChatController::class, 'index']);
        Route::post('schedules/{id}/chat/messages', [ChatController::class, 'store'])->middleware('throttle:chat');
        Route::put('schedules/{id}/chat/messages/{messageId}', [ChatController::class, 'update']);
        Route::delete('schedules/{id}/chat/messages/{messageId}', [ChatController::class, 'destroy']);
        Route::post('schedules/{id}/chat/read', [ChatController::class, 'markRead']);
        Route::get('schedules/{id}/chat/unread-count', [ChatController::class, 'unreadCount']);
        Route::get('schedules/{id}/chat/room', [ChatController::class, 'room']);
        Route::post('schedules/{id}/chat/messages/{messageId}/pin', [ChatController::class, 'pin']);
        Route::delete('schedules/{id}/chat/messages/{messageId}/pin', [ChatController::class, 'unpin']);
        Route::post('schedules/{id}/chat/messages/{messageId}/react', [ChatController::class, 'react']);
        Route::post('schedules/{id}/chat/typing', [ChatController::class, 'typing'])->middleware('throttle:60,1');
        Route::post('schedules/{id}/chat/joined', [ChatController::class, 'joined'])->middleware('throttle:20,1');

        // ศูนย์ช่วยเหลือในแอป (async support inbox) — ลูกค้าคุยกับทีมงาน
        Route::get('support/conversation', [SupportController::class, 'show']);
        Route::get('support/messages', [SupportController::class, 'messages']);
        Route::post('support/messages', [SupportController::class, 'send'])->middleware('throttle:chat');
        Route::post('support/read', [SupportController::class, 'markRead']);
        Route::get('support/unread-count', [SupportController::class, 'unreadCount']);

        // Operator announcements per schedule. Read side is open to any member;
        // write side is gated to staff/operators inside the controller (canModerate),
        // so assigned staff can post from the driver app too — same as chat pinning.
        Route::get('schedules/{id}/announcements', [AnnouncementController::class, 'index']);
        Route::post('schedules/{id}/announcements/read', [AnnouncementController::class, 'markRead']);
        Route::get('schedules/{id}/announcements/unread-count', [AnnouncementController::class, 'unreadCount']);
        Route::post('schedules/{id}/announcements', [AnnouncementController::class, 'store']);
        Route::put('schedules/{id}/announcements/{announcementId}', [AnnouncementController::class, 'update']);
        Route::delete('schedules/{id}/announcements/{announcementId}', [AnnouncementController::class, 'destroy']);
        Route::post('schedules/{id}/announcements/{announcementId}/pin', [AnnouncementController::class, 'pin']);
        Route::delete('schedules/{id}/announcements/{announcementId}/pin', [AnnouncementController::class, 'unpin']);

        // กำหนดการรอบเดินทาง (itinerary) — สตาฟประจำรอบ/ทีมงานอ่าน; แอดมินจัดการในบล็อก admin
        Route::get('schedules/{id}/itinerary', [ScheduleItineraryController::class, 'index']);
        // เช็คอินจุดกำหนดการ — สตาฟกดยืนยันว่ามาถึงจุดนี้แล้ว (กันลืม/ผิดแผน)
        Route::post('schedules/{id}/itinerary/{itemId}/reach', [ScheduleItineraryController::class, 'reach']);

        // Promotions validation
        Route::post('promotions/validate', [PromotionController::class, 'validateCode'])->middleware('throttle:promotion');

        // Payments
        Route::post('payments/charge', [PaymentController::class, 'charge'])->middleware('throttle:payment');
        Route::post('payments/charge-installment', [PaymentController::class, 'chargeInstallment'])->middleware('throttle:payment');
        Route::post('payments/charge-balance', [PaymentController::class, 'chargeBalance'])->middleware('throttle:payment');
        Route::post('payments/scan-slip', [PaymentController::class, 'scanSlip'])->middleware('throttle:slip-scan');
        Route::get('payments/{booking_ref}', [PaymentController::class, 'status']);

        // Reviews (authenticated)
        Route::get('reviews/my', [ReviewController::class, 'myReviews']);
        Route::post('reviews', [ReviewController::class, 'store']);
        Route::put('reviews/{id}', [ReviewController::class, 'update']);
        Route::delete('reviews/{id}', [ReviewController::class, 'destroy']);
        Route::post('reviews/upload-image', [ReviewController::class, 'uploadImage']);
        Route::post('reviews/upload-video', [ReviewController::class, 'uploadVideo']);

        // Staff assignment and reviews
        Route::get('staff/schedules/my', [StaffController::class, 'mySchedules']);
        Route::get('staff/reviews/my', [StaffController::class, 'myReviews']);
        Route::post('staff/reviews', [StaffController::class, 'storeReview']);
        Route::post('staff/check-in/lookup', [DriverController::class, 'lookupCheckIn']);
        Route::post('staff/check-in/confirm', [DriverController::class, 'checkIn']);

        // SOS emergency alerts
        Route::post('sos', [SosController::class, 'trigger']);
        Route::get('sos/active', [SosController::class, 'active']);
        Route::post('sos/{id}/resolve', [SosController::class, 'resolve']);

        // Waitlist
        Route::get('waitlist', [WaitlistController::class, 'myEntries']);
        Route::get('schedules/{id}/waitlist/status', [WaitlistController::class, 'scheduleStatus']);
        Route::post('schedules/{id}/waitlist', [WaitlistController::class, 'join']);
        Route::delete('schedules/{id}/waitlist', [WaitlistController::class, 'leave']);

        // Group trip invite (host-pays-all) — "ชวนเพื่อนมาเป็นกลุ่ม"
        Route::get('group-plans/mine', [GroupPlanController::class, 'mine']);
        Route::post('schedules/{id}/group-plans', [GroupPlanController::class, 'store'])->middleware('throttle:seat-lock');
        Route::get('group-plans/{code}', [GroupPlanController::class, 'show']);
        Route::post('group-plans/{code}/join', [GroupPlanController::class, 'join']);
        Route::post('group-plans/{code}/claim-seat', [GroupPlanController::class, 'claimSeat'])->middleware('throttle:seat-lock');
        Route::post('group-plans/{code}/release-seat', [GroupPlanController::class, 'releaseSeat']);
        Route::post('group-plans/{code}/leave', [GroupPlanController::class, 'leave']);
        Route::post('group-plans/{code}/checkout', [GroupPlanController::class, 'checkout']);
        Route::delete('group-plans/{code}', [GroupPlanController::class, 'cancel']);

        // Trip price & availability alerts (per-trip bell)
        Route::get('trip-alerts', [TripAlertController::class, 'index']);
        Route::post('trips/{slug}/alerts', [TripAlertController::class, 'store']);
        Route::delete('trips/{slug}/alerts', [TripAlertController::class, 'destroy']);

        // Loyalty program
        Route::get('loyalty/account', [LoyaltyController::class, 'account']);
        Route::get('loyalty/rewards', [LoyaltyController::class, 'rewards']);
        Route::post('loyalty/redeem', [LoyaltyController::class, 'redeem']);
        Route::get('loyalty/coupons', [LoyaltyController::class, 'myCoupons']);

        // Referral program (invite friends → both earn loyalty points)
        Route::get('referral', [ReferralController::class, 'show']);

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
            Route::get('schedules/{id}/manifest', [DriverController::class, 'scheduleManifest']);
            Route::post('schedules/{id}/pickup-points/{pointId}/complete', [DriverController::class, 'completePickup']);
            Route::post('schedules/{id}/depart', [DriverController::class, 'markDeparted']);

            // Pre-trip vehicle inspection (driver-only safety checklist)
            Route::get('schedules/{id}/inspection', [DriverController::class, 'inspection']);
            Route::post('schedules/{id}/inspection', [DriverController::class, 'storeInspection']);
            Route::post('check-in/lookup', [DriverController::class, 'lookupCheckIn']);
            Route::post('check-in', [DriverController::class, 'checkIn']);

            // On-trip incident reports (accident / injury logged by staff)
            Route::get('schedules/{id}/incidents', [IncidentController::class, 'index']);
            Route::post('schedules/{id}/incidents', [IncidentController::class, 'store']);
            Route::post('incidents/{id}/resolve', [IncidentController::class, 'resolve']);
        });
    });

    // Payment webhook (no auth, verify signature)
    Route::post('payments/webhook', [PaymentController::class, 'webhook']);

    // Guest booking lookup: ยืนยันตัวตนด้วย booking_ref + เบอร์โทร (ไม่ต้องล็อกอิน)
    Route::post('bookings/guest-lookup', [VehicleTrackingController::class, 'guestLookup'])->middleware('throttle:20,1');
    // Guest booking lookup by name: ค้นด้วยชื่อ + เบอร์โทรเต็ม (ไม่เปิดเผย booking_ref)
    Route::post('bookings/guest-lookup-by-name', [VehicleTrackingController::class, 'guestLookupByName'])->middleware('throttle:10,1');

    // Live Share Link: ติดตามรถแบบสาธารณะผ่าน share token (ไม่ต้องล็อกอิน)
    Route::get('track/{token}', [VehicleTrackingController::class, 'sharedTracking'])->middleware('throttle:120,1');

    // Public photo album: ดาวน์โหลดรูปประจำรอบผ่านลิงก์สาธารณะ (ไม่ต้องล็อกอิน)
    Route::get('album/{token}/photos', [PublicAlbumController::class, 'photos'])->middleware('throttle:120,1');

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
        Route::get('route', [DistanceController::class, 'route'])->middleware('throttle:60,1');
        Route::get('{vehicleId}/eta', [DistanceController::class, 'vehicleETA']);
        Route::get('{vehicleId}/eta/schedule/{scheduleId}', [DistanceController::class, 'vehicleETAToPickups']);
    });

    // Contacts
    Route::post('contacts', [ContactController::class, 'store'])->middleware('throttle:contact');

    // Analytics (public)
    Route::get('stats', [AnalyticsController::class, 'publicStats']);
    Route::get('app/version', [AppVersionController::class, 'show']);

    // Hero Slides (public)
    Route::get('hero-slides', [AdminController::class, 'publicHeroSlides']);

    // Gallery — ภาพประทับใจ (public)
    Route::get('gallery', [AdminController::class, 'publicGallery']);

    // Admin routes
    Route::middleware(['auth:sanctum', 'role:admin|operator'])->prefix('admin')->group(function () {
        // Dashboard
        Route::get('dashboard', [AdminController::class, 'dashboard']);

        // Trip posts moderation — ฟีดรูปหลังทริป (ดูทั้งหมด/ซ่อน/ลบ)
        Route::get('trip-posts', [TripPostController::class, 'adminIndex']);
        Route::post('trip-posts/{postId}/hide', [TripPostController::class, 'adminHide']);
        Route::post('trip-posts/{postId}/unhide', [TripPostController::class, 'adminUnhide']);
        Route::delete('trip-posts/{postId}', [TripPostController::class, 'adminDestroy']);

        // Group chat — list active conversations
        Route::get('chat/conversations', [ChatController::class, 'adminConversations']);

        // ศูนย์ช่วยเหลือ — กล่องข้อความรวมของทีมงาน
        Route::get('support/conversations', [SupportController::class, 'adminIndex']);
        Route::get('support/conversations/unread-count', [SupportController::class, 'adminUnreadTotal']);
        Route::get('support/conversations/{id}', [SupportController::class, 'adminShow']);
        Route::post('support/conversations/{id}/read', [SupportController::class, 'adminMarkRead']);
        Route::post('support/conversations/{id}/messages', [SupportController::class, 'adminReply'])->middleware('throttle:chat');
        Route::post('support/conversations/{id}/close', [SupportController::class, 'adminClose']);
        Route::post('support/conversations/{id}/reopen', [SupportController::class, 'adminReopen']);

        // Trips CRUD
        Route::get('trips', [AdminController::class, 'trips']);
        Route::get('trips/{id}', [AdminController::class, 'showTrip']);
        Route::post('trips', [AdminController::class, 'storeTrip']);
        Route::put('trips/{id}', [AdminController::class, 'updateTrip']);
        Route::patch('trips/bulk-update-field', [AdminController::class, 'bulkUpdateTripField']);
        Route::delete('trips/{id}', [AdminController::class, 'deleteTrip']);

        // Blog articles CRUD + publishing
        Route::get('articles', [AdminArticleController::class, 'index']);
        Route::get('article-categories', [AdminArticleController::class, 'categories']);
        Route::post('article-categories', [AdminArticleController::class, 'storeCategory']);
        Route::put('article-categories/{id}', [AdminArticleController::class, 'updateCategory']);
        Route::delete('article-categories/{id}', [AdminArticleController::class, 'destroyCategory']);
        Route::get('article-tags', [AdminArticleController::class, 'tags']);
        Route::post('articles', [AdminArticleController::class, 'store']);
        Route::get('articles/{id}', [AdminArticleController::class, 'show']);
        Route::put('articles/{id}', [AdminArticleController::class, 'update']);
        Route::patch('articles/{id}/publish', [AdminArticleController::class, 'publish']);
        Route::delete('articles/{id}', [AdminArticleController::class, 'destroy']);

        // Schedules CRUD
        Route::get('schedules', [AdminController::class, 'schedules']);
        Route::post('schedules', [AdminController::class, 'storeSchedule']);
        Route::put('schedules/{id}', [AdminController::class, 'updateSchedule']);
        Route::patch('schedules/bulk-update', [AdminController::class, 'bulkUpdateSchedules']);
        Route::delete('schedules/{id}', [AdminController::class, 'deleteSchedule']);
        Route::post('schedules/move-bookings', [AdminController::class, 'moveBookings']);
        Route::get('schedules/{id}/staff', [AdminController::class, 'scheduleStaff']);
        Route::put('schedules/{id}/staff', [AdminController::class, 'syncScheduleStaff']);

        // Flexi-Price (Go Together) — ผู้จัดยื่นข้อเสนอส่วนต่างค่ารถให้รอบที่คนไม่ครบ
        Route::get('flexi-offers', [FlexiDepartureController::class, 'adminIndex']);
        Route::post('flexi-offers/{id}/cancel', [FlexiDepartureController::class, 'adminCancel']);
        Route::post('schedules/{id}/flexi-offer', [FlexiDepartureController::class, 'store']);

        // Schedule Pickup Points
        Route::get('schedules/{id}/pickup-points', [AdminController::class, 'pickupPoints']);
        Route::get('schedules/{id}/pickup-points/copy-sources', [AdminController::class, 'pickupCopySources']);
        Route::post('schedules/{id}/pickup-points/copy-from', [AdminController::class, 'copyPickupPointsFrom']);
        Route::post('schedules/{id}/pickup-points', [AdminController::class, 'storePickupPoint']);
        Route::post('schedules/{id}/pickup-points/sync-images', [AdminController::class, 'syncPickupImages']);
        Route::put('schedules/{id}/pickup-points/{pointId}', [AdminController::class, 'updatePickupPoint']);
        Route::delete('schedules/{id}/pickup-points/{pointId}', [AdminController::class, 'deletePickupPoint']);

        // Schedule Itinerary (กำหนดการรอบเดินทาง) — แอดมิน/operator สร้าง/แก้/ลบ/จัดลำดับ
        Route::get('schedules/{id}/itinerary', [ScheduleItineraryController::class, 'index']);
        Route::post('schedules/{id}/itinerary', [ScheduleItineraryController::class, 'store']);
        Route::post('schedules/{id}/itinerary/reorder', [ScheduleItineraryController::class, 'reorder']);
        Route::put('schedules/{id}/itinerary/{itemId}', [ScheduleItineraryController::class, 'update']);
        Route::delete('schedules/{id}/itinerary/{itemId}', [ScheduleItineraryController::class, 'destroy']);

        // Bookings
        Route::get('bookings', [AdminController::class, 'bookings']);
        Route::post('bookings/manual', [AdminController::class, 'storeManualBooking']);
        Route::get('bookings/{ref}', [AdminController::class, 'showBooking']);
        Route::post('bookings/{ref}', [AdminController::class, 'updateBooking']);
        Route::put('bookings/{ref}/status', [AdminController::class, 'updateBookingStatus']);
        Route::delete('bookings/{ref}', [AdminController::class, 'deleteBooking']);
        Route::get('bookings/{ref}/refund-preview', [AdminController::class, 'refundPreview']);
        Route::post('bookings/{ref}/refund', [AdminController::class, 'processRefund']);
        Route::post('bookings/{ref}/transfer', [AdminController::class, 'transferBooking']);
        Route::post('bookings/{ref}/slip/approve', [AdminController::class, 'approveSlip']);
        Route::post('bookings/{ref}/slip/reject', [AdminController::class, 'rejectSlip']);
        Route::post('bookings/{ref}/slip/reverify', [AdminController::class, 'reverifySlip']);
        Route::get('schedules/{id}/manifest', [AdminController::class, 'manifest']);

        // On-trip incident reports (accident / injury) — ops view + close case
        Route::get('incidents', [IncidentController::class, 'adminIndex']);
        Route::post('incidents/{id}/resolve', [IncidentController::class, 'resolve']);

        // Outstanding payments — ติดตาม/ส่งลิงก์ชำระเงินให้ลูกค้าที่ยังค้างจ่าย
        Route::get('payments/outstanding', [AdminPaymentController::class, 'outstanding']);
        Route::post('payments/send-links', [AdminPaymentController::class, 'sendLinksBulk']);
        Route::post('payments/{ref}/send-link', [AdminPaymentController::class, 'sendLink']);

        // Drivers (ทะเบียนคนขับ)
        Route::get('drivers', [AdminController::class, 'drivers']);
        Route::post('drivers', [AdminController::class, 'storeDriver']);
        Route::put('drivers/{id}', [AdminController::class, 'updateDriver']);
        Route::delete('drivers/{id}', [AdminController::class, 'deleteDriver']);

        // Vehicles CRUD
        Route::get('vehicles', [AdminController::class, 'vehicles']);
        Route::post('vehicles', [AdminController::class, 'storeVehicle']);
        Route::put('vehicles/{id}', [AdminController::class, 'updateVehicle']);
        Route::delete('vehicles/{id}', [AdminController::class, 'deleteVehicle']);
        Route::put('vehicles/{id}/driver-pin', [AdminController::class, 'setVehicleDriverPin']);
        Route::delete('vehicles/{id}/driver-pin', [AdminController::class, 'clearVehicleDriverPin']);
        Route::post('vehicles/{id}/login-qr', [AdminController::class, 'createVehicleDriverLoginQr']);

        // Vehicle Pickup Points
        Route::get('vehicles/{id}/pickup-points', [AdminController::class, 'vehiclePickupPoints']);
        Route::post('vehicles/{id}/pickup-points', [AdminController::class, 'storeVehiclePickupPoint']);
        Route::put('vehicles/{id}/pickup-points/{pointId}', [AdminController::class, 'updateVehiclePickupPoint']);
        Route::delete('vehicles/{id}/pickup-points/{pointId}', [AdminController::class, 'deleteVehiclePickupPoint']);

        // Users management
        Route::get('users', [AdminController::class, 'users']);
        Route::get('staff/users', [AdminController::class, 'staffUsers']);
        Route::get('staff/roster', [AdminController::class, 'staffRoster']);
        Route::post('users', [AdminController::class, 'storeUser']);
        Route::put('users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('users/{id}', [AdminController::class, 'deleteUser']);

        // Trip & schedule photos (Cloudflare R2)
        Route::get('trips/{id}/photos', [PhotoController::class, 'tripIndex']);
        Route::post('trips/{id}/photos', [PhotoController::class, 'tripUpload']);
        Route::post('trips/{id}/photos/reorder', [PhotoController::class, 'tripReorder']);
        Route::delete('trips/{id}/photos/{photoId}', [PhotoController::class, 'tripDestroy']);

        Route::get('schedules/{id}/photos', [PhotoController::class, 'scheduleIndex']);
        Route::post('schedules/{id}/photos', [PhotoController::class, 'scheduleUpload']);
        Route::post('schedules/{id}/photos/apply', [PhotoController::class, 'scheduleApply']);
        Route::post('schedules/{id}/photos/reorder', [PhotoController::class, 'scheduleReorder']);
        Route::delete('schedules/{id}/photos', [PhotoController::class, 'scheduleDestroyAll']);
        Route::delete('schedules/{id}/photos/{photoId}', [PhotoController::class, 'scheduleDestroy'])
            ->whereNumber('photoId');

        // Public album share link (anyone with the link can download — no login)
        Route::get('schedules/{id}/photos/share', [PhotoController::class, 'scheduleShareShow']);
        Route::post('schedules/{id}/photos/share', [PhotoController::class, 'scheduleShareStore']);
        Route::delete('schedules/{id}/photos/share', [PhotoController::class, 'scheduleShareDestroy']);

        // Upload
        Route::post('upload-image', [AdminController::class, 'uploadMedia']);
        Route::post('pickup-points/image', [AdminController::class, 'uploadPickupPointImage']);
        Route::get('pickup-points/images', [AdminController::class, 'pickupPointImages']);
        Route::get('must-know/images', [AdminController::class, 'mustKnowImages']);
        Route::get('media', [AdminController::class, 'listMedia']);
        Route::delete('media', [AdminController::class, 'deleteMedia']);

        // Calendar
        Route::get('calendar/schedules', [AdminExtendedController::class, 'calendarSchedules']);
        Route::get('calendar/schedules/{id}/payments', [AdminExtendedController::class, 'schedulePayments'])->whereNumber('id');

        // Inline edit of a manifest passenger (e.g. backfill birth date)
        Route::patch('passengers/{id}', [AdminExtendedController::class, 'updatePassenger'])->whereNumber('id');

        // Birth-date follow-up — upcoming bookings missing DOB + ready links to send
        Route::get('birthdate-followup', [AdminExtendedController::class, 'birthdateFollowup']);

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

        // Finance — สรุปกำไร/ค่าใช้จ่ายต่อทริปและต่อรอบเดินทาง
        Route::prefix('finance')->group(function () {
            Route::get('trips', [AdminFinanceController::class, 'tripProfitSummary']);
            Route::get('trips/{tripId}/schedules', [AdminFinanceController::class, 'tripScheduleProfit']);
            Route::get('trips/{tripId}/templates', [AdminFinanceController::class, 'templates']);
            Route::post('trips/{tripId}/templates', [AdminFinanceController::class, 'storeTemplate']);
            Route::put('trips/{tripId}/templates/{id}', [AdminFinanceController::class, 'updateTemplate']);
            Route::delete('trips/{tripId}/templates/{id}', [AdminFinanceController::class, 'deleteTemplate']);
            Route::get('schedules/{scheduleId}/expenses', [AdminFinanceController::class, 'expenses']);
            Route::post('schedules/{scheduleId}/expenses', [AdminFinanceController::class, 'storeExpense']);
            Route::post('schedules/{scheduleId}/expenses/apply-templates', [AdminFinanceController::class, 'applyTemplates']);
            Route::post('schedules/{scheduleId}/expenses/copy-to', [AdminFinanceController::class, 'copyExpensesTo']);
            Route::put('schedules/{scheduleId}/expenses/{id}', [AdminFinanceController::class, 'updateExpense']);
            Route::delete('schedules/{scheduleId}/expenses/{id}', [AdminFinanceController::class, 'deleteExpense']);
        });

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
        Route::post('categories/reorder', [CategoryController::class, 'reorder']);
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

        // Waitlist management
        Route::get('schedules/{id}/waitlist', [WaitlistController::class, 'adminScheduleWaitlist']);

        // Hero Slides CRUD
        Route::get('hero-slides', [AdminController::class, 'heroSlides']);
        Route::post('hero-slides', [AdminController::class, 'storeHeroSlide']);
        Route::put('hero-slides/{id}', [AdminController::class, 'updateHeroSlide']);
        Route::delete('hero-slides/{id}', [AdminController::class, 'deleteHeroSlide']);
        Route::post('hero-slides/reorder', [AdminController::class, 'reorderHeroSlides']);

        // Urgent-trips popup settings (ป๊อปอัพหน้าเว็บ)
        Route::get('settings/urgent-popup', [AdminController::class, 'urgentPopupSettings']);
        Route::put('settings/urgent-popup', [AdminController::class, 'updateUrgentPopupSettings']);

        // Gallery — ภาพประทับใจ CRUD
        Route::get('gallery', [AdminController::class, 'galleryImages']);
        Route::post('gallery', [AdminController::class, 'storeGalleryImage']);
        Route::put('gallery/{id}', [AdminController::class, 'updateGalleryImage']);
        Route::delete('gallery/{id}', [AdminController::class, 'deleteGalleryImage']);
        Route::post('gallery/reorder', [AdminController::class, 'reorderGalleryImages']);
    });
});
