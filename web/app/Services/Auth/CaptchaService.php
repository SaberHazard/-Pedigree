<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * کپچای تصویری سبک (بدون سرویس خارجی) برای جلوگیری از «اس‌ام‌اس بمبینگ».
 * فقط وقتی فعال می‌شود که از یک IP درخواست‌های زیادی برای کد پیامکی ارسال شود.
 */
class CaptchaService
{
    private const TTL = 300;

    /** @return array{id:string, image:string} */
    public function generate(): array
    {
        $answer = (string) random_int(10000, 99999);
        $id = (string) Str::uuid();
        Cache::put('captcha:'.$id, hash('sha256', $answer), self::TTL);

        return ['id' => $id, 'image' => $this->render($answer)];
    }

    public function verify(?string $id, ?string $answer): bool
    {
        if (! $id || ! $answer) {
            return false;
        }
        $expected = Cache::pull('captcha:'.$id);

        return $expected !== null && hash_equals($expected, hash('sha256', trim($answer)));
    }

    /** ساخت تصویر PNG کپچا با GD به صورت data URI */
    private function render(string $text): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            // اگر GD نصب نباشد، متن ساده (بسیار نادر)
            return 'data:text/plain;base64,'.base64_encode($text);
        }

        $small = imagecreatetruecolor(70, 22);
        imagefill($small, 0, 0, imagecolorallocate($small, 245, 243, 238));
        $x = 6;
        foreach (str_split($text) as $char) {
            $color = imagecolorallocate($small, random_int(10, 90), random_int(40, 100), random_int(60, 120));
            imagestring($small, 5, $x, random_int(1, 6), $char, $color);
            $x += random_int(11, 13);
        }

        $w = 210;
        $h = 66;
        $img = imagecreatetruecolor($w, $h);
        imagecopyresampled($img, $small, 0, 0, 0, 0, $w, $h, 70, 22);
        for ($i = 0; $i < 6; $i++) {
            $c = imagecolorallocatealpha($img, random_int(80, 180), random_int(80, 180), random_int(80, 180), 60);
            imagesetthickness($img, 2);
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        for ($i = 0; $i < 350; $i++) {
            $c = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }

        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
