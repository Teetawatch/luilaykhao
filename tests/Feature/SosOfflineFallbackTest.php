<?php

namespace Tests\Feature;

use App\Jobs\BroadcastSosAlert;
use App\Jobs\DeliverSosSms;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\SosAlert;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\SosSmsService;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * SOS ตอนไม่มีสัญญาณ
 *
 * ระบบ SOS เดิมวิ่งบนเน็ตทั้งเส้น — FCM, Reverb, อีเมล ต้องการ data ทั้งฝั่งคนกด
 * และฝั่งคนรับ ซึ่งเป็นสมมติฐานที่ใช้ไม่ได้กับที่ที่ทริปเดินป่าเกิดขึ้นจริง
 * เทสต์ชุดนี้ล็อกสามอย่างที่ปิดช่องนั้น: เวลาที่กดจริง, การกันซ้ำข้ามการรีสตาร์ท
 * และ SMS ที่ไปถึงเครื่องซึ่งไม่มี data
 */
class SosOfflineFallbackTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);

        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'sos-offline-'.uniqid(), 'type' => 'trekking',
            'location' => 'เลย', 'difficulty' => 'medium', 'duration_days' => 2,
            'max_participants' => 10, 'price_per_person' => 2500, 'status' => 'active',
        ]);

        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 1, 'status' => 'open',
            'transport_type' => 'van',
        ]);

        $this->owner = User::factory()->create(['phone' => '0811111111']);

        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'qr_code' => Booking::generateQrCode(),
            'status' => 'confirmed',
            'total_amount' => 2500,
            'paid_amount' => 2500,
            'payment_type' => 'full',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── เวลาที่กดจริง vs เวลาที่ระบบได้รับ ────────────────────────────────────

    public function test_a_queued_alert_keeps_the_time_it_was_actually_raised(): void
    {
        Bus::fake();

        $raisedAt = now()->subMinutes(95);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', [
                'schedule_id' => $this->schedule->id,
                'occurred_at' => $raisedAt->toISOString(),
                'client_token' => 'tok-queued-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.source', SosAlert::SOURCE_OFFLINE_QUEUE)
            // ทีมค้นหาต้องรู้ว่าพิกัดที่กำลังดูอยู่เก่าไปแล้วกี่นาที
            ->assertJsonPath('data.delay_minutes', 95);

        $alert = SosAlert::firstOrFail();
        $this->assertSame(
            $raisedAt->format('Y-m-d H:i'),
            $alert->occurred_at->format('Y-m-d H:i'),
        );
    }

    public function test_an_alert_sent_straight_away_is_not_marked_as_queued(): void
    {
        Bus::fake();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', [
                'schedule_id' => $this->schedule->id,
                'occurred_at' => now()->subSeconds(20)->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.source', SosAlert::SOURCE_APP)
            ->assertJsonPath('data.delay_minutes', 0);
    }

    /**
     * นาฬิกาเครื่องเป็นค่าที่ผู้ใช้ตั้งเองได้ ค่าที่เพี้ยนต้องไม่ทำให้ SOS ตกไป
     * แค่ถอยไปใช้เวลาที่เซิร์ฟเวอร์รับแทน
     */
    public function test_an_impossible_client_clock_falls_back_to_server_time(): void
    {
        Bus::fake();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', [
                'schedule_id' => $this->schedule->id,
                'occurred_at' => now()->addDays(3)->toISOString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.source', SosAlert::SOURCE_APP);

        $alert = SosAlert::firstOrFail();
        $this->assertSame(0, $alert->delayMinutes());
    }

    /**
     * เคสที่กดคืนสุดท้ายบนดอยแล้วส่งออกได้ตอนรถถึงกรุงเทพ — ตอนที่ส่งถึงเซิร์ฟเวอร์
     * อาจเลยช่วงเวลาทริปไปแล้ว แต่ตอนที่กดยังอยู่ในช่วง ต้องไม่ถูกปฏิเสธทิ้ง
     */
    public function test_the_trip_window_is_checked_against_when_it_was_raised(): void
    {
        Bus::fake();

        $schedule = $this->schedule;
        $schedule->update([
            'departure_date' => now('Asia/Bangkok')->subDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDays(2)->toDateString(),
        ]);

        // ส่งตอนนี้ = นอกช่วงแล้ว
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', ['schedule_id' => $schedule->id])
            ->assertStatus(422);

        // ส่งพร้อมเวลาที่กดจริง (ยังอยู่ในช่วง) = รับ
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', [
                'schedule_id' => $schedule->id,
                'occurred_at' => now()->subHours(30)->toISOString(),
                'client_token' => 'tok-late',
            ])
            ->assertOk();

        $this->assertDatabaseCount('sos_alerts', 1);
    }

    // ── กันซ้ำ ────────────────────────────────────────────────────────────────

    /**
     * กลไกกันซ้ำเดิมดูแค่ "เคสของคนนี้ใน 2 นาทีล่าสุด" ซึ่งครอบไม่ถึงคิวที่ค้าง
     * อยู่ในเครื่องแล้วถูกส่งซ้ำข้ามชั่วโมงหลังผู้ใช้ปิดแอปแล้วเปิดใหม่
     */
    public function test_resending_the_same_queued_alert_does_not_create_a_second_case(): void
    {
        Bus::fake();

        $payload = [
            'schedule_id' => $this->schedule->id,
            'occurred_at' => now()->subMinutes(30)->toISOString(),
            'client_token' => 'tok-same',
        ];

        $first = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', $payload)->assertOk();

        // สามชั่วโมงต่อมา คิวถูกส่งซ้ำ — พ้นหน้าต่างกันซ้ำแบบ 2 นาทีไปนานแล้ว
        Carbon::setTestNow(now()->addHours(3));

        $second = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/sos', $payload)->assertOk();

        $this->assertDatabaseCount('sos_alerts', 1);
        $this->assertSame(
            $first->json('data.id'),
            $second->json('data.id'),
        );
    }

    // ── SMS ถึงคนที่ไม่มี data ─────────────────────────────────────────────────

    public function test_the_alert_is_also_sent_by_sms_to_staff_driver_and_ops(): void
    {
        Bus::fake();
        Mail::fake();
        $this->enableSms();

        $staff = User::factory()->create(['phone' => '0822222222']);
        $this->schedule->staff()->attach($staff->id);

        $driver = User::factory()->create(['phone' => '0833333333']);
        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1',
            'type' => 'van',
            'capacity' => 10,
            'license_plate' => 'กก 1234',
            'driver_user_id' => $driver->id,
            'driver_name' => 'พี่ต้น',
            'driver_phone' => '0833333333',
        ]);
        $this->schedule->update(['vehicle_id' => $vehicle->id]);

        $admin = User::factory()->create(['phone' => '0844444444']);
        $admin->assignRole('admin');

        $alert = SosAlert::create([
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'contact_phone' => $this->owner->phone,
            'occurred_at' => now(),
            'status' => 'active',
        ]);

        app()->call([new BroadcastSosAlert($alert->id), 'handle']);

        foreach (['66822222222', '66833333333', '66844444444'] as $msisdn) {
            Bus::assertDispatched(
                DeliverSosSms::class,
                fn (DeliverSosSms $job) => $this->jobRecipient($job) === $msisdn,
            );
        }

        // คนกดไม่ต้องได้รับ SMS แจ้ง SOS ของตัวเอง
        Bus::assertNotDispatched(
            DeliverSosSms::class,
            fn (DeliverSosSms $job) => $this->jobRecipient($job) === '66811111111',
        );
    }

    public function test_no_sms_goes_out_when_the_channel_is_switched_off(): void
    {
        Bus::fake();
        Mail::fake();
        $this->enableSms();
        Setting::put(SiteSettings::KEY, ['sos_sms_enabled' => false]);

        $staff = User::factory()->create(['phone' => '0822222222']);
        $this->schedule->staff()->attach($staff->id);

        $alert = SosAlert::create([
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'occurred_at' => now(),
            'status' => 'active',
        ]);

        app()->call([new BroadcastSosAlert($alert->id), 'handle']);

        Bus::assertNotDispatched(DeliverSosSms::class);
    }

    /**
     * งานถูก retry ได้ 3 ครั้ง แต่คนรับต้องไม่ได้ข้อความเดิมสามรอบ
     */
    public function test_the_same_number_is_never_texted_twice_for_one_case(): void
    {
        $this->enableSms();
        Http::fake(['*' => Http::response(['id' => 'msg-1'], 200)]);

        $alert = SosAlert::create([
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'latitude' => 16.86,
            'longitude' => 101.79,
            'message' => 'ฉันหลงทาง',
            'occurred_at' => now(),
            'status' => 'active',
        ]);

        $sms = app(SosSmsService::class);

        $this->assertNotNull($sms->sendTo($alert, '66822222222'));
        $this->assertNull($sms->sendTo($alert, '66822222222'));

        $this->assertDatabaseCount('sms_logs', 1);
        $this->assertDatabaseHas('sms_logs', [
            'sms_type' => SosSmsService::SMS_TYPE,
            'recipient' => '66822222222',
            'status' => 'sent',
            'booking_id' => null,
        ]);
    }

    public function test_the_text_carries_the_map_pin_and_the_time_it_was_raised(): void
    {
        $alert = SosAlert::create([
            'user_id' => $this->owner->id,
            'schedule_id' => $this->schedule->id,
            'latitude' => 16.86,
            'longitude' => 101.79,
            'message' => 'ฉันหลงทาง',
            'contact_phone' => '0811111111',
            'occurred_at' => now()->subMinutes(40),
            'status' => 'active',
        ]);

        $text = app(SosSmsService::class)->compose($alert->fresh(['user', 'schedule.trip']));

        $this->assertStringContainsString('[SOS]', $text);
        $this->assertStringContainsString('https://maps.google.com/?q=16.86000,101.79000', $text);
        $this->assertStringContainsString('ฉันหลงทาง', $text);
        $this->assertStringContainsString('โทร 0811111111', $text);
        // พิกัดที่แนบมาเก่าเท่าตอนที่กด ไม่ใช่ตำแหน่งปัจจุบัน — ต้องบอกไว้ในข้อความ
        $this->assertStringContainsString('กดเมื่อ', $text);
    }

    // ── รายชื่อสำรองที่แอปเก็บไว้ใช้ตอนออฟไลน์ ──────────────────────────────────

    public function test_the_offline_contact_list_covers_staff_driver_and_the_hotline(): void
    {
        Setting::put(SiteSettings::KEY, ['support_phone' => '026126006']);

        $staff = User::factory()->create(['name' => 'สมชาย', 'nickname' => 'พี่ชาย', 'phone' => '0822222222']);
        $this->schedule->staff()->attach($staff->id);

        $vehicle = Vehicle::create([
            'name' => 'รถตู้ 1', 'type' => 'van', 'capacity' => 10,
            'license_plate' => 'กก 1234',
            'driver_name' => 'พี่ต้น', 'driver_phone' => '0833333333',
        ]);
        $this->schedule->update(['vehicle_id' => $vehicle->id]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/emergency-contacts")
            ->assertOk();

        $this->assertSame(
            ['staff', 'driver', 'hotline'],
            array_column($response->json('data.contacts'), 'role'),
        );
        $this->assertSame('พี่ชาย', $response->json('data.contacts.0.name'));
        // ทริปในไทย — เบอร์ราชการต้องติดมาด้วย เพราะหน้าจอนี้เปิดตอนไม่มีเน็ต
        $this->assertNotEmpty($response->json('data.emergency_numbers'));
    }

    public function test_someone_outside_the_round_cannot_read_the_contact_list(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/schedules/{$this->schedule->id}/emergency-contacts")
            ->assertStatus(404);
    }

    private function enableSms(): void
    {
        config()->set('services.thaibulksms', [
            'enabled' => true,
            'api_key' => 'key',
            'api_secret' => 'secret',
            'sender' => 'LUILAYKHAO',
            'endpoint' => 'https://api.thaibulksms.test/sms',
            'credit_endpoint' => 'https://api.thaibulksms.test/credit',
            'timeout' => 10,
            'credit_type' => 'standard',
            'shorten_url' => null,
            'expire' => null,
        ]);
    }

    /** อ่านเบอร์ผู้รับออกจาก job ที่ constructor เป็น private */
    private function jobRecipient(DeliverSosSms $job): string
    {
        $property = new \ReflectionProperty($job, 'msisdn');

        return (string) $property->getValue($job);
    }
}
