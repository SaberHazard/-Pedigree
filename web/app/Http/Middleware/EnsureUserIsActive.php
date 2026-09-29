<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * کاربر مسدود یا متعلق به شخص درگذشته/حذف‌شده نمی‌تواند از API استفاده کند.
 * کاربر «در انتظار تأیید عضویت» فقط به مسیرهایی که با active:pending علامت خورده‌اند (مثل /auth/me) دسترسی دارد.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next, ?string $allow = null): Response
    {
        $user = $request->user();
        if ($user?->isPending()) {
            if ($allow === 'pending') {
                return $next($request);
            }

            return response()->json(['message' => 'عضویت شما هنوز در انتظار تأیید مدیر سایت است.', 'code' => 'pending_approval'], 403);
        }
        if ($user) {
            $person = $user->person;
            if (! $user->isActive() || ($person === null && ! $user->isAdmin()) || $person?->is_deceased) {
                return response()->json(['message' => 'حساب کاربری شما غیرفعال است.'], 403);
            }
        }

        return $next($request);
    }
}
