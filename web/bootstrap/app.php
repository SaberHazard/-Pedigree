<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResetScopedServices;
use App\Http\Middleware\ResolveViewer;
use App\Http\Middleware\SecurityHeaders;
use App\Services\Sms\SmsException;
use App\Support\ErrorReporter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
        // پیام‌های راه‌اندازی تماس (SDP) باید دست‌نخورده برسند؛ حذف «\r\n» پایانی آن‌ها را نامعتبر می‌کند
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/calls/*/signal')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/calls/*/signal')]);
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
        // خطاهای «قانون کسب‌وکار» (پیام فارسی برای کاربر، مثل «این شخص از قبل پدر دارد») خطای سرور نیستند
        // و ثبتشان در لاگ فقط دیسک را پر می‌کند (راهی برای حمله با درخواست‌های پیاپی)
        $exceptions->dontReport([DomainException::class, SmsException::class]);

        // هر خطای پیش‌بینی‌نشده با کد پیگیری در پنل مدیریت (بخش «خطاها») ثبت می‌شود
        $exceptions->report(function (Throwable $e) {
            ErrorReporter::record($e, app()->runningInConsole() && ! app()->runningUnitTests() ? null : request());
        });

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

        // هر خطای دیگر: هیچ جزئیاتی (مسیر فایل، آدرس سرور، پرس‌وجو) به کاربر نشان داده نمی‌شود؛
        // فقط پیام فارسی و کد پیگیری. جزئیات فقط در لاگ سرور و خلاصه پاک‌شده‌اش در پنل مدیریت است.
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface || $e instanceof ValidationException || $e instanceof AuthenticationException) {
                return null;
            }
            // فقط روی رایانه برنامه‌نویس با APP_DEBUG=true جزئیات کامل نمایش داده می‌شود
            if (config('app.debug') && app()->environment('local')) {
                return null;
            }
            $ref = ErrorReporter::refFor($e);
            $message = 'متأسفانه خطای پیش‌بینی‌نشده‌ای رخ داد و برای مدیر سایت ثبت شد.'.($ref ? " کد پیگیری: {$ref}" : '');
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $message, 'code' => 'server_error', 'ref' => $ref], 500);
            }

            return response()->view('errors.500', ['ref' => $ref], 500);
        });
    })->create();
