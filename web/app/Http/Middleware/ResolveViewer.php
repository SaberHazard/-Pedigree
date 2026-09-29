<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * مسیرهای «فقط خواندنی» (درخت، پروفایل): فقط اعضای واردشده.
 * هیچ بخشی از شجره‌نامه بدون ورود دیده نمی‌شود (حریم خاندان).
 */
class ResolveViewer
{
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('sanctum');

        if (! $request->user()) {
            return response()->json(['message' => 'لطفاً ابتدا وارد حساب کاربری شوید.'], 401);
        }

        return $next($request);
    }
}
