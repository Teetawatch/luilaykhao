<?php

namespace Tests\Feature;

use App\Models\BookingPassenger;
use App\Models\SavedTraveller;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use App\Rules\ThaiName;
use App\Support\PermitFormFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ชื่อ / นามสกุลแยกช่อง — แอปและเว็บรุ่นใหม่ส่งสองช่อง ชื่อเต็ม (`name`) ยังถูก
 * ประกอบให้ทุกที่ที่อ่านอยู่แล้ว ส่วนช่องทางเก่าที่ส่งช่องเดียวยังจองได้เหมือนเดิม
 */
class PassengerNamePartsTest extends TestCase
{
    use RefreshDatabase;

    private TripSchedule $schedule;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $trip = Trip::create([
            'title' => 'ภูกระดึง', 'slug' => 'name-parts-'.uniqid(), 'type' => 'trekking', 'location' => 'Loei',
            'difficulty' => 'easy', 'duration_days' => 2, 'max_participants' => 10,
            'price_per_person' => 1500, 'status' => 'active',
        ]);
        $this->schedule = TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now('Asia/Bangkok')->addMonth()->toDateString(),
            'return_date' => now('Asia/Bangkok')->addMonth()->addDay()->toDateString(),
            'total_seats' => 10, 'booked_seats' => 0, 'transport_type' => 'van', 'status' => 'open',
        ]);
        $this->user = User::factory()->create();
    }

    private function book(array $passenger)
    {
        return $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/bookings', [
            'schedule_id' => $this->schedule->id,
            'passengers' => [array_merge([
                'title' => 'นาย', 'nickname' => 'ชาย', 'id_card' => '1234567890123',
                'phone' => '0810000000', 'blood_group' => 'A', 'halal_food' => false,
                'emergency_contact' => 'แม่', 'emergency_phone' => '0820000000',
            ], $passenger)],
        ]);
    }

    public function test_first_and_last_name_are_stored_and_the_full_name_is_composed(): void
    {
        $this->book(['first_name' => ' สมหญิง ', 'last_name' => 'ณ  อยุธยา'])->assertCreated();

        $p = BookingPassenger::firstOrFail();
        $this->assertSame('สมหญิง ณ อยุธยา', $p->name);
        $this->assertSame('สมหญิง', $p->first_name);
        $this->assertSame('ณ อยุธยา', $p->last_name);
    }

    public function test_the_thai_name_rule_still_applies_to_the_split_fields(): void
    {
        $this->book(['first_name' => 'Somchai', 'last_name' => 'Jaidee'])
            ->assertStatus(422)
            ->assertJsonFragment(['passengers.0.name' => [ThaiName::NOT_THAI]]);

        $this->book(['first_name' => 'สมชาย', 'last_name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('passengers.0.last_name');
    }

    public function test_old_clients_that_send_one_name_still_book(): void
    {
        $this->book(['name' => 'สมชาย ใจดี'])->assertCreated();

        $p = BookingPassenger::firstOrFail();
        $this->assertNull($p->first_name);
        $this->assertSame(['สมชาย', 'ใจดี'], $p->nameParts());
    }

    public function test_a_multi_word_passport_name_keeps_its_real_surname(): void
    {
        $this->book([
            'first_name' => 'Anna Maria', 'last_name' => 'Schmidt',
            'first_name_en' => 'ANNA MARIA', 'last_name_en' => 'SCHMIDT',
            'nationality' => 'DE', 'id_card' => null, 'passport_no' => 'C01X00T47',
            'phone' => '+4915112345678',
        ])->assertCreated();

        $p = BookingPassenger::firstOrFail();
        $this->assertSame('Anna Maria Schmidt', $p->name);
        $this->assertSame('ANNA MARIA SCHMIDT', $p->name_en);
        $this->assertSame(['ANNA MARIA', 'SCHMIDT'], $p->nameEnParts());

        // แบบฟอร์มอุทยาน/ประกันไม่เดาจากช่องว่างแรกอีกแล้ว
        $values = PermitFormFields::values($p, $this->schedule->fresh('trip'), 1, Carbon::now('Asia/Bangkok'));
        $this->assertSame('Anna Maria', $values['first_name']);
        $this->assertSame('Schmidt', $values['last_name']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/bookings/'.$p->booking->booking_ref)
            ->assertOk()
            ->assertJsonPath('data.passengers.0.first_name', 'Anna Maria')
            ->assertJsonPath('data.passengers.0.last_name', 'Schmidt')
            ->assertJsonPath('data.passengers.0.last_name_en', 'SCHMIDT');
    }

    public function test_editing_only_the_full_name_drops_stale_parts(): void
    {
        $this->book(['first_name' => 'สมชาย', 'last_name' => 'ใจดี'])->assertCreated();
        $p = BookingPassenger::firstOrFail();

        // แอดมิน / ลิงก์กรอกเอง / รับต่อที่นั่ง แก้แค่ชื่อเต็ม
        $p->update(['name' => 'มานี มีตา']);
        $p->refresh();
        $this->assertNull($p->first_name);
        $this->assertSame(['มานี', 'มีตา'], $p->nameParts());

        // แก้ชื่อเต็มเป็นค่าเดิม (ส่งกลับมาพร้อมฟอร์ม) ไม่ล้างของที่ยังตรงกัน
        $p->update(['first_name' => 'มานี', 'last_name' => 'มีตา']);
        $p->update(['name' => 'มานี  มีตา']);
        $this->assertSame('มานี', $p->fresh()->first_name);
    }

    public function test_saved_travellers_keep_the_split_name(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/saved-travellers', [
                'first_name' => 'สมหญิง', 'last_name' => 'ณ อยุธยา', 'nationality' => 'TH',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'สมหญิง ณ อยุธยา')
            ->assertJsonPath('data.last_name', 'ณ อยุธยา');

        $this->assertSame('ณ อยุธยา', SavedTraveller::firstOrFail()->last_name);
    }
}
