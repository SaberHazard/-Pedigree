<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** فقط مدیر کل (مالک سایت) */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperAdmin()) {
            return response()->json(['message' => 'این بخش فقط برای مدیر کل سایت است.'], 403);
        }

        return $next($request);
    }
}
