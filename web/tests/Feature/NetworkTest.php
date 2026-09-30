<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Social\SafeHttp;
use App\Support\Outbound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * سرور ایران / خارج: پراکسی خروجی سرویس‌های خارجی و آزمایش آن از پنل مدیریت.
 */
class NetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_foreign_proxy_resolution_order(): void
    {
        config(['pedigree.network.foreign_proxy' => 'socks5h://127.0.0.1:1080', 'pedigree.iran_proxy' => '']);
        $this->assertSame('iran', Outbound::location());
        $this->assertSame('socks5h://127.0.0.1:1080', Outbound::foreign());
        // پراکسی خاص یک بخش مقدم است
        $this->assertSame('http://ai-proxy:3128', Outbound::foreign('http://ai-proxy:3128'));
        $this->assertNull(Outbound::iran());

        config(['pedigree.network.foreign_proxy' => '  ', 'pedigree.network.location' => 'abroad', 'pedigree.iran_proxy' => 'http://ir:3128']);
        $this->assertNull(Outbound::foreign());
        $this->assertSame('abroad', Outbound::location());
        $this->assertSame('http://ir:3128', Outbound::iran());
    }

    public function test_proxy_test_shows_exit_ip_country_and_service_reachability(): void
    {
        SafeHttp::fakeResolver(fn () => ['34.117.59.81']);
        config(['pedigree.network.foreign_proxy' => 'socks5h://127.0.0.1:1080']);
        Http::fake([
            'ipinfo.io/*' => Http::sequence()->push(['ip' => '5.75.1.2', 'country' => 'DE'])->push(['ip' => '2.144.1.1', 'country' => 'IR']),
            'generativelanguage.googleapis.com/*' => Http::response('Not Found', 404),
            'fcm.googleapis.com/*' => Http::response('Forbidden', 403),
        ]);
        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();

        $message = $this->actingAs($super, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'proxy'])
            ->assertOk()->assertJsonPath('ok', true)->json('message');
        $this->assertStringContainsString('5.75.1.2', $message);
        $this->assertStringContainsString('(DE)', $message);
        $this->assertStringContainsString('هوش مصنوعی گوگل ✓', $message);
        $this->assertStringContainsString('Firebase ✗', $message);

        // خروجی ایران با سرور ایران: هشدار
        $this->assertStringContainsString('هشدار', $this->postJson('/api/admin/settings/test', ['action' => 'proxy'])->json('message'));

        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN])->refresh();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'proxy'])->assertForbidden();
    }

    public function test_settings_expose_network_group_with_guide(): void
    {
        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
        $groups = collect($this->actingAs($super, 'sanctum')->getJson('/api/admin/settings')->assertOk()->json('data'))->keyBy('key');
        $network = $groups['network'];
        $this->assertNotEmpty($network['guide']);
        $this->assertStringContainsString('autossh', collect($network['guide'])->pluck('code')->implode("\n"));
        $this->assertContains('pedigree.network.foreign_proxy', collect($network['fields'])->pluck('key')->all());
    }
}
