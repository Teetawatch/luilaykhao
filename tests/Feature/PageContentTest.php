<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\PageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PageContentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_public_endpoint_returns_bundled_defaults_before_anyone_edits(): void
    {
        $response = $this->getJson('/api/v1/content/checklist');

        $response->assertOk();
        $this->assertSame('เช็คลิสต์ของที่ต้องเตรียม', $response->json('data.title'));
        $this->assertNotEmpty($response->json('data.categories'));
    }

    public function test_public_endpoint_404s_for_unknown_page(): void
    {
        $this->getJson('/api/v1/content/not-a-page')->assertNotFound();
    }

    public function test_admin_can_edit_content_and_public_endpoint_reflects_it(): void
    {
        $content = PageContent::get('booking_guide');
        $content['hero_title'] = 'จองยังไงดี';

        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/content/booking_guide', ['content' => $content])
            ->assertOk();

        $this->getJson('/api/v1/content/booking_guide')
            ->assertOk()
            ->assertJsonPath('data.hero_title', 'จองยังไงดี');
    }

    public function test_saved_content_still_gains_defaults_for_fields_added_later(): void
    {
        // จำลองค่าที่แอดมินเคยบันทึกไว้ตอน schema ยังไม่มีช่อง footnote
        Setting::put('page_content:checklist', ['title' => 'ของที่ต้องเตรียม']);

        $content = PageContent::get('checklist');

        $this->assertSame('ของที่ต้องเตรียม', $content['title']);
        $this->assertNotEmpty($content['footnote']);
        $this->assertNotEmpty($content['categories']);
    }

    public function test_reset_restores_the_bundled_content(): void
    {
        Setting::put('page_content:problem', ['hero_title' => 'ของที่แก้ไว้']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/content/problem/reset')
            ->assertOk();

        $this->assertSame(
            PageContent::defaults('problem')['hero_title'],
            PageContent::get('problem')['hero_title'],
        );
    }

    public function test_update_rejects_content_that_breaks_the_schema(): void
    {
        $content = PageContent::get('faq');
        $content['groups'][0]['tone'] = 'neon-pink';

        $this->actingAs($this->admin())
            ->putJson('/api/v1/admin/content/faq', ['content' => $content])
            ->assertStatus(422)
            ->assertJsonValidationErrors('content.groups.0.tone');
    }

    public function test_admin_index_flags_which_pages_have_been_edited(): void
    {
        Setting::put('page_content:faq', PageContent::defaults('faq'));

        $rows = collect(
            $this->actingAs($this->admin())->getJson('/api/v1/admin/content')->json('data')
        )->keyBy('key');

        $this->assertCount(count(PageContent::pages()), $rows);
        $this->assertTrue($rows['faq']['customised']);
        $this->assertFalse($rows['difficulty']['customised']);
    }

    public function test_admin_show_returns_the_schema_used_to_draw_the_form(): void
    {
        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/content/difficulty');

        $response->assertOk();
        $keys = collect($response->json('data.fields'))->pluck('key');
        $this->assertTrue($keys->contains('levels'));
        $this->assertSame('repeater', collect($response->json('data.fields'))->firstWhere('key', 'levels')['type']);
    }

    public function test_non_admin_cannot_edit_content(): void
    {
        $this->actingAs(User::factory()->create())
            ->putJson('/api/v1/admin/content/faq', ['content' => PageContent::get('faq')])
            ->assertForbidden();
    }

    public function test_every_registered_page_has_defaults_matching_its_schema(): void
    {
        foreach (PageContent::pages() as $key => $page) {
            $defaults = $page['defaults'];

            foreach ($page['fields'] as $field) {
                $this->assertArrayHasKey(
                    $field['key'],
                    $defaults,
                    "หน้า {$key} ประกาศช่อง {$field['key']} ไว้ใน schema แต่ไม่มีค่าเริ่มต้น",
                );
            }

            $this->assertSame(
                [],
                array_diff(array_keys($defaults), array_column($page['fields'], 'key')),
                "หน้า {$key} มีค่าเริ่มต้นของช่องที่ไม่ได้อยู่ใน schema (แอดมินจะแก้ไม่ได้)",
            );
        }
    }

    public function test_default_content_of_every_page_passes_its_own_validation(): void
    {
        $admin = $this->admin();

        foreach (array_keys(PageContent::pages()) as $key) {
            $this->actingAs($admin)
                ->putJson("/api/v1/admin/content/{$key}", ['content' => PageContent::defaults($key)])
                ->assertOk();
        }
    }
}
