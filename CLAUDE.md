# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Luilaykhao** is a Thai outdoor trip booking platform. The monorepo contains:
- **Laravel backend** (root) — REST API at `/api/v1/`, the source of truth for all business logic
- **`luilaykhao-app/`** — Flutter customer mobile app (iOS/Android)
- **`luilaykhao-driver-app/`** — Flutter driver/staff app (PIN-based login, GPS tracking)

## Commands

### Backend (Laravel)

```bash
# Start all services in parallel (server + queue + logs + vite)
composer run dev

# First-time setup
composer run setup

# Run all tests
composer run test

# Run a single test class or file
php artisan test --filter BookingServiceTest
php artisan test tests/Feature/BookingController.php

# Code formatting
./vendor/bin/pint

# Check SMS credits
php artisan sms:credit

# Verify the APNs auth key can reach Apple (Live Activity push) — no device needed
php artisan apns:check
```

### Flutter apps

```bash
# Customer app — run with API pointing to local backend
cd luilaykhao-app
flutter run --dart-define=API_BASE_URL=http://localhost:8000/api/v1 \
            --dart-define=REVERB_APP_KEY=traildive-key \
            --dart-define=REVERB_HOST=localhost \
            --dart-define=REVERB_PORT=8080 \
            --dart-define=REVERB_SCHEME=ws

# Driver app
cd luilaykhao-driver-app
flutter run --dart-define=API_BASE_URL=http://localhost:8000/api/v1
```

## Architecture

### Data model hierarchy

```
Trip (slug-routed)
  └── TripSchedule  (departure_date, seats, price_override, vehicle)
        ├── Booking (booking_ref LLK-YYYYMMDD-XXXX, payment_type: full|deposit|installment)
        │     ├── BookingPassenger
        │     ├── BookingSeat
        │     └── InstallmentPayment
        ├── SchedulePickupPoint (pickup locations with per-point pricing)
        ├── WaitlistEntry
        └── ScheduleStaffAssignment (pivot to User)
```

`TripSchedule.effective_price` falls back to `Trip.price_per_person` unless `price_override` is set. `SchedulePickupPoint.price` overrides effective_price when a passenger selects a pickup point.

### Backend structure

- **Controllers** — `app/Http/Controllers/Api/V1/` — thin: validate, call service, return response. All use the `ApiResponse` trait (`success()`, `error()`, `paginated()`).
- **Services** — contain all business logic and throw `\Exception` with Thai-language messages on failure:
  - `BookingService` — creates bookings inside a DB transaction, verifies seat locks, calculates pricing (addons, promotions, deposit), fires post-booking notifications
  - `SeatLockService` — Redis-backed soft locks (10 min base + 5 min/extra seat). Falls back to allowing the booking when Redis is unavailable.
  - `WaitlistService` — manages queue; `ProcessWaitlistJob` offers seats when a cancellation frees them; offers expire after `WaitlistService::offerTtlMinutes()` (default 15, editable at `/admin/settings`)
  - `FcmService` — Firebase Cloud Messaging via service account JWT (cached 55 min); SOS alerts sent as high-priority FCM data messages
  - `SmsService` — ThaiBulkSMS integration; messages are queued via `SendPendingSmsJob` and deduplicated per booking/type
  - `MailService` — Brevo SMTP; triggered from `BookingService` and queue jobs
  - `GoogleDistanceService` — Distance Matrix API for pickup ETA calculations
- **Events / Jobs** — real-time broadcast via Laravel Reverb: `SeatLocked`, `SeatReleased`, `SeatBooked`, `VehicleLocationUpdated`, `SosTriggered`
- **Rate limiters** — defined by name in `bootstrap/app.php` booted(): `auth`, `payment`, `seat-lock`, `promotion`, `contact`, `api`

### Observability & ops

