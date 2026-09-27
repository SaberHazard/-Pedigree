<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * پاک‌کردن کش سرویس‌های «یک‌نمونه برای هر درخواست» (Kinship، PersonAccess) در ابتدای هر درخواست.
 *
 * PHP-FPM برای هر درخواست از نو شروع می‌کند، ولی در اجراهای ماندگار (Octane، تست‌ها)
 * نباید نتیجه دسترسی یک درخواست به درخواست بعدی برسد (مثلاً بعد از تغییر نسبت‌ها).
 */
class ResetScopedServices
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->forgetScopedInstances();

        return $next($request);
    }
}
