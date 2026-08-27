<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\VehicleDriverService;
use App\Support\MediaDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ทะเบียนคนขับเก็บข้อมูลคนขับครบในที่เดียว และรถแค่ "ผูก" แล้วได้ทุกอย่างมา
 * รวมถึงรหัสส่ง GPS ที่ตั้งครั้งเดียวใช้ได้ทุกคันที่คนขับคนนั้นขับ
 */
class DriverProfileAndSharedPinTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    private function makeVehicle(Driver $driver, string $name): Vehicle
    {
        return Vehicle::create([
            'name' => $name,
            'type' => 'van',
            'capacity' => 12,
            'driver_id' => $driver->id,
            'driver_name' => $driver->name,
            'driver_phone' => $driver->phone,
        ]);
    }

    public function test_registry_stores_the_full_driver_profile(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/drivers', [
                'name' => 'สมชาย ใจดี',
                'phone' => '081-111-2222',
                'line_id' => 'somchai_d',
                'license_number' => 'บ1234567',
                'license_type' => 'ท.2',
                'license_expires_at' => now('Asia/Bangkok')->addYear()->toDateString(),
                'id_card' => '1103700123456',
                'birth_date' => '1985-04-12',
                'address' => '99/1 ถนนสุขุมวิท กรุงเทพฯ',
                'emergency_contact' => 'สมหญิง ใจดี',
                'emergency_phone' => '089-999-0000',
                'notes' => 'ชำนาญเส้นทางภาคเหนือ',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.license_type', 'ท.2')
            ->assertJsonPath('data.id_card', '1103700123456')
            ->assertJsonPath('data.birth_date', '1985-04-12')
            ->assertJsonPath('data.emergency_contact', 'สมหญิง ใจดี')
            ->assertJsonPath('data.emergency_phone', '089-999-0000')
            ->assertJsonPath('data.line_id', 'somchai_d')
            ->assertJsonPath('data.license_status', 'valid');

        // เลขบัตรประชาชนต้องถูกเข้ารหัสในฐานข้อมูล เหมือน User.id_card
        $stored = DB::table('drivers')->where('id', $response->json('data.id'))->value('id_card');
        $this->assertNotSame('1103700123456', $stored);
        $this->assertSame('1103700123456', Driver::find($response->json('data.id'))->id_card);
    }

    public function test_licence_expiry_is_reported_as_a_status_not_just_a_date(): void
    {
        $cases = [
            ['expires' => now('Asia/Bangkok')->subDay(), 'status' => 'expired'],
            ['expires' => now('Asia/Bangkok')->addDays(30), 'status' => 'expiring'],
            ['expires' => now('Asia/Bangkok')->addDays(200), 'status' => 'valid'],
            ['expires' => null, 'status' => 'unknown'],
        ];

        foreach ($cases as $case) {
            $driver = Driver::create([
                'name' => 'คนขับ '.$case['status'],
                'license_expires_at' => $case['expires']?->toDateString(),
            ]);

            $this->assertSame($case['status'], $driver->licenseStatus());
        }

        $rows = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/drivers')
            ->assertOk()
            ->json('data');

        $expired = collect($rows)->firstWhere('name', 'คนขับ expired');
        $this->assertSame('expired', $expired['license_status']);
        $this->assertSame(-1, $expired['license_days_left']);
    }

    public function test_licence_photo_is_stored_privately_and_can_be_removed(): void
    {
        Storage::fake(MediaDisk::slipDisk());
        $driver = Driver::create(['name' => 'สมชาย']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/drivers/{$driver->id}/license-photo", [
                'license_photo' => UploadedFile::fake()->image('licence.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('data.has_license_photo', true);

        $path = $driver->fresh()->license_photo;
        $this->assertStringStartsWith('driver-documents/', $path);
        Storage::disk(MediaDisk::slipDisk())->assertExists($path);
        // เอกสารประจำตัวต้องไม่ไปอยู่บนดิสก์สาธารณะที่คลังมีเดียใช้
        $this->assertNotSame(MediaDisk::name(), MediaDisk::slipDisk());
        $this->assertNotNull(MediaDisk::privateUrl($path));

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/drivers/{$driver->id}/license-photo")
            ->assertOk()
            ->assertJsonPath('data.has_license_photo', false);

        Storage::disk(MediaDisk::slipDisk())->assertMissing($path);
        $this->assertNull($driver->fresh()->license_photo);
    }

    public function test_one_pin_covers_every_vehicle_the_driver_is_linked_to(): void
    {
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222']);
        $first = $this->makeVehicle($driver, 'รถตู้ 1');
        $second = $this->makeVehicle($driver, 'รถตู้ 2');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$first->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk()
            ->assertJsonPath('data.has_driver_pin', true);

        $driver->refresh();
        $this->assertNotNull($driver->pin_user_id);
        $this->assertTrue($driver->hasPin());

        // คันที่สองต้องใช้บัญชีเดียวกัน ไม่ต้องตั้งรหัสใหม่
        $this->assertSame($driver->pin_user_id, $first->fresh()->driver_user_id);
        $this->assertSame($driver->pin_user_id, $second->fresh()->driver_user_id);

        // และล็อกอินด้วยรหัสเดียวต้องเห็นงานของทั้งสองคัน
        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '4521'])
            ->assertOk();
    }

    public function test_a_newly_linked_vehicle_inherits_the_drivers_pin(): void
    {
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222']);
        $existing = $this->makeVehicle($driver, 'รถตู้ 1');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$existing->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        // เพิ่มรถคันใหม่แล้วผูกคนขับคนเดิม — ต้องมีรหัสส่ง GPS ให้เลยตั้งแต่แถวแรก
        $created = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/vehicles', [
                'name' => 'รถตู้ 3', 'type' => 'van', 'capacity' => 12, 'driver_id' => $driver->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.has_driver_pin', true)
            ->json('data');

        $this->assertSame($driver->fresh()->pin_user_id, Vehicle::find($created['id'])->driver_user_id);
    }

    public function test_clearing_the_pin_clears_it_for_all_of_that_drivers_vehicles(): void
    {
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222']);
        $first = $this->makeVehicle($driver, 'รถตู้ 1');
        $second = $this->makeVehicle($driver, 'รถตู้ 2');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$first->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$second->id}/driver-pin")
            ->assertOk();

        $this->assertFalse($driver->fresh()->hasPin());
        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '4521'])
            ->assertUnauthorized();
    }

    public function test_setting_one_pin_retires_the_separate_pins_the_vans_used_before(): void
    {
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222']);
        $first = $this->makeVehicle($driver, 'รถตู้ 1');
        $second = $this->makeVehicle($driver, 'รถตู้ 2');

        // สภาพเดิมก่อนย้าย PIN มาอยู่กับคน: คนขับคนเดียวแต่จำรหัสคนละตัวต่อคัน
        app(VehicleDriverService::class)->setPin($first, '1111');
        $second->forceFill(['driver_id' => null])->save();
        app(VehicleDriverService::class)->setPin($second->fresh(), '2222');
        $second->forceFill(['driver_id' => $driver->id])->save();

        // ตั้งรหัสใหม่ครั้งเดียวให้คนขับ — ทุกคันต้องมาใช้รหัสนี้
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$first->id}/driver-pin", ['driver_pin' => '3333'])
            ->assertOk();

        $accountId = $driver->fresh()->pin_user_id;
        $this->assertSame($accountId, $first->fresh()->driver_user_id);
        $this->assertSame($accountId, $second->fresh()->driver_user_id);

        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '3333'])->assertOk();
        // รหัสเก่าของคันที่ถูกดึงมารวมต้องถูกคืน ไม่ค้างล็อกอินได้แบบไม่มีรถ
        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '2222'])->assertUnauthorized();
    }

    public function test_deleting_one_van_leaves_the_drivers_other_vans_working(): void
    {
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222']);
        $first = $this->makeVehicle($driver, 'รถตู้ 1');
        $second = $this->makeVehicle($driver, 'รถตู้ 2');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$first->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/vehicles/{$first->id}")
            ->assertOk();

        // รหัสเป็นของคน ไม่ใช่ของรถ — คันที่เหลือต้องยังส่ง GPS ได้
        $this->assertTrue($driver->fresh()->hasPin());
        $this->assertSame($driver->fresh()->pin_user_id, $second->fresh()->driver_user_id);
        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '4521'])->assertOk();
    }

    public function test_linking_a_registry_driver_releases_the_vans_own_old_pin(): void
    {
        $standalone = Vehicle::create([
            'name' => 'รถตู้เดี่ยว', 'type' => 'van', 'capacity' => 12, 'driver_name' => 'สมปอง',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$standalone->id}/driver-pin", ['driver_pin' => '7788'])
            ->assertOk();

        $oldAccountId = $standalone->fresh()->driver_user_id;

        // ย้ายรถไปผูกกับคนขับในทะเบียนที่มีรหัสของตัวเองอยู่แล้ว
        $driver = Driver::create(['name' => 'สมชาย', 'phone' => '081-111-2222']);
        $other = $this->makeVehicle($driver, 'รถตู้ 1');
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$other->id}/driver-pin", ['driver_pin' => '4521'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$standalone->id}", [
                'name' => 'รถตู้เดี่ยว', 'type' => 'van', 'capacity' => 12, 'driver_id' => $driver->id,
            ])
            ->assertOk();

        $this->assertSame($driver->fresh()->pin_user_id, $standalone->fresh()->driver_user_id);
        // รหัสเดิมของรถคันนี้ต้องถูกคืน ไม่ค้างจองเลข PIN ไว้โดยบัญชีที่ไม่มีใครใช้
        $this->assertNull(User::find($oldAccountId)->driver_pin_hash);
        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '7788'])->assertUnauthorized();
        $this->postJson('/api/v1/driver/pin-login', ['driver_pin' => '4521'])->assertOk();
    }

    public function test_a_vehicle_without_a_registry_driver_still_keeps_its_own_pin(): void
    {
        $standalone = Vehicle::create([
            'name' => 'รถตู้เดี่ยว', 'type' => 'van', 'capacity' => 12,
            'driver_name' => 'สมปอง', 'driver_phone' => '086-000-0000',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/vehicles/{$standalone->id}/driver-pin", ['driver_pin' => '7788'])
            ->assertOk()
            ->assertJsonPath('data.has_driver_pin', true);

        $this->assertNotNull($standalone->fresh()->driver_user_id);
    }

    public function test_vehicle_payload_carries_the_whole_driver_profile(): void
    {
        $driver = Driver::create([
            'name' => 'สมชาย',
            'phone' => '081-111-2222',
            'license_number' => 'บ1234567',
            'license_type' => 'ท.2',
            'license_expires_at' => now('Asia/Bangkok')->addDays(10)->toDateString(),
            'emergency_contact' => 'สมหญิง',
            'emergency_phone' => '089-999-0000',
        ]);
        $this->makeVehicle($driver, 'รถตู้ 1');

        $row = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/vehicles')
            ->assertOk()
            ->json('data.0');

        // หน้ายานพาหนะต้องแสดงข้อมูลคนขับได้ครบโดยไม่ต้องยิงขอเพิ่ม
        $this->assertSame('บ1234567', $row['driver']['license_number']);
        $this->assertSame('ท.2', $row['driver']['license_type']);
        $this->assertSame('expiring', $row['driver']['license_status']);
        $this->assertSame('สมหญิง', $row['driver']['emergency_contact']);
        $this->assertSame('089-999-0000', $row['driver']['emergency_phone']);
    }

    public function test_driver_endpoints_stay_admin_only(): void
    {
        $driver = Driver::create(['name' => 'สมชาย']);

        // ยังไม่ล็อกอินก่อน — actingAs ค้างอยู่ตลอดเทสต์ ถ้าสลับลำดับจะได้ 403 แทน 401
        $this->postJson("/api/v1/admin/drivers/{$driver->id}/license-photo")->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/admin/drivers/{$driver->id}/license-photo", [
                'license_photo' => UploadedFile::fake()->image('licence.jpg'),
            ])
            ->assertForbidden();
    }
}
