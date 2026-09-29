<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>خطا</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; font-family: Vazirmatn, Tahoma, sans-serif; background: #f6f4ef; color: #243034; padding: 16px; box-sizing: border-box; }
        .box { max-width: 420px; text-align: center; background: #fff; border-radius: 20px; padding: 28px 22px; box-shadow: 0 20px 50px -25px rgba(0,0,0,.35); }
        h1 { font-size: 1.2rem; margin: 0 0 10px; }
        p { line-height: 1.9; margin: 0 0 14px; color: #5b6b6f; }
        code { direction: ltr; display: inline-block; background: #eef4f2; color: #2c7a6f; padding: 2px 10px; border-radius: 8px; font-weight: 700; letter-spacing: 1px; }
        a { display: inline-block; margin-top: 6px; background: #3f9589; color: #fff; text-decoration: none; padding: 9px 20px; border-radius: 12px; }
        @media (prefers-color-scheme: dark) { body { background: #111a1c; color: #e4eeee; } .box { background: #182427; } p { color: #9fb0b2; } code { background: #213236; color: #7fd1c4; } }
    </style>
</head>
<body>
<div class="box">
    <h1>متأسفانه خطایی رخ داد</h1>
    <p>این خطا خودکار برای مدیر سایت ثبت شد. لطفاً چند لحظه بعد دوباره امتحان کنید.</p>
    @if (! empty($ref))
        <p>کد پیگیری: <code>{{ $ref }}</code></p>
    @endif
    <a href="{{ url('/') }}">بازگشت به صفحه اصلی</a>
</div>
</body>
</html>