- **Error tracking** — Sentry (`sentry/sentry-laravel`), wired in `bootstrap/app.php` `withExceptions()`. No-op unless `SENTRY_LARAVEL_DSN` is set. The Flutter apps report crashes separately via Firebase Crashlytics (`AnalyticsService`).
- **Queues** — Redis-backed; run `php artisan horizon` in production to process/monitor jobs (dashboard at `/horizon`, gated to `admin` role via `viewHorizon`). Local dev still uses `queue:listen` in `composer dev`.

### Auth & roles

- Laravel Sanctum (Bearer token for both mobile and future web)
- Three user classes via Spatie Permission: `admin`, `operator`, `driver`
- Drivers also authenticate via PIN (`driver_pin_hash` on User); the PIN login endpoint is unauthenticated (`/api/v1/driver/pin-login`)
- Admin/operator routes live under `/api/v1/admin/` with `role:admin|operator` middleware
- `User.id_card`, `.allergies`, `.health_notes` are Eloquent-encrypted at rest

### Payments

Payments are collected via **PromptPay QR + bank transfer with slip upload** (config in `config/payment.php`). The customer uploads a transfer slip on `payments/charge` (and the balance/installment/split variants); `SlipOcrService` + `VerifySlipJob` read the slip asynchronously to flag mismatches for admin review. There is no live card-charging gateway wired up. `payment_type` on Booking can be:
- `full` — full amount due at booking
- `deposit` — deposit paid now, balance due later (date in `balance_due_at`)
- `installment` — schedule driven by `InstallmentPayment` records

Deposit can be configured per-schedule as either `percent` or `amount` (per-person). `TripSchedule.resolveDepositAmount()` handles both cases.

`POST /api/v1/payments/webhook` is the gateway-callback endpoint for when a real gateway is connected. It is unauthenticated but requires an HMAC-SHA256 signature of the raw body in `X-Payment-Signature` verified against `PAYMENT_WEBHOOK_SECRET` (disabled/503 when the secret is empty). A `charge.complete` event idempotently confirms a still-pending booking via `BookingService::confirmBooking()`.

### Real-time tracking

Vehicle GPS is pushed to `/api/v1/tracking/update` (no auth, intended for device SDK) and stored in `vehicle_locations`. The `VehicleLocationUpdated` event broadcasts to `vehicle.{vehicleId}` channel. The Flutter customer app connects via `web_socket_channel` to Reverb using `--dart-define` config. The share-tracking feature gives unauthenticated public access via a 12-char random token stored on the Booking (`share_token`).

**Trip member live location** — travellers can opt in to share their own position with the rest of their round (`trip_member_locations`, one row per user per schedule; deleting the row *is* stopping). Who may read/write is `SosParticipantService`, same as SOS, and only within the trip window. `TripMemberLocationUpdated` broadcasts on the **private** channel `trip-members.{scheduleId}` — vehicle position is company data, a person's position is not. Pins older than `TripMemberLocationService::STALE_MINUTES` are never handed out.

### Live Activity ("วันเดินทาง" lock-screen card)

`TripActivityService` is the single source of the card's Thai copy, stage, and ETA — the apps only draw. Stages: `countdown → preparing → enroute → approaching → arriving → arrived → onboard`.

- **iOS** — the app starts the Activity once (`luilaykhao/live_activity` MethodChannel → `ios/Runner/LiveActivityChannel.swift`), posts its push token to `POST /api/v1/live-activities`, and from then on `ApnsLiveActivityService` pushes updates straight to APNs. This needs a **direct APNs auth key** (`.p8`, `APNS_*` in `.env`) because FCM cannot set the `<bundle>.push-type.liveactivity` topic. With `APNS_KEY_ID`/`APNS_TEAM_ID` unset the whole feature no-ops. On iOS 17.2+ the server can also *start* the card unprompted via the push-to-start token stored on `fcm_tokens.live_activity_start_token`.
- **Android** — no ActivityKit; the same state ships as an FCM data message (`type: trip_activity`) that `TripActivityService` (Dart) renders as an ongoing notification on the `trip_activity` channel.
- Driven by `trip-activity:sync` every minute, plus `SyncTripActivityJob` fired from `BookingObserver` on check-in/cancellation so the card flips instantly.
- The widget extension target lives in `ios/LiveActivity/` and is wired into `Runner.xcodeproj` already; `ios/scripts/add_live_activity_target.rb` recreates it idempotently if the project file is ever regenerated. Its embed phase **must** stay ordered before Flutter's "Thin Binary" phase or the build fails with an opaque dependency cycle.
- `departs_at` gotcha applies: it stores Thai wall-clock in a UTC-typed column, so comparisons go through `TripActivityService::nowThai()`, never `now()`.

