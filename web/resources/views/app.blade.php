@php
    /** @var array $importMap */
    $nonce = app()->bound('csp-nonce') ? app('csp-nonce') : '';
    $base = rtrim(url('/'), '/');
    $imports = [];
    foreach ($importMap as $path => $versioned) {
        $imports[$base.$path] = $base.$versioned;
    }
@endphp
<!doctype html>
<html lang="fa" dir="rtl" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ config('pedigree.site_name') }}</title>
    <meta name="description" content="شجره‌نامه خانوادگی آنلاین: درخت خانواده، نیاکان و نوادگان، عکس‌ها و خاطرات">
    <meta name="theme-color" content="#f4f3ee">
    <meta name="base-url" content="{{ $base }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ config('pedigree.site_name') }}">
    <meta name="format-detection" content="telephone=no">
    <link rel="manifest" href="{{ $base }}/manifest.webmanifest">
    <link rel="icon" href="{{ $base }}/assets/img/icon.svg?v={{ $assetVersion }}" type="image/svg+xml">
    <link rel="icon" href="{{ $base }}/assets/img/icon-192.png?v={{ $assetVersion }}" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ $base }}/assets/img/apple-touch-icon.png?v={{ $assetVersion }}">
    <link rel="preload" href="{{ $base }}/assets/fonts/Vazirmatn-UI-FD-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="{{ $base }}/assets/fonts/Vazirmatn-UI-FD-Bold.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ $base }}/assets/css/app.css?v={{ $assetVersion }}">
    {{-- اعمال تم قبل از نمایش صفحه (جلوگیری از چشمک زدن) --}}
    <script nonce="{{ $nonce }}">
        (function () {
            try {
                var t = localStorage.getItem('theme') || 'auto';
                var dark = t === 'dark' || (t === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
            } catch (e) {}
        })();
    </script>
    {{-- نقشه نسخه ماژول‌ها: بعد از هر تغییر فایل، مرورگر نسخه جدید را می‌گیرد --}}
    <script type="importmap" nonce="{{ $nonce }}">@json(['imports' => $imports], JSON_UNESCAPED_SLASHES)</script>
    <script type="module" nonce="{{ $nonce }}" src="{{ $imports[$base.'/assets/js/main.js'] ?? $base.'/assets/js/main.js' }}"></script>
</head>
<body>
    <div id="top-progress" class="top-progress"></div>
    <div id="app">
        <div class="page-loader"><div class="spinner"></div></div>
    </div>
    <div id="toasts" class="toasts" aria-live="polite"></div>
    <div id="print-root"></div>
    <noscript>برای استفاده از شجره‌نامه باید جاوااسکریپت مرورگر فعال باشد.</noscript>
    <script type="application/json" id="boot-data">@json($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)</script>
</body>
</html>
