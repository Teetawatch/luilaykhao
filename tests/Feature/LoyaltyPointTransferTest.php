<?php

namespace Tests\Feature;

use App\Jobs\ExpireLoyaltyPointsJob;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointTransfer;
use App\Models\LoyaltyTransaction;
use App\Models\SmartNotification;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * โอนแต้มให้เพื่อน — ย้ายแต้มพร้อมวันหมดอายุเดิม มีเพดานต่อวัน และแจ้งผู้รับ
 */
class LoyaltyPointTransferTest extends TestCase
{
    use RefreshDatabase;

    private function userWithPoints(int $points, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);

        if ($points > 0) {
            app(LoyaltyService::class)->credit($user->id, $points, 'แต้มทดสอบ');
        }

        return $user;
    }

    public function test_sender_can_transfer_points_to_a_friend_by_phone(): void
    {
        $sender = $this->userWithPoints(200, ['name' => 'ต้น ทดสอบ']);
        $friend = User::factory()->create(['name' => 'สมชาย ใจดี', 'phone' => '081-234-5678']);
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/loyalty/transfers', [
            'recipient' => '0812345678',
            'points' => 50,
            'message' => 'ขอบคุณที่ชวนไปเที่ยว',
        ])
            ->assertCreated()
            ->assertJsonPath('data.points', 50)
            ->assertJsonPath('data.recipient_name', 'สมชาย ใ.')
            ->assertJsonPath('data.points_remaining', 150);

        $this->assertSame(150, LoyaltyAccount::forUser($sender->id)->points);
        $recipientAccount = LoyaltyAccount::forUser($friend->id);
        $this->assertSame(50, $recipientAccount->points);
        // แต้มที่ได้จากเพื่อนไม่ใช่แต้มจากการเดินทาง
        $this->assertSame(0, $recipientAccount->lifetime_points);

        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $sender->id, 'type' => 'transfer', 'points' => -50,
        ]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $friend->id, 'type' => 'earn', 'points' => 50, 'points_remaining' => 50,
        ]);
        $this->assertDatabaseHas('smart_notifications', [
            'user_id' => $friend->id, 'type' => 'loyalty_points_received',
        ]);
    }

    public function test_transferred_points_keep_their_original_expiry_date(): void
    {
        $this->travelTo('2026-01-10 10:00:00');
        $sender = $this->userWithPoints(30);
        $this->travelTo('2026-06-10 10:00:00');
        app(LoyaltyService::class)->credit($sender->id, 100, 'ก้อนใหม่');
        $friend = User::factory()->create();

        $this->travelTo('2026-07-01 10:00:00');
        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/loyalty/transfers', [
            'recipient' => $friend->email,
            'points' => 50,
        ])->assertCreated();

        // ก้อนที่ใกล้หมดอายุถูกโอนก่อน: 30 แต้มจาก ม.ค. + 20 แต้มจาก มิ.ย.
        $lots = LoyaltyTransaction::where('user_id', $friend->id)->where('type', 'earn')
            ->orderBy('expires_at')->get();
        $this->assertCount(2, $lots);
        $this->assertSame(30, (int) $lots[0]->points_remaining);
        $this->assertSame('2028-01-10', $lots[0]->expires_at->toDateString());
        $this->assertSame(20, (int) $lots[1]->points_remaining);
        $this->assertSame('2028-06-10', $lots[1]->expires_at->toDateString());

        // ครบกำหนดก้อนแรก ผู้รับเสีย 30 แต้มเหมือนที่ผู้โอนจะเสีย — โอนไม่ได้ต่ออายุแต้ม
        $this->travelTo('2028-01-11 10:00:00');
        (new ExpireLoyaltyPointsJob)->handle(app(LoyaltyService::class));
        $this->assertSame(20, LoyaltyAccount::forUser($friend->id)->points);
        $this->assertSame(80, LoyaltyAccount::forUser($sender->id)->points);
    }

    public function test_cannot_transfer_more_than_balance_or_below_minimum_or_to_self(): void
    {
        $sender = $this->userWithPoints(40);
        $sender->forceFill(['referral_code' => 'SELFCODE'])->save();
        $friend = User::factory()->create(['referral_code' => 'FRND2345']);
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'frnd2345', 'points' => 41])
            ->assertStatus(422);
        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'FRND2345', 'points' => 5])
            ->assertStatus(422);
        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'SELFCODE', 'points' => 10])
            ->assertStatus(422);

        $this->assertSame(40, LoyaltyAccount::forUser($sender->id)->points);
        $this->assertSame(0, LoyaltyPointTransfer::count());
        $this->assertSame(0, LoyaltyAccount::forUser($friend->id)->points);
    }

    public function test_daily_cap_limits_points_sent_per_thai_day(): void
    {
        config([
            'loyalty.transfer.daily_max_points' => 100,
            'loyalty.transfer.daily_max_count' => 5,
        ]);
        $this->travelTo('2026-10-30 10:00:00'); // 17:00 เวลาไทย
        $sender = $this->userWithPoints(500);
        $friend = User::factory()->create(['referral_code' => 'FRND2345']);
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'FRND2345', 'points' => 70])->assertCreated();
        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'FRND2345', 'points' => 40])
            ->assertStatus(422)
            ->assertJsonPath('message', 'วันนี้โอนได้อีกไม่เกิน 30 แต้ม');

        $this->getJson('/api/v1/loyalty/transfers')
            ->assertOk()
            ->assertJsonPath('data.limits.remaining_today_points', 30)
            ->assertJsonPath('data.history.0.direction', 'out');

        // 00:30 เวลาไทยของวันถัดไป (ยังเป็นวันเดิมใน UTC) — เพดานเริ่มใหม่แล้ว
        $this->travelTo('2026-10-30 17:30:00');
        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'FRND2345', 'points' => 100])->assertCreated();
    }

    public function test_lookup_shows_masked_name_only(): void
    {
        $sender = $this->userWithPoints(100);
        User::factory()->create(['name' => 'นางสาว มาลี ศรีสุข', 'phone' => '0899999999', 'avatar' => null]);
        Sanctum::actingAs($sender);

        $response = $this->postJson('/api/v1/loyalty/transfers/lookup', ['recipient' => '+66899999999'])
            ->assertOk()
            ->assertJsonPath('data.display_name', 'มาลี ศ.')
            ->assertJsonPath('data.avatar_url', null);

        $this->assertStringNotContainsString('ศรีสุข', $response->getContent());
        $this->assertStringNotContainsString('0899999999', $response->getContent());

        $this->postJson('/api/v1/loyalty/transfers/lookup', ['recipient' => '0800000000'])->assertNotFound();
    }

    public function test_ambiguous_phone_matching_two_accounts_is_not_guessed(): void
    {
        $sender = $this->userWithPoints(100);
        User::factory()->create(['phone' => '0811111111']);
        User::factory()->create(['phone' => '081-111-1111']);
        Sanctum::actingAs($sender);

        $this->postJson('/api/v1/loyalty/transfers/lookup', ['recipient' => '0811111111'])->assertNotFound();
    }

    public function test_received_points_can_be_spent_and_history_shows_both_sides(): void
    {
        $sender = $this->userWithPoints(100, ['nickname' => 'ต้น', 'name' => 'ธนา ใจงาม']);
        $friend = User::factory()->create(['referral_code' => 'FRND2345']);
        Sanctum::actingAs($sender);
        $this->postJson('/api/v1/loyalty/transfers', ['recipient' => 'FRND2345', 'points' => 60])->assertCreated();

        app(LoyaltyService::class)->spend($friend->id, 60, 'แลกของรางวัล');
        $this->assertSame(0, LoyaltyAccount::forUser($friend->id)->points);

        Sanctum::actingAs($friend);
        $this->getJson('/api/v1/loyalty/transfers')
            ->assertOk()
            ->assertJsonPath('data.member_code', 'FRND2345')
            ->assertJsonPath('data.history.0.direction', 'in')
            ->assertJsonPath('data.history.0.counterpart_name', 'ต้น (ธนา ใ.)');

        $notification = SmartNotification::where('user_id', $friend->id)->first();
        $this->assertStringContainsString('60 แต้ม', $notification->body);
    }
}
