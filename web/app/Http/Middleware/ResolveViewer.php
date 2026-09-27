<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * برای مسیرهای «فقط خواندنی» (درخت، پروفایل): اگر حالت مهمان فعال باشد
 * بدون ورود هم قابل مشاهده‌اند؛ وگرنه ورود لازم است.
 */
class ResolveViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('sanctum');

        if (! $request->user() && ! config('pedigree.guest_view')) {
            return response()->json(['message' => 'لطفاً ابتدا وارد حساب کاربری شوید.'], 401);
        }

        return $next($request);
    }
}
