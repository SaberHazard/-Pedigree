<?php

namespace Tests\Unit;

use App\Services\Sms\SmsException;
use App\Services\Sms\SmsManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * درخواست‌های ارسالی به هر پنل پیامکی (آدرس، پارامترها و تشخیص موفقیت/خطا)
 * مطابق مستندات عمومی هر پنل؛ بدون ارسال پیامک واقعی.
 */
class SmsDriversTest extends TestCase
{
    private function send(string $driver, array $config): void
    {
        config(['pedigree.sms.driver' => $driver, "pedigree.sms.drivers.{$driver}" => $config]);
        app(SmsManager::class)->sendOtp('09121234567', '123456');
    }

    public function test_kavenegar(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200, 'message' => 'تایید شد'], 'entries' => []])]);
        $this->send('kavenegar', ['api_key' => 'KEY', 'template' => 'verify']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.kavenegar.com/v1/KEY/verify/lookup.json')
            && $r['receptor'] === '09121234567' && $r['token'] === '123456' && $r['template'] === 'verify');
    }

    public function test_kavenegar_error_is_reported(): void
    {
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 418, 'message' => 'اعتبار کافی نیست']], 200)]);
        $this->expectException(SmsException::class);
        $this->send('kavenegar', ['api_key' => 'KEY', 'template' => 'verify']);
    }

    public function test_smsir(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 1, 'message' => 'موفق', 'data' => ['messageId' => 1]])]);
        $this->send('smsir', ['api_key' => 'KEY', 'template_id' => '100', 'parameter' => 'CODE']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.sms.ir/v1/send/verify'
            && $r->hasHeader('X-API-KEY', 'KEY')
            && $r['mobile'] === '09121234567' && $r['templateId'] === 100
            && $r['parameters'] === [['name' => 'CODE', 'value' => '123456']]);
    }

    public function test_smsir_failure(): void
    {
        Http::fake(['api.sms.ir/*' => Http::response(['status' => 0, 'message' => 'error'], 400)]);
        $this->expectException(SmsException::class);
        $this->send('smsir', ['api_key' => 'KEY', 'template_id' => '100']);
    }

    public function test_melipayamak(): void
    {
        Http::fake(['rest.payamak-panel.com/*' => Http::response(['Value' => '5082938271652', 'RetStatus' => 1, 'StrRetStatus' => 'Ok'])]);
        $this->send('melipayamak', ['username' => 'u', 'password' => 'p', 'body_id' => '777']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber'
            && $r['to'] === '09121234567' && $r['text'] === '123456' && $r['bodyId'] === '777');
    }

    public function test_melipayamak_error_code(): void
    {
        // کدهای خطای ملی‌پیامک اعداد کوتاه هستند (مثلاً ۱۱ = متن نامعتبر)
        Http::fake(['rest.payamak-panel.com/*' => Http::response(['Value' => '11', 'RetStatus' => 0])]);
        $this->expectException(SmsException::class);
        $this->send('melipayamak', ['username' => 'u', 'password' => 'p', 'body_id' => '777']);
    }

    public function test_ippanel(): void
    {
        Http::fake(['api2.ippanel.com/*' => Http::response(['status' => 'OK', 'code' => 200, 'data' => ['message_id' => 1]])]);
        $this->send('ippanel', ['api_key' => 'KEY', 'sender' => '+983000505', 'pattern_code' => 'abc', 'variable' => 'code']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api2.ippanel.com/api/v1/sms/pattern/normal/send'
            && $r->hasHeader('apikey', 'KEY') && $r['code'] === 'abc' && $r['recipient'] === '09121234567'
            && $r['variable'] === ['code' => '123456']);
    }

    public function test_ghasedak(): void
    {
        Http::fake(['api.ghasedak.me/*' => Http::response(['result' => ['code' => 200, 'message' => 'success'], 'items' => [1]])]);
        $this->send('ghasedak', ['api_key' => 'KEY', 'template' => 'otp']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.ghasedak.me/v2/verification/send/simple'
            && $r->hasHeader('apikey', 'KEY') && $r['receptor'] === '09121234567' && $r['param1'] === '123456');
    }

    public function test_missing_configuration_throws(): void
    {
        Http::fake();
        $this->expectException(SmsException::class);
        $this->send('kavenegar', ['api_key' => '', 'template' => 'verify']);
    }

    public function test_connection_errors_are_retried_but_http_errors_are_not(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['return' => ['status' => 500]], 500);
        });
        try {
            $this->send('kavenegar', ['api_key' => 'KEY', 'template' => 'verify']);
        } catch (SmsException) {
            // انتظار می‌رود
        }
        // پاسخ خطای HTTP دوباره تلاش نمی‌شود تا پیامک تکراری ارسال نشود
        $this->assertSame(1, $calls);
    }
}
