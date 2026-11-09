<?php

namespace Tests\Feature;

use App\Models\SmartNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * รายการแจ้งเตือนของลูกค้า — หน้าแรก + "เลื่อนโหลดเพิ่ม" ด้วย before_id
 * และเลขที่ยังไม่อ่านที่แอปใช้เป็นป้ายบนไอคอน
 */
class NotificationListTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<SmartNotification> เรียงจากเก่าไปใหม่ */
    private function notify(User $user, int $count, bool $read = false): array
    {
        $made = [];
        for ($i = 0; $i < $count; $i++) {
            $made[] = SmartNotification::create([
                'user_id' => $user->id,
                'type' => 'booking_confirmed',
                'title' => 'แจ้งเตือน '.$i,
                'body' => '...',
                'data' => [],
                'is_read' => $read,
            ]);
        }

        return $made;
    }

    public function test_the_first_page_is_newest_first_and_says_more_remain(): void
    {
        $user = User::factory()->create();
        $made = $this->notify($user, 5);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.0.id', $made[4]->id)
            ->assertJsonPath('data.1.id', $made[3]->id)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.total', 5);
    }

    public function test_before_id_continues_after_the_last_row_even_when_new_ones_arrive(): void
    {
        $user = User::factory()->create();
        $made = $this->notify($user, 5);

        // แถวใหม่สองแถวเข้ามาหลังโหลดหน้าแรก — page=2 จะได้ $made[4] ซ้ำ
        $this->notify($user, 2);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications?per_page=2&before_id='.$made[3]->id)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $made[2]->id)
            ->assertJsonPath('data.1.id', $made[1]->id)
            // ที่เหลือหลังแถวนี้ = $made[0] อีกหนึ่ง
            ->assertJsonPath('meta.last_page', 2);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications?per_page=2&before_id='.$made[1]->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $made[0]->id)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_never_lists_someone_elses_notifications(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->notify($other, 3);
        $mine = $this->notify($me, 1);

        $this->actingAs($me, 'sanctum')
            ->getJson('/api/v1/notifications?before_id=999999')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine[0]->id);
    }

    public function test_per_page_is_capped(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications?per_page=5000')
            ->assertUnprocessable();
    }

    public function test_unread_count_counts_every_unread_row_not_just_a_page(): void
    {
        $user = User::factory()->create();
        $this->notify($user, 30);
        $this->notify($user, 4, read: true);
        $this->notify(User::factory()->create(), 7);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.count', 30);
    }
}
