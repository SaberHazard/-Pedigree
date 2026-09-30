<?php

namespace App\Services\Donate;

use App\Exceptions\DomainException;
use App\Models\Donation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Social\SafeHttp;
use App\Support\Outbound;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * حمایت از سازنده با درگاه پرداخت (زرین‌پال، زیبال، Pay.ir)
 *
 * امنیت:
 *  - مبلغ فقط در سرور ثبت می‌شود و هنگام تأیید همان مبلغ به درگاه فرستاده می‌شود
 *  - «پرداخت‌شده» فقط با تأیید سرور-به-سرور درگاه (نه پارامترهای بازگشت کاربر)
 *  - هر پرداخت یک توکن تصادفی بازگشت دارد و فقط یک بار تأیید می‌شود (قفل ردیف)
 *  - درخواست‌ها فقط https به دامنه‌های ثابت درگاه (ضد SSRF) و با سقف دفعات
 */
class DonationService
{
    public const GATEWAYS = [
        'zarinpal' => 'زرین‌پال',
        'zibal' => 'زیبال',
        'payir' => 'Pay.ir',
    ];

    public function __construct(private readonly SafeHttp $http, private readonly AuditLogger $audit) {}

    public function gateway(): ?string
    {
        $g = (string) config('pedigree.donate.gateway', '');

        return isset(self::GATEWAYS[$g]) && $this->key($g) !== '' ? $g : null;
    }

    private function key(string $gateway): string
    {
        return (string) match ($gateway) {
            'zarinpal' => config('pedigree.donate.zarinpal.merchant_id'),
            'zibal' => config('pedigree.donate.zibal.merchant'),
            'payir' => config('pedigree.donate.payir.api'),
            default => '',
        };
    }

    private function sandbox(): bool
    {
        return (bool) config('pedigree.donate.sandbox', false);
    }

    public function limits(): array
    {
        $min = max(1000, (int) config('pedigree.donate.min_amount', 10000));
        $max = max($min, (int) config('pedigree.donate.max_amount', 50000000));
        $suggested = array_values(array_filter(array_map('intval', preg_split('/[,،\s]+/u', (string) config('pedigree.donate.suggested', '50000,100000,200000,500000')) ?: []), fn ($v) => $v >= $min && $v <= $max));

        return ['min' => $min, 'max' => $max, 'suggested' => array_slice($suggested, 0, 6)];
    }

