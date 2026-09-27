<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/**
 * صفحه واحد (Single Page App) وب‌اپ.
 * همه مسیرهای رابط کاربری با # مدیریت می‌شوند و فقط همین یک صفحه از سرور بارگذاری می‌شود.
 */
class SpaController extends Controller
{
    public function __invoke(): View
    {
        return view('app', [
            'importMap' => $this->importMap(),
            'assetVersion' => $this->version(public_path('assets/css/app.css')),
        ]);
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
