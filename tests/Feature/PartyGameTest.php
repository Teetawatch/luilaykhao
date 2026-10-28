<?php

namespace Tests\Feature;

use App\Models\BookingPassenger;
use App\Models\PartyGameCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\TripChatFixtures;
use Tests\TestCase;

class PartyGameTest extends TestCase
{
    use RefreshDatabase;
    use TripChatFixtures;

    private function admin(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function fetch(User $user)
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1/party-games')->assertOk();
    }

    public function test_migration_ships_a_starter_bank_for_every_game(): void
    {
        foreach (PartyGameCard::GAMES as $game) {
            $this->assertGreaterThan(5, PartyGameCard::where('game', $game)->count(), $game);
        }

        $trivia = PartyGameCard::where('game', 'trivia')->get();
        foreach ($trivia as $card) {
            $this->assertArrayHasKey($card->data['answer'], $card->data['choices'], $card->body);
        }
        $this->assertTrue(PartyGameCard::where('game', 'spyfall')->get()->every(fn ($c) => count($c->data['roles']) >= 3));
    }

    public function test_traveller_on_the_trip_gets_the_round_players_and_bank(): void
    {
        $schedule = $this->tripSchedule();
        [$owner, $booking] = $this->party($schedule, ['บี', 'บี', 'แอน']);
        $this->staffOf($schedule, 'พี่ต้น');

        $res = $this->fetch($owner);

        $res->assertJsonPath('data.rounds.0.schedule_id', $schedule->id)
            ->assertJsonPath('data.rounds.0.trip_title', 'ทริปภูกระดึง')
            ->assertJsonPath('data.rounds.0.opens_on', $schedule->departure_date->toDateString())
            ->assertJsonPath('data.rounds.0.closes_on', $schedule->return_date->toDateString());

        // ชื่อซ้ำต่อท้ายเลข และทีมงานอยู่ท้ายรายชื่อ
        $this->assertSame(['บี', 'บี 2', 'แอน', 'พี่ต้น'], $res->json('data.rounds.0.players'));
        $this->assertNotEmpty($res->json('data.cards'));
        $this->assertSame(
            PartyGameCard::where('is_active', true)->whereNull('trip_id')->count(),
            count($res->json('data.cards')),
        );
    }

    public function test_people_who_said_they_are_not_going_are_left_out_of_the_players(): void
    {
        $schedule = $this->tripSchedule();
        [$owner, , $passengers] = $this->party($schedule, ['บี', 'แอน']);
        BookingPassenger::whereKey($passengers[1]->id)->update(['not_going_at' => now()]);

        $this->assertSame(['บี'], $this->fetch($owner)->json('data.rounds.0.players'));
    }

    public function test_staff_of_the_round_can_open_the_games(): void
    {
        $schedule = $this->tripSchedule();
        $this->party($schedule, ['บี']);
        $staff = $this->staffOf($schedule);

        $this->fetch($staff)->assertJsonPath('data.rounds.0.schedule_id', $schedule->id);
    }

    public function test_someone_not_on_any_round_gets_nothing(): void
    {
        $schedule = $this->tripSchedule();
        $this->party($schedule, ['บี']);

        $this->fetch(User::factory()->create())
            ->assertJsonPath('data.rounds', [])
            ->assertJsonPath('data.cards', []);
    }