    /**
     * ساخت پرداخت و گرفتن آدرس درگاه
     *
     * @return array{donation: Donation, url: string}
     *
     * @throws DomainException
     */
    public function start(User $user, int $amount, ?string $message, bool $anonymous, ?string $ip): array
    {
        $gateway = $this->gateway();
        if (! config('pedigree.donate.enabled', true) || $gateway === null) {
            throw new DomainException('پرداخت آنلاین فعال نیست؛ می‌توانید از شماره کارت یا حساب‌های همین صفحه استفاده کنید.', 503, 'donate_off');
        }
        $limits = $this->limits();
        if ($amount < $limits['min'] || $amount > $limits['max']) {
            throw new DomainException('مبلغ باید بین '.number_format($limits['min']).' و '.number_format($limits['max']).' تومان باشد.');
        }
        if (RateLimiter::tooManyAttempts('donate:'.$user->id, 6)) {
            throw new DomainException('چند بار پشت سر هم تلاش کرده‌اید؛ چند دقیقه دیگر دوباره امتحان کنید.', 429);
        }
        RateLimiter::hit('donate:'.$user->id, 600);

        $message = $message !== null ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $message) ?? ''), 0, 200) : null;
        $donation = Donation::create([
            'user_id' => $user->id,
            'gateway' => $gateway,
            'amount' => $amount,
            'callback_token' => Str::random(48),
            'message' => $message ?: null,
            'anonymous' => $anonymous,
            'ip' => $ip,
        ]);
        $callback = rtrim((string) config('app.url'), '/').'/donate/callback/'.$donation->callback_token;
        $description = mb_substr('حمایت از '.config('pedigree.site_name'), 0, 100);
        $rial = $amount * 10;

        try {
            [$authority, $url] = match ($gateway) {
                'zarinpal' => $this->zarinpalRequest($rial, $callback, $description),
                'zibal' => $this->zibalRequest($rial, $callback, $description, $donation->id),
                'payir' => $this->payirRequest($rial, $callback, $description, $donation->id),
            };
        } catch (DomainException $e) {
            $donation->forceFill(['status' => Donation::STATUS_FAILED])->save();
            throw $e;
        }
        try {
            $donation->forceFill(['authority' => $authority])->save();
        } catch (QueryException) {
            // شناسه تکراری از درگاه (نباید پیش بیاید): این پرداخت کنار گذاشته می‌شود
            $donation->forceFill(['authority' => null, 'status' => Donation::STATUS_FAILED])->save();
            throw new DomainException('درگاه پرداخت پاسخ نامعتبری داد؛ دوباره امتحان کنید.', 502, 'donate_gateway');
        }

        return ['donation' => $donation, 'url' => $url];
    }

    /**
     * بازگشت از درگاه: تأیید سرور-به-سرور (فقط یک بار)
     *
     * @param  array<string, mixed>  $query  پارامترهای بازگشت درگاه
     */
    public function complete(string $token, array $query): Donation
    {
        return DB::transaction(function () use ($token, $query) {
            /** @var Donation|null $donation */
            $donation = Donation::query()->where('callback_token', $token)->lockForUpdate()->first();
            if ($donation === null) {
                throw new DomainException('پرداخت پیدا نشد.', 404);
            }
            if ($donation->status !== Donation::STATUS_PENDING) {
                return $donation;
            }
            // پارامتر بازگشت باید همان شناسه‌ای باشد که درگاه به ما داده بود
            $returned = (string) ($query['Authority'] ?? $query['authority'] ?? $query['trackId'] ?? $query['token'] ?? '');
            $cancelled = match ($donation->gateway) {
                'zarinpal' => ($query['Status'] ?? '') !== 'OK',
                'zibal' => (string) ($query['success'] ?? '') !== '1',
                'payir' => (string) ($query['status'] ?? '') !== '1',
                default => true,
            };
            if ($returned === '' || ! hash_equals((string) $donation->authority, $returned) || $cancelled) {
                $donation->forceFill(['status' => Donation::STATUS_FAILED])->save();

                return $donation;
            }

            try {
                $result = match ($donation->gateway) {
                    'zarinpal' => $this->zarinpalVerify($donation),
                    'zibal' => $this->zibalVerify($donation),
                    'payir' => $this->payirVerify($donation),
                };
            } catch (Throwable $e) {
                Log::warning('Donation verify failed', ['donation' => $donation->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
                $result = null;
            }
            if ($result === null) {
                $donation->forceFill(['status' => Donation::STATUS_FAILED])->save();

                return $donation;
            }
            $donation->forceFill([
                'status' => Donation::STATUS_PAID,
                'ref_id' => mb_substr((string) $result['ref'], 0, 120),
                'card' => $result['card'] ? mb_substr((string) $result['card'], 0, 30) : null,
                'paid_at' => now(),
            ])->save();
            $this->audit->log('donation.paid', null, ['donation' => $donation->id, 'amount' => $donation->amount, 'gateway' => $donation->gateway], $donation->user);

            return $donation;
        });
    }

    // ------------------------------------------------------------------ زرین‌پال

    private function zarinpalHost(): string
    {
        return $this->sandbox() ? 'sandbox.zarinpal.com' : 'payment.zarinpal.com';
    }

    private function zarinpalRequest(int $rial, string $callback, string $description): array
    {
        $json = $this->post('https://'.$this->zarinpalHost().'/pg/v4/payment/request.json', [$this->zarinpalHost()], [
            'merchant_id' => $this->key('zarinpal'),
            'amount' => $rial,
            'currency' => 'IRR',
            'description' => $description,
            'callback_url' => $callback,
        ]);
        $authority = (string) ($json['data']['authority'] ?? '');
        if ((int) ($json['data']['code'] ?? 0) !== 100 || ! preg_match('/^[A-Za-z0-9]{10,64}$/', $authority)) {
            $this->fail('zarinpal', $json);
        }

        return [$authority, 'https://'.$this->zarinpalHost().'/pg/StartPay/'.$authority];
    }

    private function zarinpalVerify(Donation $d): ?array
    {
        $json = $this->post('https://'.$this->zarinpalHost().'/pg/v4/payment/verify.json', [$this->zarinpalHost()], [
            'merchant_id' => $this->key('zarinpal'),
            'amount' => $d->amount * 10,
            'authority' => $d->authority,
        ]);
        $code = (int) ($json['data']['code'] ?? 0);

        return in_array($code, [100, 101], true) ? ['ref' => $json['data']['ref_id'] ?? '', 'card' => $json['data']['card_pan'] ?? null] : null;
    }

    // ------------------------------------------------------------------ زیبال

    private function zibalRequest(int $rial, string $callback, string $description, int $orderId): array
    {
        $json = $this->post('https://gateway.zibal.ir/v1/request', ['gateway.zibal.ir'], [
            'merchant' => $this->sandbox() ? 'zibal' : $this->key('zibal'),
            'amount' => $rial,
            'callbackUrl' => $callback,
            'description' => $description,
            'orderId' => (string) $orderId,
        ]);
        $track = (string) ($json['trackId'] ?? '');
        if ((int) ($json['result'] ?? 0) !== 100 || ! preg_match('/^\d{3,20}$/', $track)) {
            $this->fail('zibal', $json);
        }

        return [$track, 'https://gateway.zibal.ir/start/'.$track];
    }

    private function zibalVerify(Donation $d): ?array
    {
        $json = $this->post('https://gateway.zibal.ir/v1/verify', ['gateway.zibal.ir'], [
            'merchant' => $this->sandbox() ? 'zibal' : $this->key('zibal'),
            'trackId' => (int) $d->authority,
        ]);
        $result = (int) ($json['result'] ?? 0);
        // ۱۰۰ = تأیید شد (مبلغ باید دقیقاً همان باشد)، ۲۰۱ = قبلاً تأیید شده (پاسخ ممکن است مبلغ نداشته باشد)
        $amountOk = isset($json['amount']) ? (int) $json['amount'] === $d->amount * 10 : $result === 201;
        $ok = in_array($result, [100, 201], true) && $amountOk;

        return $ok ? ['ref' => $json['refNumber'] ?? $d->authority, 'card' => $json['cardNumber'] ?? null] : null;
    }

    // ------------------------------------------------------------------ Pay.ir

    private function payirRequest(int $rial, string $callback, string $description, int $orderId): array
    {
        $json = $this->post('https://pay.ir/pg/send', ['pay.ir'], [
            'api' => $this->sandbox() ? 'test' : $this->key('payir'),
            'amount' => $rial,
            'redirect' => $callback,
            'factorNumber' => (string) $orderId,
            'description' => $description,
        ]);
        $token = (string) ($json['token'] ?? '');
        if ((int) ($json['status'] ?? 0) !== 1 || ! preg_match('/^[A-Za-z0-9]{6,64}$/', $token)) {
            $this->fail('payir', $json);
        }

        return [$token, 'https://pay.ir/pg/'.$token];
    }

    private function payirVerify(Donation $d): ?array
    {
        $json = $this->post('https://pay.ir/pg/verify', ['pay.ir'], [
            'api' => $this->sandbox() ? 'test' : $this->key('payir'),
            'token' => $d->authority,
        ]);
        $ok = (int) ($json['status'] ?? 0) === 1 && (int) ($json['amount'] ?? 0) === $d->amount * 10;

        return $ok ? ['ref' => $json['transId'] ?? '', 'card' => $json['cardNumber'] ?? null] : null;
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function post(string $url, array $hosts, array $body): array
    {
        $res = $this->http->postJson($url, $hosts, $body, 256 * 1024, ['Accept' => 'application/json'], Outbound::iran() ?? SafeHttp::DIRECT, 20);
        $json = json_decode($res['body'], true);

        return is_array($json) ? $json + ['_status' => $res['status']] : ['_status' => $res['status']];
    }

    private function fail(string $gateway, array $json): never
    {
        Log::warning('Donation gateway request failed', ['gateway' => $gateway, 'status' => $json['_status'] ?? null, 'response' => mb_substr(json_encode(array_diff_key($json, ['_status' => 1]), JSON_UNESCAPED_UNICODE) ?: '', 0, 300)]);

        throw new DomainException('اتصال به درگاه پرداخت ممکن نشد؛ کمی بعد دوباره امتحان کنید یا از شماره کارت استفاده کنید.', 502, 'donate_gateway');
    }
}
