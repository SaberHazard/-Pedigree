<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResetScopedServices;
use App\Http\Middleware\ResolveViewer;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // سشن کوکی‌محور برای وب‌اپ روی همان دامنه (Sanctum SPA) + توکن برای اپ موبایل
        $middleware->statefulApi();
        // بازگشت درگاه پرداخت با POST از سایت درگاه می‌آید (توکن تصادفی خودش را دارد)
        $middleware->validateCsrfTokens(except: ['donate/callback/*']);
        $middleware->prepend(ResetScopedServices::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureAdmin::class,
            'super-admin' => EnsureSuperAdmin::class,
            'viewer' => ResolveViewer::class,
        ]);

        // اگر سایت پشت CDN/پروکسی (مثل ابرآروان یا Cloudflare) است، IP واقعی کاربر از این هدرها خوانده شود
        $proxies = env('TRUSTED_PROXIES');
        if ($proxies) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'لطفاً ابتدا وارد حساب کاربری شوید.'], 401);
            }
        });

        // پیام‌های فارسی برای خطاهای استاندارد HTTP
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }
            $status = $e->getStatusCode();
            $defaults = [
                400 => 'درخواست نامعتبر است.',
                401 => 'لطفاً ابتدا وارد حساب کاربری شوید.',
                403 => 'شما اجازه انجام این کار را ندارید.',
                404 => 'مورد درخواستی پیدا نشد.',
                405 => 'این روش درخواست مجاز نیست.',
                413 => 'حجم فایل بیش از حد مجاز سرور است.',
                419 => 'نشست شما منقضی شده است؛ صفحه را دوباره بارگذاری کنید.',
                429 => 'تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.',
                500 => 'خطای داخلی سرور رخ داد.',
                503 => 'سرویس موقتاً در دسترس نیست.',
            ];
            $message = $e->getMessage();
            // پیام‌های انگلیسی پیش‌فرض فریم‌ورک با پیام فارسی جایگزین می‌شوند
            if ($message === '' || preg_match('/^[\x00-\x7F]*$/', $message)) {
                $message = $defaults[$status] ?? 'خطایی رخ داد.';
            }

            return response()->json(['message' => $message], $status, $e->getHeaders());
        });
    })->create();