    public function test_bank_arrives_two_days_early_but_not_before_and_not_after_return(): void
    {
        $soon = $this->tripSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(2)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
        ]);
        [$soonOwner] = $this->party($soon, ['บี']);
        $this->fetch($soonOwner)->assertJsonPath('data.rounds.0.schedule_id', $soon->id);

        $later = $this->tripSchedule([
            'departure_date' => now('Asia/Bangkok')->addDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->addDays(4)->toDateString(),
        ]);
        [$laterOwner] = $this->party($later, ['บี']);
        $this->fetch($laterOwner)->assertJsonPath('data.rounds', [])->assertJsonPath('data.cards', []);

        $ended = $this->tripSchedule([
            'departure_date' => now('Asia/Bangkok')->subDays(3)->toDateString(),
            'return_date' => now('Asia/Bangkok')->subDay()->toDateString(),
        ]);
        [$endedOwner] = $this->party($ended, ['บี']);
        $this->fetch($endedOwner)->assertJsonPath('data.rounds', []);
    }

    public function test_overnight_van_opens_the_games_the_night_it_leaves(): void
    {
        $tomorrow = now('Asia/Bangkok')->addDay();
        $schedule = $this->tripSchedule([
            'departure_date' => $tomorrow->toDateString(),
            'return_date' => $tomorrow->copy()->addDay()->toDateString(),
            // เวลาไทยในคอลัมน์ UTC — คืนนี้สามทุ่ม
            'departs_at' => now('Asia/Bangkok')->toDateString().' 21:00:00',
        ]);
        [$owner] = $this->party($schedule, ['บี']);

        $this->fetch($owner)->assertJsonPath('data.rounds.0.opens_on', now('Asia/Bangkok')->toDateString());
    }

    public function test_cancelled_or_unconfirmed_bookings_do_not_open_the_games(): void
    {
        $schedule = $this->tripSchedule();
        [$pending] = $this->party($schedule, ['บี'], ['status' => 'pending']);
        [$cancelled] = $this->party($schedule, ['แอน'], ['status' => 'cancelled']);

        $this->fetch($pending)->assertJsonPath('data.rounds', []);
        $this->fetch($cancelled)->assertJsonPath('data.rounds', []);
    }

    public function test_trip_specific_and_hidden_cards(): void
    {
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['บี']);
        $other = $this->tripSchedule();

        $mine = PartyGameCard::create([
            'game' => 'trivia', 'category' => 'ภูกระดึง', 'body' => 'หลังแปอยู่สูงเท่าไหร่',
            'data' => ['choices' => ['ราว 1,200 ม.', 'ราว 2,500 ม.'], 'answer' => 0],
            'trip_id' => $schedule->trip_id,
        ]);
        $theirs = PartyGameCard::create([
            'game' => 'never', 'level' => 'light', 'body' => 'ฉันไม่เคยไปทริปอื่น',
            'trip_id' => $other->trip_id,
        ]);
        $hidden = PartyGameCard::create([
            'game' => 'never', 'level' => 'light', 'body' => 'ฉันไม่เคยเห็นใบนี้', 'is_active' => false,
        ]);

        $ids = collect($this->fetch($owner)->json('data.cards'))->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($theirs->id));
        $this->assertFalse($ids->contains($hidden->id));
    }

    public function test_admin_adds_cards_and_each_game_is_validated(): void
    {
        $admin = $this->admin();
        $url = '/api/v1/admin/party-games';

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'trivia', 'category' => 'รอบตัว', 'body' => '1+1 เท่ากับเท่าไหร่',
            'data' => ['choices' => ['1', '2'], 'answer' => 2],
        ])->assertStatus(422)->assertJsonValidationErrors('data.answer');

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'spyfall', 'body' => 'ตลาดเช้า', 'data' => ['roles' => ['แม่ค้า', 'แม่ค้า']],
        ])->assertStatus(422)->assertJsonValidationErrors('data.roles');

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'truth_dare', 'level' => 'light', 'body' => 'ร้องเพลง',
        ])->assertStatus(422)->assertJsonValidationErrors('kind');

        $res = $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'heads_up', 'category' => 'สายแคมป์ปิ้ง', 'body' => ' ไฟแช็ก ',
            // ช่องที่เกมนี้ไม่ใช้ถูกทิ้ง ไม่ค้างในฐานข้อมูล
            'level' => 'spicy', 'kind' => 'dare',
            'data' => ['taboo' => "ไฟ\nจุด, บุหรี่"],
        ])->assertCreated();

        $card = PartyGameCard::findOrFail($res->json('data.id'));
        $this->assertSame('ไฟแช็ก', $card->body);
        $this->assertNull($card->level);
        $this->assertNull($card->kind);
        $this->assertSame(['taboo' => ['ไฟ', 'จุด', 'บุหรี่']], $card->data);
    }

    public function test_admin_pastes_many_lines_at_once(): void
    {
        $admin = $this->admin();
        $before = PartyGameCard::where('game', 'never')->count();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games/bulk', [
            'game' => 'never', 'level' => 'medium',
            'lines' => "ฉันไม่เคยตกรถ\n\n  ฉันไม่เคยลืมกุญแจ  \nฉันไม่เคยตกรถ",
        ])->assertCreated()->assertJsonPath('data.count', 2);

        $this->assertSame($before + 2, PartyGameCard::where('game', 'never')->count());
        $this->assertSame(2, PartyGameCard::where('level', 'medium')->whereIn('body', ['ฉันไม่เคยตกรถ', 'ฉันไม่เคยลืมกุญแจ'])->count());

        // ขาดระดับ = ไม่บันทึกสักบรรทัด
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games/bulk', [
            'game' => 'never', 'lines' => "ก\nข",
        ])->assertStatus(422);
        $this->assertSame($before + 2, PartyGameCard::where('game', 'never')->count());
    }

    public function test_forbidden_words_ship_by_level_and_must_stay_short(): void
    {
        foreach (PartyGameCard::LEVELS as $level) {
            $this->assertGreaterThanOrEqual(10, PartyGameCard::where('game', 'forbidden')->where('level', $level)->count(), $level);
        }

        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games', [
            'game' => 'forbidden', 'level' => 'spicy', 'body' => 'ห้ามพูดประโยคยาวมากเกินกว่าที่ใครจะเผลอพูดออกมาได้',
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games', [
            'game' => 'forbidden', 'body' => 'หนาว',
        ])->assertStatus(422)->assertJsonValidationErrors('level');

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games/bulk', [
            'game' => 'forbidden', 'level' => 'medium', 'lines' => "ภูเขา\nลม",
        ])->assertCreated()->assertJsonPath('data.count', 2);
    }

    public function test_admin_toggles_edits_and_deletes(): void
    {
        $admin = $this->admin();
        $card = PartyGameCard::create(['game' => 'never', 'level' => 'light', 'body' => 'ฉันไม่เคยเดินป่า']);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/party-games/{$card->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        // แก้ข้อความโดยไม่ส่ง is_active มา — ใบที่ปิดไว้ต้องยังปิดอยู่
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/party-games/{$card->id}", [
            'game' => 'never', 'level' => 'spicy', 'body' => 'ฉันไม่เคยเดินป่าตอนกลางคืน',
        ])->assertOk()->assertJsonPath('data.level', 'spicy')->assertJsonPath('data.is_active', false);

        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/party-games/{$card->id}")->assertOk();
        $this->assertNull(PartyGameCard::find($card->id));
    }

    public function test_non_admin_cannot_manage_the_bank(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/admin/party-games')
            ->assertForbidden();
    }

    public function test_admin_list_includes_hidden_cards_and_trips(): void
    {
        $admin = $this->admin();
        PartyGameCard::create(['game' => 'never', 'level' => 'light', 'body' => 'ฉันไม่เคยซ่อน', 'is_active' => false]);
        $schedule = $this->tripSchedule();

        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/party-games')->assertOk();

        $this->assertContains('ฉันไม่เคยซ่อน', collect($res->json('data.cards'))->pluck('body'));
        $this->assertContains($schedule->trip_id, collect($res->json('data.trips'))->pluck('id'));
    }

    public function test_icebreaker_games_ship_with_pairs_and_levels(): void
    {
        $this->assertTrue(PartyGameCard::where('game', 'would_rather')->get()->every(fn ($c) => filled($c->data['other'] ?? null)));
        $this->assertTrue(PartyGameCard::where('game', 'wavelength')->get()->every(fn ($c) => filled($c->data['right'] ?? null)));
        foreach (['most_likely', 'would_rather'] as $game) {
            foreach (PartyGameCard::LEVELS as $level) {
                $this->assertGreaterThanOrEqual(5, PartyGameCard::where('game', $game)->where('level', $level)->count(), "{$game} {$level}");
            }
        }

        // แอปเติม "ใครน่าจะ" เอง — คลังต้องไม่มีหัวซ้ำ
        $this->assertSame(0, PartyGameCard::where('game', 'most_likely')->where('body', 'like', 'ใคร%')->count());

        // ส่งถึงแอปพร้อมข้อมูลคู่
        $schedule = $this->tripSchedule();
        [$owner] = $this->party($schedule, ['บี', 'แอน']);
        $cards = collect($this->fetch($owner)->json('data.cards'));
        $wave = $cards->firstWhere('game', 'wavelength');
        $this->assertNotEmpty($wave['data']['right']);
        $this->assertNotEmpty($cards->firstWhere('game', 'bomb'));
    }

    public function test_icebreaker_cards_are_validated_and_cleaned(): void
    {
        $admin = $this->admin();
        $url = '/api/v1/admin/party-games';

        // หัว "ใครน่าจะ" กับเครื่องหมายคำถามท้ายถูกตัด — ตัวสุดท้ายเป็นอักษรไทยต้องไม่ถูกกินไบต์
        $res = $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'most_likely', 'level' => 'light', 'body' => 'ใครในวงนี้น่าจะกลัวผีที่สุด？',
        ])->assertCreated();
        $this->assertSame('กลัวผีที่สุด', $res->json('data.body'));

        $res = $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'most_likely', 'level' => 'light', 'body' => 'ทำไฟฟ?',
        ])->assertCreated();
        $this->assertSame('ทำไฟฟ', $res->json('data.body'));

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'most_likely', 'body' => 'หลงทาง',
        ])->assertStatus(422)->assertJsonValidationErrors('level');

        // เกมคู่ — พิมพ์ "A | B" ช่องเดียวได้ ขาดฝั่งไหนไม่ผ่าน
        $res = $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'would_rather', 'level' => 'medium', 'body' => ' ภูเขา | ทะเล ',
        ])->assertCreated();
        $this->assertSame('ภูเขา', $res->json('data.body'));
        $this->assertSame(['other' => 'ทะเล'], PartyGameCard::find($res->json('data.id'))->data);

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'would_rather', 'level' => 'medium', 'body' => 'ภูเขา',
        ])->assertStatus(422)->assertJsonValidationErrors('data.other');

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'wavelength', 'body' => '| ร้อน',
        ])->assertStatus(422)->assertJsonValidationErrors('body');

        $res = $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'wavelength', 'body' => 'หนาว', 'level' => 'spicy', 'data' => ['right' => 'ร้อน'],
        ])->assertCreated();
        $this->assertNull($res->json('data.level'));
        $this->assertSame('ร้อน', $res->json('data.data.right'));

        $this->actingAs($admin, 'sanctum')->postJson($url, [
            'game' => 'bomb', 'body' => str_repeat('ยาว', 31),
        ])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_admin_pastes_pairs_one_per_line(): void
    {
        $admin = $this->admin();
        $before = PartyGameCard::where('game', 'wavelength')->count();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games/bulk', [
            'game' => 'wavelength',
            'lines' => "เงียบ | เสียงดัง\nช้า|เร็ว",
        ])->assertCreated()->assertJsonPath('data.count', 2);

        $this->assertSame(['right' => 'เร็ว'], PartyGameCard::where('game', 'wavelength')->where('body', 'ช้า')->first()->data);

        // บรรทัดเดียวไม่มีคู่ = ไม่บันทึกสักบรรทัด
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games/bulk', [
            'game' => 'wavelength', 'lines' => "มืด | สว่าง\nไม่มีคู่",
        ])->assertStatus(422);
        $this->assertSame($before + 2, PartyGameCard::where('game', 'wavelength')->count());

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/party-games/bulk', [
            'game' => 'bomb', 'lines' => "ยี่ห้อกาแฟ\nชื่อน้ำตก",
        ])->assertCreated()->assertJsonPath('data.count', 2);
    }
}
