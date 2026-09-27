<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * کاربر مسدود یا متعلق به شخص درگذشته/حذف‌شده نمی‌تواند از API استفاده کند.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $person = $user->person;
            if (! $user->isActive() || ($person === null && ! $user->isAdmin()) || $person?->is_deceased) {
                return response()->json(['message' => 'حساب کاربری شما غیرفعال است.'], 403);
            }
        }

        return $next($request);
    }
}
