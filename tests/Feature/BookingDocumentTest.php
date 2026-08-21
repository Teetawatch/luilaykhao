<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\BookingDocument;
use App\Models\BookingPassenger;
use App\Models\Category;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Support\MediaDisk;
use App\Support\TripDocumentRequirements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function makeTrip(array $requirements = []): Trip
    {
        return Trip::create([
            'title' => 'เกาะเต่า ดำน้ำ',
            'slug' => 'koh-tao-'.uniqid(),
            'type' => 'diving',
            'location' => 'สุราษฎร์ธานี',
            'region' => 'south',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 4900,
            'status' => 'active',
            'document_requirements' => $requirements,
        ]);
    }

    private function makeBooking(User $owner, Trip $trip): Booking
    {
        $schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(11)->toDateString(),
            'total_seats' => 20,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);

        $booking = Booking::create([
            'booking_ref' => 'LLK-20260101-'.random_int(1000, 9999),
            'user_id' => $owner->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 4900,
            'paid_amount' => 4900,
        ]);

        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'สมชาย ใจดี',
            'phone' => '0812345678',
        ]);

        return $booking->fresh();
    }

    private function divingRequirement(): array
    {
        return [[
            'key' => 'dive-cert',
            'label' => 'บัตรดำน้ำ',
            'note' => 'ใช้สำหรับยืนยันระดับการดำน้ำกับร้านดำน้ำที่เกาะเต่า',
            'required' => true,
        ]];
    }

    public function test_the_customer_can_attach_a_document_the_trip_asks_for(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner, $this->makeTrip($this->divingRequirement()));
        $passenger = $booking->passengers->first();

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $passenger->id,
                'requirement_key' => 'dive-cert',
                'file' => UploadedFile::fake()->image('cert.jpg'),
            ]);

        $response->assertStatus(201);
        $this->assertSame('บัตรดำน้ำ', $response->json('data.file.label'));

        $document = BookingDocument::first();
        $this->assertSame($passenger->id, $document->booking_passenger_id);
        // ข้อความ "ใช้สำหรับ..." ถูก snapshot ไว้กับไฟล์ ไม่ได้อ่านสดจากทริป
        $this->assertStringContainsString('ยืนยันระดับการดำน้ำ', $document->note);
        Storage::disk(MediaDisk::slipDisk())->assertExists($document->file_path);
        // เอกสารระบุตัวบุคคลต้องไม่ไปอยู่บนดิสก์สาธารณะ
        $this->assertStringStartsWith('booking-documents/', $document->file_path);
    }

    public function test_a_document_the_trip_never_asked_for_is_refused(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner, $this->makeTrip($this->divingRequirement()));

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $booking->passengers->first()->id,
                'requirement_key' => 'something-else',
                'file' => UploadedFile::fake()->image('cert.jpg'),
            ]);

        $response->assertStatus(422);
        $this->assertSame(0, BookingDocument::count());
    }

    public function test_a_trip_that_asks_for_nothing_takes_no_uploads(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner, $this->makeTrip());

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $booking->passengers->first()->id,
                'requirement_key' => 'dive-cert',
                'file' => UploadedFile::fake()->image('cert.jpg'),
            ])
            ->assertStatus(422);
    }

    public function test_a_stranger_cannot_read_or_attach_documents(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $booking = $this->makeBooking($owner, $this->makeTrip($this->divingRequirement()));

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/documents")
            ->assertStatus(403);

        $this->actingAs($stranger, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $booking->passengers->first()->id,
                'requirement_key' => 'dive-cert',
                'file' => UploadedFile::fake()->image('cert.jpg'),
            ])
            ->assertStatus(403);
    }

    public function test_the_listing_reports_who_is_still_missing_a_required_document(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner, $this->makeTrip($this->divingRequirement()));
        BookingPassenger::create([
            'booking_id' => $booking->id,
            'name' => 'สมหญิง ใจงาม',
            'phone' => '0898765432',
        ]);

        $first = $booking->passengers()->first();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $first->id,
                'requirement_key' => 'dive-cert',
                'file' => UploadedFile::fake()->image('cert.jpg'),
            ])->assertStatus(201);

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/documents")
            ->assertOk();

        // คนแรกครบแล้ว คนที่สองยังขาด — ทั้งการจองจึงยังไม่ครบ
        $this->assertFalse($response->json('data.passengers.0.requirements.0.is_missing'));
        $this->assertTrue($response->json('data.passengers.1.requirements.0.is_missing'));
        $this->assertTrue($response->json('data.has_missing'));
    }

    public function test_a_team_member_can_view_and_delete_an_attached_document(): void
    {
        Storage::fake(MediaDisk::slipDisk());
        Role::findOrCreate('admin');

        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $booking = $this->makeBooking($owner, $this->makeTrip($this->divingRequirement()));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $booking->passengers->first()->id,
                'requirement_key' => 'dive-cert',
                'file' => UploadedFile::fake()->image('cert.jpg'),
            ])->assertStatus(201);

        $document = BookingDocument::first();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/bookings/{$booking->booking_ref}/documents")
            ->assertOk()
            ->assertJsonPath('data.passengers.0.requirements.0.files.0.id', $document->id);

        // ต้องมีลิงก์เปิดไฟล์เสมอ ไม่งั้นแอดมินเห็นชื่อไฟล์แต่กดดูไม่ได้
        $this->assertNotEmpty(
            $response->json('data.passengers.0.requirements.0.files.0.url')
        );

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/bookings/{$booking->booking_ref}/documents/{$document->id}")
            ->assertOk();

        $this->assertSame(0, BookingDocument::count());
        Storage::disk(MediaDisk::slipDisk())->assertMissing($document->file_path);
    }

    public function test_an_executable_upload_is_rejected(): void
    {
        Storage::fake(MediaDisk::slipDisk());

        $owner = User::factory()->create();
        $booking = $this->makeBooking($owner, $this->makeTrip($this->divingRequirement()));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/bookings/{$booking->booking_ref}/documents", [
                'passenger_id' => $booking->passengers->first()->id,
                'requirement_key' => 'dive-cert',
                'file' => UploadedFile::fake()->create('payload.php', 10, 'application/x-httpd-php'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_the_admin_gets_a_key_written_for_every_requirement_it_saves(): void
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Category::create(['name' => 'ดำน้ำ', 'slug' => 'diving']);
        $trip = $this->makeTrip();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", [
                'title' => $trip->title,
                'type' => 'diving',
                'location' => $trip->location,
                'region' => 'south',
                'difficulty' => 'medium',
                'duration_days' => 2,
                'max_participants' => 20,
                'price_per_person' => 4900,
                // ไม่ส่ง key มา — ระบบต้องเขียนให้เอง ไม่งั้นวันที่แอดมินแก้ชื่อ
                // เอกสาร ไฟล์เก่าจะหลุดจากช่องของมัน
                'document_requirements' => [
                    ['label' => 'ใบรับรองแพทย์', 'note' => 'ใช้สำหรับยืนยันว่าเดินป่าได้', 'required' => true],
                ],
            ])
            ->assertOk();

        $saved = $trip->fresh()->document_requirements;
        $this->assertNotEmpty($saved[0]['key']);
        $this->assertSame('ใบรับรองแพทย์', $saved[0]['label']);
    }

    public function test_two_requirements_with_the_same_name_do_not_share_a_key(): void
    {
        $normalized = TripDocumentRequirements::normalize([
            ['label' => 'สำเนาบัตรประชาชน'],
            ['label' => 'สำเนาบัตรประชาชน'],
        ]);

        $this->assertNotSame($normalized[0]['key'], $normalized[1]['key']);
    }
}
