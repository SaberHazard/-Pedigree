<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حریم خاندان: بدون ورود هیچ‌چیز دیده نمی‌شود و غریبه‌ای که خودش ثبت‌نام می‌کند تا تأیید مدیر هیچ‌چیز نمی‌بیند
 */
class MembershipApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function register(string $phone, array $extra = []): array
    {
        $code = $this->postJson('/api/auth/otp', ['phone' => $phone])->json('debug_code');
        $token = $this->postJson('/api/auth/otp/verify', ['phone' => $phone, 'code' => $code])->json('registration_token');

        return $this->postJson('/api/auth/register', $extra + [
            'registration_token' => $token, 'first_name' => 'غریبه', 'last_name' => 'ناشناس', 'gender' => 'm',
            'no_national_code' => true, 'device_name' => 'test', 'join_note' => 'پسر حسن احمدی از شاخه شیراز',
        ])->json();
    }

    public function test_nothing_is_visible_without_login(): void
    {
        $member = User::factory()->withPerson()->create();
        foreach ([
            "/api/persons/{$member->person_id}",
            "/api/persons/{$member->person_id}/relatives",
            "/api/persons/{$member->person_id}/media",
            "/api/tree/{$member->person_id}/descendants",
            "/api/tree/{$member->person_id}/ancestors",
            '/api/families',
            '/api/persons',
            '/api/group/messages',
        ] as $url) {
            $this->getJson($url)->assertStatus(401);
        }
        // حتی اگر کسی متغیر قدیمی حالت مهمان را روشن کرده باشد
        config(['pedigree.guest_view' => true]);
        $this->getJson("/api/persons/{$member->person_id}")->assertStatus(401);
    }

    public function test_self_registered_stranger_waits_for_admin_approval(): void
    {
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $member = User::factory()->withPerson()->create();

        $res = $this->register('09350000077');
        $this->assertSame('pending', $res['user']['status']);
        $this->assertSame([], array_filter($res['user']['counters']));
        $token = $res['token'];
        $stranger = User::query()->where('status', User::STATUS_PENDING)->firstOrFail();

        // عضو در انتظار هیچ‌چیز از شجره‌نامه نمی‌بیند
        $this->withToken($token)->getJson("/api/persons/{$member->person_id}")->assertStatus(403)->assertJsonPath('code', 'pending_approval');
        $this->withToken($token)->getJson('/api/persons?q=a')->assertStatus(403);
        $this->withToken($token)->getJson('/api/group/messages')->assertStatus(403);
        $this->withToken($token)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.join_note', 'پسر حسن احمدی از شاخه شیراز');

        // مدیران خبردار می‌شوند
        $this->assertSame('member_pending', $admin->notifications()->first()->data['kind']);

        // عضو عادی نمی‌تواند تأیید کند؛ مدیر می‌بیند و تأیید می‌کند
        $this->actingAs($member, 'sanctum')->postJson("/api/admin/users/{$stranger->id}/approve")->assertStatus(403);
        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/users?status=pending')->assertOk();
        $this->assertSame(1, $list->json('pending'));
        $this->assertSame('پسر حسن احمدی از شاخه شیراز', $list->json('data.0.join_note'));
        // وضعیت در انتظار با PATCH عادی عوض نمی‌شود
        $this->patchJson("/api/admin/users/{$stranger->id}", ['status' => 'active'])->assertStatus(422);
        $this->postJson("/api/admin/users/{$stranger->id}/approve")->assertOk();
        $this->assertTrue($stranger->fresh()->isActive());
        $this->assertSame('member_approved', $stranger->notifications()->first()->data['kind']);
        $this->postJson("/api/admin/users/{$stranger->id}/approve")->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/persons/{$member->person_id}")->assertOk();
    }

    public function test_rejected_stranger_is_blocked_and_their_orphan_profile_removed(): void
    {
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN]);
        $res = $this->register('09350000078');
        $stranger = User::query()->where('status', User::STATUS_PENDING)->firstOrFail();
        $personId = $stranger->person_id;

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/users/{$stranger->id}/reject")->assertOk();
        $this->assertSame(User::STATUS_BLOCKED, $stranger->fresh()->status);
        $this->assertSoftDeleted('persons', ['id' => $personId]);
        $this->assertSame(0, $stranger->tokens()->count());
        $this->assertNotEmpty($res['token']);
    }

    public function test_relatives_added_phone_logs_in_without_approval_and_note_is_required(): void
    {
        // شماره را بستگان در پروفایل ثبت کرده‌اند: ورود مستقیم
        $person = Person::factory()->create(['first_name' => 'رضا']);
        $person->forceFill(['phone' => '09350000079'])->save();
        $code = $this->postJson('/api/auth/otp', ['phone' => '09350000079'])->json('debug_code');
        $this->postJson('/api/auth/otp/verify', ['phone' => '09350000079', 'code' => $code, 'device_name' => 'x'])
            ->assertOk()->assertJsonPath('user.status', 'active');

        // بدون معرفی ثبت‌نام نمی‌شود
        $code = $this->postJson('/api/auth/otp', ['phone' => '09350000080'])->json('debug_code');
        $token = $this->postJson('/api/auth/otp/verify', ['phone' => '09350000080', 'code' => $code])->json('registration_token');
        $this->postJson('/api/auth/register', ['registration_token' => $token, 'first_name' => 'x', 'last_name' => 'y', 'gender' => 'm', 'no_national_code' => true])
            ->assertJsonValidationErrors('join_note');

        // با خاموش بودن تأیید عضویت، ثبت‌نام مستقیم فعال است
        config(['pedigree.registration.require_approval' => false]);
        $res = $this->register('09350000081', ['join_note' => null]);
        $this->assertSame('active', $res['user']['status']);
    }
}
