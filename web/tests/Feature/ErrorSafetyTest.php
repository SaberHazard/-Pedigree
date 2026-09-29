<?php

namespace Tests\Feature;

use App\Exceptions\DomainException;
use App\Models\ErrorReport;
use App\Models\User;
use App\Support\ErrorReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * تور ایمنی خطاها: هیچ خطایی جزئیات سرور (مسیر فایل، آدرس، کلید، پرس‌وجو) را به کاربر نشان نمی‌دهد؛
 * فقط پیام فارسی و کد پیگیری. خلاصه پاک‌شده خطا در پنل مدیریت ثبت می‌شود.
 */
class ErrorSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_URL = 'https://api.kavenegar.com/v1/SECRETKEY1234567890ABCDEFGH/account/info.json?token=abc';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => true]);
        Route::middleware('api')->get('/api/_test/boom', function () {
            throw new RuntimeException('boom at '.self::SECRET_URL.' in '.base_path('app/Secret.php').' for 09121234567');
        });
        Route::middleware('web')->get('/_test/boom', function () {
            throw new RuntimeException('web boom '.self::SECRET_URL);
        });
        Route::middleware('api')->get('/api/_test/domain', function () {
            throw new DomainException('این شخص از قبل پدر دارد.');
        });
    }

    public function test_unexpected_api_error_shows_only_a_persian_message_and_tracking_code(): void
    {
        $response = $this->getJson('/api/_test/boom')->assertStatus(500)->assertJsonPath('code', 'server_error');
        $ref = $response->json('ref');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $ref);
        $this->assertStringContainsString("کد پیگیری: {$ref}", $response->json('message'));

        // هیچ جزئیاتی از سرور در پاسخ نیست (حتی با APP_DEBUG=true روی سرور)
        $body = $response->getContent();
        foreach (['SECRETKEY', 'kavenegar', 'app/Secret.php', base_path(), 'trace', 'exception', 'RuntimeException', '0912'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }

        // در پنل مدیریت: پیام پاک‌شده، محل خطا در کد خود برنامه
        $report = ErrorReport::query()->where('ref', $ref)->firstOrFail();
        $this->assertSame('server', $report->source);
        $this->assertSame('RuntimeException', $report->type);
        $this->assertStringContainsString('[api.kavenegar.com]', $report->message);
        $this->assertStringNotContainsString('SECRETKEY', $report->message);
        $this->assertStringNotContainsString('0912', $report->message);
        $this->assertStringNotContainsString(base_path(), $report->message);
        $this->assertStringStartsWith('tests/Feature/ErrorSafetyTest.php:', $report->location);
        $this->assertSame('/api/_test/boom', $report->path);

        // تکرار همان خطا: یک ردیف با شمارنده و همان کد
        $this->getJson('/api/_test/boom')->assertStatus(500)->assertJsonPath('ref', $ref);
        $this->assertSame(2, ErrorReport::query()->where('ref', $ref)->value('count'));
        $this->assertSame(1, ErrorReport::query()->count());
    }

    public function test_web_page_error_renders_a_friendly_page_without_details(): void
    {
        $response = $this->get('/_test/boom')->assertStatus(500);
        $response->assertSee('متأسفانه خطایی رخ داد')->assertSee('کد پیگیری');
        $this->assertStringNotContainsString('SECRETKEY', $response->getContent());
        $this->assertStringNotContainsString('kavenegar', $response->getContent());
    }

    public function test_business_rule_errors_are_shown_but_not_logged(): void
    {
        Log::spy();
        $this->getJson('/api/_test/domain')->assertStatus(422)->assertJsonPath('message', 'این شخص از قبل پدر دارد.');
        Log::shouldNotHaveReceived('error');
        $this->assertSame(0, ErrorReport::query()->count());
    }

    public function test_sms_panel_network_failure_never_leaks_the_api_key(): void
    {
        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
        config(['pedigree.sms.drivers.kavenegar' => ['api_key' => 'SECRETKEY1234567890ABCDEFGH']]);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out for '.self::SECRET_URL));
        Log::spy();

        $response = $this->actingAs($super, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'sms_credit', 'provider' => 'kavenegar'])
            ->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('اتصال به پنل پیامکی برقرار نشد', $response->json('message'));
        $this->assertStringNotContainsString('SECRETKEY', $response->getContent());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'SMS provider unreachable'
            && ! str_contains(json_encode($context), 'SECRETKEY'));
    }

    public function test_browser_errors_reach_the_admin_panel_for_super_admins_only(): void
    {
        $member = User::factory()->withPerson()->create()->refresh();
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN])->refresh();
        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();

        // مهمان نمی‌تواند گزارش بفرستد
        $this->postJson('/api/client-errors', ['message' => 'x'])->assertUnauthorized();

        $ref = $this->actingAs($member, 'sanctum')->postJson('/api/client-errors', [
            'message' => 'Cannot read properties of undefined (reading \'id\') token=eyJhbGciOiJIUzI1NiJ9abcdefghijklmnop',
            'type' => 'TypeError',
            'source' => '/assets/js/pages/person.js',
            'line' => 120,
            'page' => '#/person/abc',
        ])->assertStatus(202)->json('ref');
        $report = ErrorReport::query()->where('ref', $ref)->firstOrFail();
        $this->assertSame('client', $report->source);
        $this->assertSame('/assets/js/pages/person.js:120', $report->location);
        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9abcdefghijklmnop', $report->message);

        // فقط مدیر کل خطاها را می‌بیند و رفع‌شده علامت می‌زند
        $this->actingAs($member, 'sanctum')->getJson('/api/admin/errors')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/errors')->assertForbidden();
        $this->actingAs($super, 'sanctum')->getJson('/api/admin/errors')->assertOk()
            ->assertJsonPath('data.0.ref', $ref)->assertJsonPath('data.0.user.id', $member->id)->assertJsonPath('meta.open', 1);
        $this->postJson("/api/admin/errors/{$report->id}/resolve")->assertOk();
        $this->getJson('/api/admin/errors')->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/errors?status=resolved')->assertJsonCount(1, 'data');
        $this->deleteJson('/api/admin/errors/resolved')->assertOk();
        $this->assertSame(0, ErrorReport::query()->count());

        // سقف ارسال گزارش مرورگر
        for ($i = 0; $i < 12; $i++) {
            $last = $this->actingAs($member, 'sanctum')->postJson('/api/client-errors', ['message' => "e{$i}"]);
        }
        $last->assertStatus(429);
    }

    public function test_sanitizer_and_flood_cap(): void
    {
        $clean = ErrorReporter::sanitize('failed '.self::SECRET_URL.' at '.base_path('app/X.php').' user 0012345679 key sk-proj-ABCDEF1234567890abcdef1234 (Connection: mysql, SQL: select * from users where password = x)');
        $this->assertStringContainsString('[api.kavenegar.com]', $clean);
        foreach (['SECRETKEY', 'token=', base_path(), '0012345679', 'sk-proj-ABCDEF', 'password', 'select'] as $leak) {
            $this->assertStringNotContainsString($leak, $clean);
        }
        $this->assertStringContainsString('app/X.php', $clean);

        // سیل خطا: حداکثر ۶۰ ثبت در دقیقه
        for ($i = 0; $i < 70; $i++) {
            ErrorReporter::record(new RuntimeException("flood {$i} ".str_repeat('x', $i)));
        }
        $this->assertLessThanOrEqual(60, ErrorReport::query()->count());
    }
}
