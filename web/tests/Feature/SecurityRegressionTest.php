<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * پیشگیری از بازگشت خطاهای امنیتی/پایداری شناخته‌شده.
 */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    /** پارامترهای آرایه‌ای در آدرس (مثل ?status[]=x) نباید خطای ۵۰۰ بدهند */
    public function test_array_query_params_never_cause_server_errors(): void
    {
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
        $this->actingAs($admin, 'sanctum');
        $pid = $admin->person_id;

        foreach ([
            "/api/persons/{$pid}/media?type[]=image&category[a]=b",
            '/api/admin/users?role[]=admin&status[]=x&q[]=1',
            '/api/admin/activity?action[]=x&subject_id[]=1&user_id[]=2',
            '/api/admin/sms-messages?status[]=sent',
            '/api/persons?gender[]=m&q[]=x&per_page[]=5',
            '/api/persons?page[]=2',
            '/api/messages/1?after[]=1&before[]=2',
            '/api/dashboard/activity?days[]=1',
            "/api/tree/{$pid}/descendants?depth[]=3",
            "/api/tree/{$pid}/ancestors?depth[]=3",
            '/api/social/preview?network[]=x&value[]=y',
            '/api/notifications?page[]=1',
            '/api/group/messages?after[]=1&since[]=x&around[]=2',
            '/api/group/messages?since=not-a-date&after=1',
            '/api/games/question?type[]=who',
            '/api/admin/sms-templates?x[]=1',
            '/api/admin/group/reports?x[]=1',
            '/api/greetings?occasion[]=nowruz',
        ] as $url) {
            $this->assertLessThan(500, $this->getJson($url)->status(), $url);
        }
    }

    /** هر محدودیت عددی (throttle:N,M) شمارنده جدا دارد؛ بدون پیشوند، همه مسیرها یک شمارنده مشترک برای هر کاربر داشتند */
    public function test_numeric_throttles_have_unique_prefixes(): void
    {
        $owners = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && preg_match('/^throttle:\d+,\d+(?:,(.+))?$/', $middleware, $m)) {
                    $this->assertNotEmpty($m[1] ?? null, "numeric throttle without prefix on {$route->uri()}");
                    $owners[$m[1]][] = $route->uri();
                }
            }
        }
        foreach ($owners as $prefix => $uris) {
            $this->assertCount(1, array_unique($uris), "throttle prefix {$prefix} shared by ".implode(', ', $uris));
        }
        $this->assertGreaterThan(5, count($owners));
    }
}
