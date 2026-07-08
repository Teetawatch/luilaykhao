<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * คำถามที่พบบ่อย (FAQ) ต่อทริป — admin แก้ผ่าน API, หน้าเว็บสาธารณะอ่านได้
 */
class TripFaqTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        Category::create(['name' => 'เดินป่า', 'slug' => 'trekking']);
    }

    private function makeTrip(array $overrides = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'ภูกระดึง',
            'slug' => 'trip-'.uniqid(),
            'type' => 'trekking',
            'location' => 'เลย',
            'region' => 'north',
            'difficulty' => 'medium',
            'duration_days' => 2,
            'max_participants' => 20,
            'price_per_person' => 2500,
            'status' => 'active',
        ], $overrides));
    }

    public function test_public_show_returns_faqs(): void
    {
        $trip = $this->makeTrip([
            'faqs' => [
                ['question' => 'ต้องเตรียมเงินสดไปเท่าไหร่?', 'answer' => 'ประมาณ 500 บาท'],
            ],
        ]);

        $res = $this->getJson("/api/v1/trips/{$trip->slug}")->assertOk();

        $res->assertJsonPath('data.faqs.0.question', 'ต้องเตรียมเงินสดไปเท่าไหร่?');
        $res->assertJsonPath('data.faqs.0.answer', 'ประมาณ 500 บาท');
    }

    public function test_show_returns_empty_array_when_no_faqs(): void
    {
        $trip = $this->makeTrip();

        $this->getJson("/api/v1/trips/{$trip->slug}")
            ->assertOk()
            ->assertJsonPath('data.faqs', []);
    }

    public function test_admin_can_save_faqs(): void
    {
        $trip = $this->makeTrip();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", [
                'title' => $trip->title,
                'type' => 'trekking',
                'location' => $trip->location,
                'region' => $trip->region,
                'difficulty' => 'medium',
                'duration_days' => 2,
                'max_participants' => 20,
                'price_per_person' => 2500,
                'faqs' => [
                    ['question' => 'มีที่จอดรถไหม?', 'answer' => 'มีที่จอดรถฟรีที่จุดนัดพบ'],
                    ['question' => 'เด็กไปได้ไหม?', 'answer' => 'ได้ แต่แนะนำอายุ 7 ปีขึ้นไป'],
                ],
            ])
            ->assertOk();

        $this->assertCount(2, $trip->fresh()->faqs);
        $this->assertSame('มีที่จอดรถไหม?', $trip->fresh()->faqs[0]['question']);
    }

    public function test_admin_rejects_faq_missing_answer(): void
    {
        $trip = $this->makeTrip();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/trips/{$trip->id}", [
                'title' => $trip->title,
                'type' => 'trekking',
                'location' => $trip->location,
                'region' => $trip->region,
                'difficulty' => 'medium',
                'duration_days' => 2,
                'max_participants' => 20,
                'price_per_person' => 2500,
                'faqs' => [
                    ['question' => 'คำถามไม่มีคำตอบ'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('faqs.0.answer');
    }
}
