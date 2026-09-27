<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\MetaController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * صفحه واحد (Single Page App) وب‌اپ.
 * همه مسیرهای رابط کاربری با # مدیریت می‌شوند و فقط همین یک صفحه از سرور بارگذاری می‌شود.
 */
class SpaController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        if ($user && (! $user->isActive() || $user->person?->is_deceased)) {
            $user = null;
        }

        $boot = [
            'config' => app(MetaController::class)->bootstrap()->getData(true),
            // کاربر واردشده همراه صفحه ارسال می‌شود تا درخواست اضافه لازم نباشد
            'user' => $user ? (new UserResource($user->load('person')))->toArray($request) : null,
        ];

        return response()
            ->view('app', [
                'importMap' => $this->importMap(),
                'assetVersion' => $this->version(public_path('assets/css/app.css')),
                'boot' => $boot,
            ])
            // این صفحه اطلاعات کاربر را دارد؛ نباید کش شود
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * نقشه import برای ماژول‌های JS با نسخه (بر اساس زمان تغییر فایل).
     * این باعث می‌شود بعد از هر به‌روزرسانی، مرورگر فایل جدید را بگیرد (بدون نیاز به build).
     */
    private function importMap(): array
    {
        $map = [];
        $base = public_path('assets/js');
        if (! is_dir($base)) {
            return $map;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'js') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(public_path())));
            $map[$relative] = $relative.'?v='.$file->getMTime();
        }
        ksort($map);

        return $map;
    }

    private function version(string $path): string
    {
        return is_file($path) ? (string) filemtime($path) : '1';
    }
}
