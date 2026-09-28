<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Settings\SettingsStore;
use App\Services\Sms\SmsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «تنظیمات و اتصال‌ها»: کلیدهای API از پنل مدیریت، رمزنگاری، ماسک، اعتبارسنجی و آزمایش اتصال.
 */
class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->withPerson(['phone' => '09120001111'])->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
    }

    private function field(array $groups, string $key): array
    {
        foreach ($groups as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['key'] === $key) {
                    return $field;
                }
            }
        }
        $this->fail("field {$key} not found");
    }

    public function test_only_super_admin_can_see_or_change_settings(): void
    {
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN]);
        $member = User::factory()->withPerson()->create();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', ['values' => ['pedigree.site_name' => 'x']])->assertForbidden();
        $this->actingAs($member, 'sanctum')->getJson('/api/admin/settings')->assertForbidden();
        $this->actingAs($this->super, 'sanctum')->getJson('/api/admin/settings')->assertOk()->assertJsonPath('data.0.key', 'general');
    }

    public function test_api_keys_are_encrypted_masked_and_applied(): void
    {
        config(['pedigree.sms.drivers.kavenegar.api_key' => null]);
        $this->actingAs($this->super, 'sanctum')->putJson('/api/admin/settings', ['values' => [
            'pedigree.sms.driver' => 'kavenegar',
            'pedigree.sms.drivers.kavenegar.api_key' => 'SECRET-KEY-1234abcd',
            'pedigree.sms.drivers.kavenegar.template' => 'myverify',
            'pedigree.member_sms.min_completeness' => 90,
            'pedigree.birthdays.notify_hour' => 7,
        ]])->assertOk();

        // همین حالا اعمال شده
        $this->assertSame('SECRET-KEY-1234abcd', config('pedigree.sms.drivers.kavenegar.api_key'));
        $this->assertSame('kavenegar', app(SmsManager::class)->driverName());
        $this->assertSame(90, config('pedigree.member_sms.min_completeness'));
        $this->assertSame(7, config('pedigree.birthdays.notify_hour'));

        // در پایگاه داده رمزنگاری‌شده
        $raw = DB::table('settings')->where('key', 'pedigree.sms.drivers.kavenegar.api_key')->value('value');
        $this->assertStringNotContainsString('SECRET', $raw);

        // به مرورگر فقط «تنظیم شده» و چهار نویسه آخر
        $res = $this->getJson('/api/admin/settings')->assertOk();
        $this->assertStringNotContainsString('SECRET-KEY', $res->getContent());
        $key = $this->field($res->json('data'), 'pedigree.sms.drivers.kavenegar.api_key');
        $this->assertTrue($key['is_set']);
        $this->assertSame('••••abcd', $key['hint']);
        $this->assertArrayNotHasKey('value', $key);
        $kavenegar = collect($res->json('data'))->firstWhere('key', 'kavenegar');
        $this->assertContains('کد ورود', $kavenegar['status']['roles']);

        // تاریخچه: نام تنظیم‌ها بدون مقدار
        $log = ActivityLog::where('action', 'settings.updated')->latest('id')->first();
        $this->assertContains('pedigree.sms.drivers.kavenegar.api_key', $log->properties['keys']);
        $this->assertStringNotContainsString('SECRET', json_encode($log->properties));

        // پس از «بوت» دوباره (درخواست بعدی) هم از پایگاه داده خوانده می‌شود
        config(['pedigree.sms.drivers.kavenegar.api_key' => 'env-value']);
        $store = new SettingsStore;
        $store->apply();
        $this->assertSame('SECRET-KEY-1234abcd', config('pedigree.sms.drivers.kavenegar.api_key'));
    }

    public function test_empty_secret_keeps_value_and_clear_restores_env(): void
    {
        config(['pedigree.sms.drivers.smsir.api_key' => 'ENV-KEY']);
        $this->actingAs($this->super, 'sanctum')->putJson('/api/admin/settings', ['values' => ['pedigree.sms.drivers.smsir.api_key' => 'PANEL-KEY']])->assertOk();
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.sms.drivers.smsir.api_key' => '']])->assertOk()->assertJsonPath('changed', []);
        $this->assertSame('PANEL-KEY', config('pedigree.sms.drivers.smsir.api_key'));

        $this->putJson('/api/admin/settings', ['values' => [], 'clear' => ['pedigree.sms.drivers.smsir.api_key']])->assertOk();
        $this->assertSame('ENV-KEY', config('pedigree.sms.drivers.smsir.api_key'));
        $this->assertSame(0, DB::table('settings')->count());
    }

    public function test_invalid_values_are_rejected(): void
    {
        $this->actingAs($this->super, 'sanctum');
        $bad = [
            'pedigree.member_sms.min_completeness' => 150,
            'pedigree.map.tiles' => 'http://evil.example/tile.png',
            'pedigree.social.proxy' => 'file:///etc/passwd',
            'pedigree.sms.driver' => 'unknown',
            'services.fcm.credentials_json' => '{"project_id": "x"}',
            'app.key' => 'base64:hack',
        ];
        $res = $this->putJson('/api/admin/settings', ['values' => $bad])->assertStatus(422);
        foreach (array_keys($bad) as $key) {
            $this->assertArrayHasKey("values.{$key}", $res->json('errors'), $key);
        }
        $this->assertSame(0, DB::table('settings')->count());

        // مقدار درست
        $this->putJson('/api/admin/settings', ['values' => [
            'pedigree.map.tiles' => 'https://tiles.example.ir/{z}/{x}/{y}.png?key=abc',
            'services.fcm.credentials_json' => json_encode(['project_id' => 'fam', 'client_email' => 'a@b.iam.gserviceaccount.com', 'private_key' => '-----BEGIN PRIVATE KEY-----x']),
        ]])->assertOk();
        $this->assertSame('https://tiles.example.ir/{z}/{x}/{y}.png?key=abc', config('pedigree.map.tiles'));
        $fcm = $this->field($this->getJson('/api/admin/settings')->json('data'), 'services.fcm.credentials_json');
        $this->assertSame('پروژه: fam', $fcm['hint']);
    }

    public function test_connection_tests(): void
    {
        Http::fake([
            'api.kavenegar.com/v1/KEY/account/info.json' => Http::response(['return' => ['status' => 200], 'entries' => ['remaincredit' => 98000]]),
            'api.kavenegar.com/v1/KEY/sms/send.json' => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]),
        ]);
        config(['pedigree.sms.drivers.kavenegar' => ['api_key' => 'KEY', 'template' => 'verify']]);

        $this->actingAs($this->super, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'sms_credit', 'provider' => 'kavenegar'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonFragment(['message' => 'اتصال برقرار است. اعتبار: 98,000 ریال']);

        // پیامک آزمایشی فقط به موبایل خود مدیر
        $this->postJson('/api/admin/settings/test', ['action' => 'sms_send', 'provider' => 'kavenegar'])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sms/send.json') && $r['receptor'] === '09120001111');

        // Firebase تنظیم نشده
        config(['services.fcm.credentials_json' => null, 'services.fcm.credentials' => '/nonexistent']);
        $this->postJson('/api/admin/settings/test', ['action' => 'push'])->assertStatus(422)->assertJsonPath('ok', false);

        $member = User::factory()->withPerson()->create();
        $this->actingAs($member, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'sms_credit', 'provider' => 'kavenegar'])->assertForbidden();
    }
}