### Home-screen widget ("ทริปถัดไป")

The Live Activity covers trip day; this covers the other 364. `HomeWidgetService` (PHP) owns the copy and serves one snapshot at `GET /api/v1/me/home-widget` — the next trip's countdown plus the next payment due. Inside the Live Activity window it returns `TripActivityService::stateFor()` verbatim (`is_live: true`) so the lock-screen card and the widget never disagree on the same screen.

- The widgets never reach the network. `HomeWidgetService` (Dart) fetches the snapshot and writes it where the widget can read: **iOS** App Group `group.com.luilaykhao.app` + `WidgetCenter` reload; **Android** SharedPreferences + a direct `AppWidgetManager` update. Both via the `luilaykhao/home_widget` MethodChannel. Refreshed on app resume (2-min floor) and forced after `loadAccountData()`; cleared in `_clearLocalSession()`.
- **iOS** — `TripCountdownWidget` lives in the *existing* `LiveActivityExtension` target (`ios/LiveActivity/`), registered alongside the Live Activity in `LiveActivityBundle`. The App Group must be enabled in the Apple Developer portal for both `com.luilaykhao.app` and `com.luilaykhao.app.LiveActivity`, or `UserDefaults(suiteName:)` silently returns nil and the widget just shows its empty state.
- **Android** — a classic `AppWidgetProvider` + `RemoteViews` (`TripCountdownWidget.kt`), deliberately **not** Glance: Glance would pull in the whole Compose toolchain to draw four lines of text. The payment column is hidden below 250dp wide.
- **The one place native computes anything**: the "อีก N วัน" number, recomputed from `departure_date` on every draw so the count is right after days without the app being opened. Its wording (`อีก N วัน` / `พรุ่งนี้` / `วันนี้`, and the three relative due-date phrases) is therefore duplicated in PHP, Swift, and Kotlin and **must stay in sync**. Thai month names and ฯพ.ศ. dates stay server-side. Both native clocks pin a Gregorian calendar and `Asia/Bangkok` explicitly — a Thai device's Buddhist-era default would otherwise misparse `2026-09-05`.
- `valid_until` (the round's return date) is what lets the widget retire a finished trip on its own; `version` lets an older build refuse a snapshot shape it does not understand instead of guessing.

### Flutter apps

Both apps follow the same structure: `lib/{models,providers,screens,services,theme,widgets}`.  
- State management: Provider (`ChangeNotifier`)
- HTTP: `package:http` via `ApiClient` (thin wrapper with Bearer token injection and 401 handling)
- Reverb WebSocket: `web_socket_channel` in `realtime_service.dart`
- API base URL and Reverb coordinates are injected at build time via `--dart-define`; `lib/config/api_config.dart` reads them with `String.fromEnvironment`

### Testing

Tests use SQLite in-memory (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). Queue is `sync`, cache is `array`, mail is `array`. Feature tests in `tests/Feature/`, unit in `tests/Unit/`.

### Scheduled jobs

Background jobs that run on a schedule (configured via `routes/console.php` or Horizon):
- `SendBookingRemindersJob` — pre-departure reminders
- `SendBalanceDueRemindersJob` / `SendInstallmentRemindersJob` — payment reminders
- `ExpireWaitlistOffersJob` — expires stale waitlist offers
- `eta:notify-pickups` artisan command — ETA push notifications for today's schedules (run every few minutes)
- `trip-activity:sync` artisan command — opens/updates/closes the lock-screen "วันเดินทาง" cards (every minute)
