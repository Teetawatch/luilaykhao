<?php

namespace Tests\Feature;

use App\Jobs\SendBookingDocumentRemindersJob;
use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingMember;
use App\Models\BookingPassenger;
use App\Models\SmartNotification;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Services\BookingDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingDocumentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-02 10:00', 'Asia/Bangkok'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeBooking(
        int $daysAhead = 7,
        int $bookedSeats = 10,
        array $requirements = [['key' => 'med-cert', 'label' => 'ใบรับรองแพทย์', 'required' => true]],
        array $passengers = ['สมชาย ใจดี'],
    ): Booking {
        $trip = Trip::create([
            'title' => 'ภูกระดึง',
            'slug' => 'phu-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'northeast',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2900,
            'status' => 'active',
            'document_requirements' => $requirements,
        ]);

        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays($daysAhead)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays($daysAhead + 1)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => $bookedSeats,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => 'LLK-20261102-'.random_int(1000, 9999),
            'user_id' => User::factory()->create()->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 2900,
            'paid_amount' => 2900,
        ]);

        foreach ($passengers as $name) {
            BookingPassenger::create(['booking_id' => $booking->id, 'name' => $name, 'phone' => '0812345678']);
        }

        return $booking->fresh('passengers');
    }

    private function attach(Booking $booking, BookingPassenger $passenger, string $key = 'med-cert'): void
    {
        BookingDocument::create([
            'booking_id' => $booking->id,
            'booking_passenger_id' => $passenger->id,
            'requirement_key' => $key,
            'label' => 'ใบรับรองแพทย์',
            'file_path' => 'booking-documents/x.jpg',
            'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 10,
        ]);
    }

    private function runJob(): void
    {
        app()->call([new SendBookingDocumentRemindersJob, 'handle']);
    }

    public function test_the_owner_gets_a_push_a_week_before_departure_when_a_required_document_is_missing(): void
    {
        $booking = $this->makeBooking();

        $this->runJob();

        $notification = SmartNotification::where('user_id', $booking->user_id)->sole();
        $this->assertSame(SendBookingDocumentRemindersJob::TYPE, $notification->type);
        $this->assertStringContainsString('ในอีก 7 วัน', $notification->body);
        $this->assertStringContainsString('ใบรับรองแพทย์', $notification->body);
        $this->assertSame($booking->booking_ref, $notification->data['booking_ref']);
        $this->assertSame('booking_documents', $notification->data['route']);
    }

    public function test_the_day_before_says_tomorrow_and_only_the_milestone_days_fire(): void
    {
        $tomorrow = $this->makeBooking(daysAhead: 1);
        $inFive = $this->makeBooking(daysAhead: 5);

        $this->runJob();

        $this->assertStringContainsString('พรุ่งนี้', SmartNotification::where('user_id', $tomorrow->user_id)->sole()->body);
        $this->assertSame(0, SmartNotification::where('user_id', $inFive->user_id)->count());
    }

    public function test_nobody_is_reminded_once_everything_required_is_attached(): void
    {
        $booking = $this->makeBooking(requirements: [
            ['key' => 'med-cert', 'label' => 'ใบรับรองแพทย์', 'required' => true],
            ['key' => 'photo', 'label' => 'รูปถ่าย', 'required' => false],
        ]);
        $this->attach($booking, $booking->passengers->first());

        $this->runJob();

        $this->assertSame(0, SmartNotification::count());
    }

    public function test_a_trip_that_asks_for_nothing_sends_nothing(): void
    {
        $this->makeBooking(requirements: []);

        $this->runJob();

        $this->assertSame(0, SmartNotification::count());
    }

    public function test_an_underfilled_round_is_not_chased(): void
    {
        $this->makeBooking(bookedSeats: 3);

        $this->runJob();

        $this->assertSame(0, SmartNotification::count());
    }

    public function test_a_group_owner_hears_how_many_are_missing_and_a_linked_friend_hears_about_their_own(): void
    {
        $booking = $this->makeBooking(passengers: ['เจ้าของ', 'เพื่อน', 'น้อง']);
        [$owner, $friendSeat, $sister] = $booking->passengers->all();
        $this->attach($booking, $owner);
        // คนที่แจ้งว่าไม่ไปแล้ว ไม่ต้องทวงเอกสาร
        $sister->forceFill(['not_going_at' => now()])->save();

        $friend = User::factory()->create();
        BookingMember::create([
            'booking_id' => $booking->id,
            'user_id' => $friend->id,
            'passenger_id' => $friendSeat->id,
            'status' => BookingMember::STATUS_ACTIVE,
        ]);

        $this->runJob();

        $this->assertStringContainsString('1 จาก 2 คน', SmartNotification::where('user_id', $booking->user_id)->sole()->body);
        $this->assertStringContainsString('ใบรับรองแพทย์ของคุณ', SmartNotification::where('user_id', $friend->id)->sole()->body);
    }

    public function test_a_retry_on_the_same_day_does_not_push_twice(): void
    {
        $booking = $this->makeBooking();

        $this->runJob();
        $this->runJob();

        $this->assertSame(1, SmartNotification::where('user_id', $booking->user_id)->count());
    }

    public function test_missing_by_passenger_skips_people_who_are_not_going(): void
    {
        $booking = $this->makeBooking(passengers: ['ก', 'ข']);
        $booking->passengers->last()->forceFill(['not_going_at' => now()])->save();

        $missing = app(BookingDocumentService::class)->missingByPassenger($booking->fresh());

        $this->assertSame([$booking->passengers->first()->id => ['ใบรับรองแพทย์']], $missing);
    }
}
