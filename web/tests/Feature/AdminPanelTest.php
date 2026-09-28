<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * پنل مدیریت: نمای کلی و سلامت سرور، خروج از همه دستگاه‌ها، اعلان همگانی، کلیدهای روشن/خاموش و اطلاعیه
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_and_controls(): void
    {
        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN, 'last_login_at' => now()])->refresh();
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN, 'last_login_at' => now()])->refresh();
        $member = User::factory()->withPerson()->create(['last_login_at' => now()])->refresh();

        $this->actingAs($member, 'sanctum')->getJson('/api/admin/overview')->assertForbidden();
        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/overview')->assertOk();
        $this->assertSame(3, $res->json('data.stats.members'));
        $this->assertContains('ffmpeg', array_column($res->json('data.checks'), 'key'));
        // هیچ کلید یا رمزی در خروجی سلامت نیست
        $this->assertStringNotContainsString((string) config('app.key'), $res->getContent());

        // خروج از همه دستگاه‌ها
        $member->createToken('phone');
        $this->postJson("/api/admin/users/{$member->id}/logout-all")->assertOk();
        $this->assertSame(0, $member->tokens()->count());
        // مدیر معمولی مدیر کل را بیرون نمی‌کند
        $this->postJson("/api/admin/users/{$super->id}/logout-all")->assertForbidden();

        // اعلان همگانی فقط مدیر کل
        $this->postJson('/api/admin/broadcast', ['title' => 'دورهمی', 'body' => 'جمعه خانه پدربزرگ'])->assertForbidden();
        $this->actingAs($super, 'sanctum')->postJson('/api/admin/broadcast', ['title' => 'دورهمی', 'body' => "جمعه خانه پدربزرگ\u{202E}"])->assertOk();
        $note = $member->notifications()->first();
        $this->assertSame('broadcast', $note->data['kind']);
        $this->assertSame('جمعه خانه پدربزرگ', $note->data['body']);
    }

    public function test_feature_switches_and_announcement(): void
    {
        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN, 'last_login_at' => now()])->refresh();
        $member = User::factory()->withPerson()->create(['last_login_at' => now()])->refresh();

        $this->actingAs($super, 'sanctum')->putJson('/api/admin/settings', ['values' => [
            'pedigree.messaging.enabled' => false,
            'pedigree.group.slow_mode_seconds' => 10,
            'pedigree.group.name' => 'دورهمی احمدی‌ها',
            'pedigree.announcement.enabled' => true,
            'pedigree.announcement.text' => 'دورهمی نوروزی ۵ فروردین',
            'pedigree.announcement.level' => 'success',
        ]])->assertOk();

        $boot = $this->getJson('/api/bootstrap')->assertOk();
        $boot->assertJsonPath('messaging.enabled', false)
            ->assertJsonPath('group.name', 'دورهمی احمدی‌ها')
            ->assertJsonPath('announcement.text', 'دورهمی نوروزی ۵ فروردین')
            ->assertJsonPath('announcement.level', 'success');
        $this->actingAs($member, 'sanctum')->getJson('/api/messages')->assertForbidden()->assertJsonPath('code', 'messaging_off');
        $this->postJson('/api/messages/start', ['person_id' => $super->person_id])->assertForbidden();

        // مقدار نامعتبر
        $this->actingAs($super, 'sanctum')->putJson('/api/admin/settings', ['values' => ['pedigree.announcement.level' => 'red', 'pedigree.group.slow_mode_seconds' => -1]])
            ->assertStatus(422);
    }
}
