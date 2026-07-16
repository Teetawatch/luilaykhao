<?php

namespace Tests\Feature;

use App\Jobs\BroadcastIncidentReport;
use App\Models\Booking;
use App\Models\Incident;
use App\Models\Trip;
use App\Models\TripSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncidentReportTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(): TripSchedule
    {
        $trip = Trip::create([
            'title' => 'Incident Trip',
            'slug' => 'incident-trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'Khao Yai',
            'difficulty' => 'easy',
            'duration_days' => 2,
            'max_participants' => 10,
            'price_per_person' => 1500,
            'status' => 'active',
        ]);

        return TripSchedule::create([
            'trip_id' => $trip->id,
            'departure_date' => now()->addDay()->toDateString(),
            'return_date' => now()->addDays(2)->toDateString(),
            'total_seats' => 10,
            'booked_seats' => 1,
            'transport_type' => 'van',
            'status' => 'open',
        ]);
    }

    private function assignedStaff(TripSchedule $schedule): User
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $schedule->staff()->attach($staff->id, ['assigned_by' => $staff->id]);

        return $staff;
    }

    public function test_assigned_staff_can_report_an_incident(): void
    {
        Bus::fake();

        $schedule = $this->makeSchedule();
        $staff = $this->assignedStaff($schedule);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/incidents", [
                'passenger_name' => 'สมชาย ใจดี',
                'severity' => 'severe',
                'description' => 'ลื่นล้มระหว่างเดินป่า ข้อเท้าบวม',
                'latitude' => 14.44,
                'longitude' => 101.37,
            ])
            ->assertOk()
            ->assertJsonPath('data.severity', 'severe')
            ->assertJsonPath('data.severity_label', 'รุนแรง')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.reported_by_name', $staff->name);

        $this->assertDatabaseHas('incidents', [
            'schedule_id' => $schedule->id,
            'reported_by' => $staff->id,
            'passenger_name' => 'สมชาย ใจดี',
            'severity' => 'severe',
            'status' => 'open',
        ]);

        Bus::assertDispatched(BroadcastIncidentReport::class);
    }

    public function test_unassigned_user_cannot_report_an_incident(): void
    {
        Bus::fake();

        $schedule = $this->makeSchedule();
        $outsider = User::factory()->create();

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/incidents", [
                'severity' => 'minor',
                'description' => 'ทดสอบ',
            ])
            ->assertStatus(403);

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_severity_must_be_valid(): void
    {
        Bus::fake();

        $schedule = $this->makeSchedule();
        $staff = $this->assignedStaff($schedule);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/schedules/{$schedule->id}/incidents", [
                'severity' => 'catastrophic',
                'description' => 'ระดับความรุนแรงไม่ถูกต้อง',
            ])
            ->assertStatus(422);
    }

    public function test_staff_can_resolve_an_incident(): void
    {
        Bus::fake();

        $schedule = $this->makeSchedule();
        $staff = $this->assignedStaff($schedule);

        $incident = Incident::create([
            'schedule_id' => $schedule->id,
            'reported_by' => $staff->id,
            'severity' => 'moderate',
            'description' => 'เหตุทดสอบ',
            'status' => 'open',
        ]);

        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/v1/driver/incidents/{$incident->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_by_name', $staff->name);

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'status' => 'resolved',
            'resolved_by' => $staff->id,
        ]);
    }

    public function test_admin_can_list_and_resolve_incidents(): void
    {
        Bus::fake();
        Role::findOrCreate('admin');

        $schedule = $this->makeSchedule();
        $staff = $this->assignedStaff($schedule);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $incident = Incident::create([
            'schedule_id' => $schedule->id,
            'reported_by' => $staff->id,
            'severity' => 'severe',
            'description' => 'ลื่นล้ม',
            'status' => 'open',
        ]);

        // Admin list (with trip context) — filter to open.
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/incidents?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incident->id)
            ->assertJsonPath('data.0.trip_title', 'Incident Trip')
            ->assertJsonPath('data.0.status', 'open');

        // Admin closes the case.
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/incidents/{$incident->id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');

        $this->assertDatabaseHas('incidents', [
            'id' => $incident->id,
            'status' => 'resolved',
            'resolved_by' => $admin->id,
        ]);
    }

    public function test_non_admin_cannot_use_admin_incident_list(): void
    {
        Role::findOrCreate('staff');
        $staff = User::factory()->create();
        $staff->assignRole('staff');

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/admin/incidents')
            ->assertStatus(403);
    }

    public function test_report_notifies_ops_and_staff_but_not_the_reporter(): void
    {
        Role::findOrCreate('admin');

        $schedule = $this->makeSchedule();
        $reporter = $this->assignedStaff($schedule);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // A confirmed customer on this trip must NOT be notified.
        $customer = User::factory()->create();
        Booking::create([
            'booking_ref' => Booking::generateRef(),
            'user_id' => $customer->id,
            'schedule_id' => $schedule->id,
            'status' => 'confirmed',
            'total_amount' => 1500,
            'paid_amount' => 1500,
        ]);

        $incident = Incident::create([
            'schedule_id' => $schedule->id,
            'reported_by' => $reporter->id,
            'severity' => 'critical',
            'description' => 'เหตุวิกฤต',
            'status' => 'open',
        ]);

        (new BroadcastIncidentReport($incident->id))->handle();

        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $admin->id,
            'type' => 'incident_report',
        ]);
        // Reporter and the customer are not notified.
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $reporter->id,
            'type' => 'incident_report',
        ]);
        $this->assertDatabaseMissing('smart_notifications', [
            'user_id' => $customer->id,
            'type' => 'incident_report',
        ]);
    }
}
