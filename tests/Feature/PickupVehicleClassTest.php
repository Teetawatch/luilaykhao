<?php

namespace Tests\Feature;

use App\Models\PickupVehicleClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ประเภทรถรับ-ส่งจุดรับต่างภูมิภาค — แอดมินตั้งค่า, ลูกค้าเห็นเป็นไกด์ตอนเลือกจุดรับ
 */
class PickupVehicleClassTest extends TestCase
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

    public function test_migration_seeds_the_four_default_classes(): void
    {
        $labels = PickupVehicleClass::query()->ordered()->pluck('label')->all();

        $this->assertSame(['รถเก๋ง', 'รถ SUV', 'รถ PPV', 'รถตู้'], $labels);
    }

    public function test_public_endpoint_returns_active_classes_with_a_readable_pax_range(): void
    {
        PickupVehicleClass::query()->delete();
        PickupVehicleClass::create(['label' => 'รถเก๋ง', 'min_pax' => 1, 'max_pax' => 2, 'sort_order' => 1]);
        PickupVehicleClass::create(['label' => 'รถตู้', 'min_pax' => 6, 'max_pax' => null, 'sort_order' => 2]);
        PickupVehicleClass::create(['label' => 'รถบัส', 'min_pax' => 20, 'is_active' => false, 'sort_order' => 3]);

        $data = $this->getJson('/api/v1/pickup-vehicle-classes')->assertOk()->json('data');

        $this->assertSame(['รถเก๋ง', 'รถตู้'], array_column($data, 'label'));
        $this->assertSame('1-2 ท่าน', $data[0]['pax_label']);
        $this->assertSame('6 ท่านขึ้นไป', $data[1]['pax_label']);
    }

    public function test_single_seat_range_reads_as_one_number(): void
    {
        $ppv = PickupVehicleClass::create(['label' => 'รถ PPV', 'min_pax' => 5, 'max_pax' => 5]);

        $this->assertSame('5 ท่าน', $ppv->paxLabel());
    }

    public function test_covers_treats_an_empty_max_as_unbounded(): void
    {
        $van = PickupVehicleClass::create(['label' => 'รถตู้', 'min_pax' => 6, 'max_pax' => null]);
        $suv = PickupVehicleClass::create(['label' => 'SUV', 'min_pax' => 3, 'max_pax' => 4]);

        $this->assertTrue($van->covers(6));
        $this->assertTrue($van->covers(40));
        $this->assertFalse($van->covers(5));

        $this->assertTrue($suv->covers(4));
        $this->assertFalse($suv->covers(5));
    }

    public function test_admin_can_create_a_class(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/pickup-vehicle-classes', [
                'label' => 'รถกระบะแค็บ',
                'min_pax' => 2,
                'max_pax' => 3,
                'image_url' => 'https://media.luilaykhao.com/pickup-points/pickup-truck.jpg',
                'note' => 'เดินทาง 2-3 ท่าน',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('pickup_vehicle_classes', [
            'label' => 'รถกระบะแค็บ',
            'min_pax' => 2,
            'max_pax' => 3,
            'is_active' => true,
        ]);
    }

    public function test_max_pax_below_min_pax_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/pickup-vehicle-classes', [
                'label' => 'รถผิดช่วง',
                'min_pax' => 6,
                'max_pax' => 2,
            ])
            ->assertStatus(422);
    }

    public function test_update_validates_the_range_against_the_stored_value(): void
    {
        $class = PickupVehicleClass::create(['label' => 'SUV', 'min_pax' => 3, 'max_pax' => 4]);

        // ส่งมาแค่ max_pax — ต้องเทียบกับ min_pax ที่เก็บไว้ ไม่ใช่ปล่อยผ่านเพราะไม่ได้ส่งมาด้วย
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/pickup-vehicle-classes/{$class->id}", ['max_pax' => 1])
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/pickup-vehicle-classes/{$class->id}", ['max_pax' => 6])
            ->assertOk();

        $this->assertSame(6, $class->fresh()->max_pax);
    }

    public function test_clearing_max_pax_makes_the_class_unbounded(): void
    {
        $class = PickupVehicleClass::create(['label' => 'รถตู้', 'min_pax' => 6, 'max_pax' => 9]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/pickup-vehicle-classes/{$class->id}", ['max_pax' => null])
            ->assertOk();

        $this->assertNull($class->fresh()->max_pax);
    }

    public function test_admin_can_reorder_and_delete(): void
    {
        PickupVehicleClass::query()->delete();
        $a = PickupVehicleClass::create(['label' => 'ก', 'min_pax' => 1, 'sort_order' => 0]);
        $b = PickupVehicleClass::create(['label' => 'ข', 'min_pax' => 2, 'sort_order' => 1]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/pickup-vehicle-classes/reorder', ['ids' => [$b->id, $a->id]])
            ->assertOk();

        $this->assertSame(['ข', 'ก'], PickupVehicleClass::query()->ordered()->pluck('label')->all());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/pickup-vehicle-classes/{$a->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pickup_vehicle_classes', ['id' => $a->id]);
    }

    public function test_public_list_reflects_an_admin_edit_immediately(): void
    {
        $class = PickupVehicleClass::query()->ordered()->first();

        $this->getJson('/api/v1/pickup-vehicle-classes')->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/pickup-vehicle-classes/{$class->id}", ['label' => 'รถเก๋งซีดาน'])
            ->assertOk();

        $labels = array_column($this->getJson('/api/v1/pickup-vehicle-classes')->json('data'), 'label');

        $this->assertContains('รถเก๋งซีดาน', $labels);
    }

    public function test_guests_cannot_manage_classes(): void
    {
        $this->postJson('/api/v1/admin/pickup-vehicle-classes', [
            'label' => 'รถลับ',
            'min_pax' => 1,
        ])->assertUnauthorized();
    }
}
