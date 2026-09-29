<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\User;
use App\Services\Social\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * حمایت از سازنده: درگاه پرداخت (تأیید سرور-به-سرور، ضد جعل و تکرار) و حساب‌های بانکی
 */
class DonationTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        SafeHttp::fakeResolver(fn () => ['185.143.233.10']);
        config([
            'app.url' => 'https://family.example',
            'pedigree.social.proxy' => null,
            'pedigree.donate.gateway' => 'zarinpal',
            'pedigree.donate.zarinpal.merchant_id' => 'MERCHANT-UUID',
        ]);
        $this->member = User::factory()->withPerson(['first_name' => 'علی'])->create()->refresh();
        $this->super = User::factory()->withPerson(['first_name' => 'مدیر'])->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
    }

    public function test_zarinpal_donation_is_paid_only_after_server_verification(): void
    {
        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::sequence()
                ->push(['data' => ['code' => 100, 'authority' => 'A000000000000000000000000000000abcd'], 'errors' => []])
                ->push(['data' => ['code' => 100, 'authority' => 'A000000000000000000000000000000abce'], 'errors' => []]),
            'payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response(['data' => ['code' => 100, 'ref_id' => 201, 'card_pan' => '502229******5995'], 'errors' => []]),
        ]);
        $this->actingAs($this->member, 'sanctum');
        $this->getJson('/api/donate')->assertOk()->assertJsonPath('data.gateway.key', 'zarinpal');
        // مبلغ خارج از بازه
        $this->postJson('/api/donate', ['amount' => 500])->assertStatus(422);

        $res = $this->postJson('/api/donate', ['amount' => 100000, 'message' => 'دستتان درد نکند 🌹'])->assertCreated();
        $this->assertSame('https://payment.zarinpal.com/pg/StartPay/A000000000000000000000000000000abcd', $res->json('data.url'));
        $donation = Donation::query()->firstOrFail();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'request.json')
            && $r['amount'] === 1000000 // تومان ← ریال
            && $r['merchant_id'] === 'MERCHANT-UUID'
            && str_starts_with($r['callback_url'], 'https://family.example/donate/callback/'));

        // بازگشت جعلی: authority دیگر → ناموفق و بدون تأیید
        $token = $donation->callback_token;
        $this->get("/donate/callback/{$token}?Authority=A999&Status=OK")->assertRedirect('/#/donate?result=failed&id='.$donation->id);
        $this->assertSame('failed', $donation->fresh()->status);

        // پرداخت درست دوم
        $this->postJson('/api/donate', ['amount' => 100000])->assertCreated();
        $second = Donation::query()->latest('id')->first();
        // درگاه با POST برمی‌گردد (بدون توکن CSRF)
        $this->post("/donate/callback/{$second->callback_token}", ['Authority' => 'A000000000000000000000000000000abce', 'Status' => 'OK'])
            ->assertRedirect('/#/donate?result=paid&id='.$second->id);
        $second->refresh();
        $this->assertSame('paid', $second->status);
        $this->assertSame('201', $second->ref_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'verify.json') && $r['amount'] === 1000000 && $r['authority'] === 'A000000000000000000000000000000abce');

        // تکرار بازگشت: دوباره تأیید نمی‌شود
        $verifies = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'verify.json'))->count();
        $this->get("/donate/callback/{$second->callback_token}?Authority=A000000000000000000000000000000abce&Status=OK")->assertRedirect();
        $this->assertSame($verifies, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'verify.json'))->count());

        // توکن ناشناخته
        $this->get('/donate/callback/'.str_repeat('x', 48))->assertRedirect('/#/donate?result=failed');
        $this->get('/donate/callback/short')->assertNotFound();

        $this->getJson("/api/donate/{$second->id}")->assertJsonPath('data.status', 'paid');
        // دیوار سپاس و پرداخت‌های خودم
        $this->getJson('/api/donate')->assertJsonPath('data.thanks.0.name', $this->member->person->fullName())->assertJsonPath('data.mine.0.ref', '201');
        // پرداخت دیگران دیده نمی‌شود
        $this->actingAs($this->super, 'sanctum')->getJson("/api/donate/{$second->id}")->assertNotFound();
        $this->actingAs($this->member, 'sanctum');
        $this->assertSame('دستتان درد نکند 🌹', Donation::query()->first()->message);
    }

    public function test_cancelled_payment_is_not_verified(): void
    {
        Http::fake([
            'payment.zarinpal.com/pg/v4/payment/request.json' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000000beef']]),
            'payment.zarinpal.com/*' => Http::response(['data' => ['code' => 100, 'ref_id' => 1]]),
        ]);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/donate', ['amount' => 50000])->assertCreated();
        $d = Donation::query()->firstOrFail();
        $this->get("/donate/callback/{$d->callback_token}?Authority={$d->authority}&Status=NOK")->assertRedirect();
        $this->assertSame('failed', $d->fresh()->status);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'verify.json'));
    }

    public function test_bank_accounts_are_validated_and_only_super_admin_manages_them(): void
    {
        $this->actingAs($this->member, 'sanctum')->postJson('/api/admin/donation-accounts', ['kind' => 'card', 'title' => 'x', 'value' => '6037991199500590'])->assertForbidden();

        $this->actingAs($this->super, 'sanctum');
        $this->postJson('/api/admin/donation-accounts', ['kind' => 'card', 'title' => 'بانک ملی', 'value' => '6037-9911-9950-0591'])->assertStatus(422);
        $this->postJson('/api/admin/donation-accounts', ['kind' => 'card', 'title' => 'بانک ملی', 'holder' => 'صابر', 'value' => '۶۰۳۷-۹۹۱۱-۹۹۵۰-۰۵۹۰'])->assertCreated()->assertJsonPath('data.value', '6037991199500590');
        $this->postJson('/api/admin/donation-accounts', ['kind' => 'sheba', 'title' => 'شبا', 'value' => '820540102680020817909003'])->assertStatus(422);
        $this->postJson('/api/admin/donation-accounts', ['kind' => 'sheba', 'title' => 'شبا', 'value' => '820540102680020817909002'])->assertCreated()->assertJsonPath('data.value', 'IR820540102680020817909002');
        $this->postJson('/api/admin/donation-accounts', ['kind' => 'link', 'title' => 'بلو', 'value' => 'javascript:alert(1)'])->assertStatus(422);
        $link = $this->postJson('/api/admin/donation-accounts', ['kind' => 'link', 'title' => 'لینک پرداخت بلو', 'value' => 'https://blu.bank/pay/example'])->assertCreated()->json('data.id');
        $this->putJson("/api/admin/donation-accounts/{$link}", ['kind' => 'link', 'title' => 'لینک', 'value' => 'https://blu.bank/pay/example', 'active' => false])->assertOk();

        // اعضا فقط حساب‌های فعال را می‌بینند
        $accounts = $this->actingAs($this->member, 'sanctum')->getJson('/api/donate')->json('data.accounts');
        $this->assertCount(2, $accounts);
        $this->assertSame(['card', 'sheba'], array_column($accounts, 'kind'));

        $this->actingAs($this->super, 'sanctum')->getJson('/api/admin/donations')->assertOk()->assertJsonPath('totals.count', 0);
    }

    public function test_zibal_verify_requires_the_same_amount(): void
    {
        config(['pedigree.donate.gateway' => 'zibal', 'pedigree.donate.zibal.merchant' => 'ZIBAL-M']);
        Http::fake([
            'gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 3714061657]),
            'gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'amount' => 10, 'refNumber' => 99]),
        ]);
        $res = $this->actingAs($this->member, 'sanctum')->postJson('/api/donate', ['amount' => 20000])->assertCreated();
        $this->assertSame('https://gateway.zibal.ir/start/3714061657', $res->json('data.url'));
        $d = Donation::query()->firstOrFail();
        $this->get("/donate/callback/{$d->callback_token}?trackId=3714061657&success=1&status=2")->assertRedirect('/#/donate?result=failed&id='.$d->id);
        $this->assertSame('failed', $d->fresh()->status);
    }

    public function test_gateway_ignores_the_foreign_social_proxy(): void
    {
        // سرور در ایران با پراکسی خارجی برای شبکه‌های اجتماعی: درگاه ایرانی باید مستقیم وصل شود
        $resolved = [];
        SafeHttp::fakeResolver(function (string $host) use (&$resolved) {
            $resolved[] = $host;

            return ['185.143.233.10'];
        });
        config(['pedigree.social.proxy' => 'socks5h://127.0.0.1:1080', 'pedigree.iran_proxy' => null]);
        Http::fake(['payment.zarinpal.com/*' => Http::response(['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000000cafe']])]);
        $this->actingAs($this->member, 'sanctum')->postJson('/api/donate', ['amount' => 100000])->assertCreated();
        $this->assertSame(['payment.zarinpal.com'], $resolved);
    }

    public function test_disabled_page_hides_everything(): void
    {
        config(['pedigree.donate.enabled' => false]);
        $this->actingAs($this->member, 'sanctum')->getJson('/api/donate')->assertOk()->assertExactJson(['data' => ['enabled' => false]]);
        $this->postJson('/api/donate', ['amount' => 100000])->assertStatus(503);
    }

    public function test_without_gateway_only_accounts_are_shown(): void
    {
        config(['pedigree.donate.gateway' => '']);
        $this->actingAs($this->member, 'sanctum')->getJson('/api/donate')->assertOk()->assertJsonPath('data.gateway', null);
        $this->postJson('/api/donate', ['amount' => 100000])->assertStatus(503)->assertJsonPath('code', 'donate_off');
    }
}
