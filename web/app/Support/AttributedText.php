<?php

namespace App\Support;

/**
 * متن با «نویسنده هر کلمه».
 *
 * متن به صورت تکه‌هایی ذخیره می‌شود: [[user_id, "متن"], [user_id, "متن"], ...]
 * وقتی کسی متن را ویرایش می‌کند، نسخه جدید کلمه‌به‌کلمه با نسخه قبل مقایسه
 * می‌شود (الگوریتم Myers، ابتدا در سطح خط و سپس در سطح کلمه):
 *   - کلمه‌هایی که دست نخورده‌اند نویسنده قبلی‌شان را نگه می‌دارند؛
 *   - کلمه‌های تازه یا تغییرکرده (حتی اصلاح یک غلط املایی) به نام ویرایشگر ثبت می‌شوند؛
 *   - کلمه‌های حذف‌شده از متن کنار می‌روند (ولی در تاریخچه نسخه‌ها باقی می‌مانند).
 *
 * این کلاس هیچ وابستگی به پایگاه‌داده ندارد تا به‌سادگی تست شود.
 */
final class AttributedText
{
    /** سقف فاصله ویرایش برای مقایسه دقیق؛ فراتر از آن تکه تغییرکرده یکجا به ویرایشگر نسبت داده می‌شود */
    private const MAX_EDIT_DISTANCE = 4000;

    /** سقف تقریبی تعداد مقایسه‌ها در یک ذخیره (محدود کردن زمان پردازش متن‌های خیلی بلند) */
    private const WORK_BUDGET = 6_000_000;

    /**
     * اعمال متن جدید روی تکه‌های قبلی.
     *
     * @param  array<int, array{0: ?int, 1: string}>  $segments
     * @return array{segments: array<int, array{0: ?int, 1: string}>, added: int, removed: int}
     */
    public static function apply(array $segments, string $newText, ?int $editor): array
    {
        [$oldTokens, $oldAuthors] = self::flatten($segments);
        $newTokens = self::tokenize($newText);

        $authors = array_fill(0, count($newTokens), $editor);
        $added = 0;
        $removed = 0;

        // ۱) مقایسه خط‌به‌خط (پاراگراف اضافه‌شده فقط یک «درج» حساب شود)
        $oldLines = self::lines($oldTokens);
        $newLines = self::lines($newTokens);
        $lineOps = self::diff(array_column($oldLines, 'key'), array_column($newLines, 'key'))
            ?? self::replaceAll(count($oldLines), count($newLines));

        $delLines = [];
        $insLines = [];
        $flush = function () use (&$delLines, &$insLines, $oldLines, $newLines, $oldTokens, $newTokens, $oldAuthors, &$authors, &$added, &$removed) {
            if (! $delLines && ! $insLines) {
                return;
            }
            // ۲) خط‌های تغییرکرده: مقایسه کلمه‌به‌کلمه کل بلوک؛ اگر خیلی سنگین بود، خط‌به‌خط
            $pairs = [[$delLines, $insLines]];
            if (count($delLines) > 1 && count($insLines) > 1 && self::diffTokens($oldLines, $newLines, $delLines, $insLines, $oldTokens, $newTokens) === null) {
                $pairs = [];
                for ($p = 0, $count = max(count($delLines), count($insLines)); $p < $count; $p++) {
                    $pairs[] = [isset($delLines[$p]) ? [$delLines[$p]] : [], isset($insLines[$p]) ? [$insLines[$p]] : []];
                }
            }
            foreach ($pairs as [$del, $ins]) {
                $a = self::tokenRange($oldLines, $del);
                $b = self::tokenRange($newLines, $ins);
                $aTok = array_map(fn ($i) => $oldTokens[$i], $a);
                $bTok = array_map(fn ($i) => $newTokens[$i], $b);
                foreach (self::diff($aTok, $bTok) ?? self::trimmedReplace($aTok, $bTok) as [$op, $i, $j]) {
                    if ($op === '=') {
                        $authors[$b[$j]] = $oldAuthors[$a[$i]];
                    } elseif ($op === '+') {
                        $added += self::isWord($bTok[$j]) ? 1 : 0;
                    } else {
                        $removed += self::isWord($aTok[$i]) ? 1 : 0;
                    }
                }
            }
            $delLines = [];
            $insLines = [];
        };

        foreach ($lineOps as [$op, $i, $j]) {
            if ($op === '=') {
                $flush();
                foreach ($newLines[$j]['tokens'] as $n => $tokenIndex) {
                    $authors[$tokenIndex] = $oldAuthors[$oldLines[$i]['tokens'][$n]];
                }
            } elseif ($op === '-') {
                $delLines[] = $i;
            } else {
                $insLines[] = $j;
            }
        }
        $flush();

        return [
            'segments' => self::merge($newTokens, $authors),
            'added' => $added,
            'removed' => $removed,
        ];
    }

    /** متن ساده از روی تکه‌ها */
    public static function plain(array $segments): string
    {
        return implode('', array_map(fn ($s) => (string) ($s[1] ?? ''), $segments));
    }

    /** شناسه همه نویسندگان (به ترتیب اولین حضور در متن) */
    public static function authors(array $segments): array
    {
        $ids = [];
        foreach ($segments as $segment) {
            $id = $segment[0] ?? null;
            if ($id !== null && trim((string) ($segment[1] ?? '')) !== '' && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** اعتبارسنجی ساختار تکه‌ها (برای داده‌ای که از پایگاه‌داده خوانده می‌شود) */
    public static function sanitize(mixed $segments): array
    {
        if (! is_array($segments)) {
            return [];
        }
        $out = [];
        foreach ($segments as $segment) {
            if (is_array($segment) && isset($segment[1]) && is_string($segment[1]) && $segment[1] !== '') {
                $author = $segment[0] ?? null;
                $out[] = [is_int($author) ? $author : (is_numeric($author) ? (int) $author : null), $segment[1]];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // جزئیات
    // ------------------------------------------------------------------

    /** تکه‌تکه کردن متن به کلمه‌ها و فاصله‌ها (هر دو نوع نگه داشته می‌شوند تا متن دقیقاً بازسازی شود) */
    public static function tokenize(string $text): array
    {
        if ($text === '') {
            return [];
        }
        preg_match_all('/\s+|\S+/u', $text, $m);

        return $m[0];
    }

    /**
     * تبدیل تکه‌ها به توکن + نویسنده هر توکن.
     * متن کامل دوباره توکن‌بندی می‌شود و نویسنده هر توکن از تکه‌ای که اولین حرفش در آن است می‌آید.
     *
     * @return array{0: string[], 1: array<int, ?int>}
     */
    private static function flatten(array $segments): array
    {
        $segments = self::sanitize($segments);
        $tokens = self::tokenize(self::plain($segments));

        $authors = [];
        $segIndex = 0;
        $segEnd = isset($segments[0]) ? strlen($segments[0][1]) : 0;
        $offset = 0;
        foreach ($tokens as $token) {
            while ($offset >= $segEnd && $segIndex < count($segments) - 1) {
                $segIndex++;
                $segEnd += strlen($segments[$segIndex][1]);
            }
            $authors[] = $segments[$segIndex][0] ?? null;
            $offset += strlen($token);
        }

        return [$tokens, $authors];
    }

    /** ادغام توکن‌های پشت‌سرهم یک نویسنده؛ فاصله‌ها به تکه قبلی می‌چسبند */
    private static function merge(array $tokens, array $authors): array
    {
        $segments = [];
        foreach ($tokens as $i => $token) {
            $last = count($segments) - 1;
            $isSpace = ! self::isWord($token);
            if ($last >= 0 && ($isSpace || $segments[$last][0] === $authors[$i])) {
                $segments[$last][1] .= $token;
            } else {
                $segments[] = [$authors[$i], $token];
            }
        }
        // فاصله‌ای که اول متن آمده، نویسنده تکه بعدی را بگیرد
        if (count($segments) > 1 && ! self::isWord($segments[0][1])) {
            $segments[1][1] = $segments[0][1].$segments[1][1];
            array_shift($segments);
        }

        return $segments;
    }

    private static function isWord(string $token): bool
    {
        return preg_match('/\S/u', $token) === 1;
    }

    /**
     * گروه‌بندی توکن‌ها در خط‌ها؛ هر خط با توکن فاصله‌ای که «\n» دارد تمام می‌شود.
     *
     * @return array<int, array{key: string, tokens: int[]}>
     */
    private static function lines(array $tokens): array
    {
        $lines = [];
        $current = ['key' => '', 'tokens' => []];
        foreach ($tokens as $i => $token) {
            $current['key'] .= $token;
            $current['tokens'][] = $i;
            if (str_contains($token, "\n")) {
                $lines[] = $current;
                $current = ['key' => '', 'tokens' => []];
            }
        }
        if ($current['tokens']) {
            $lines[] = $current;
        }

        return $lines;
    }

    /** آزمون سبک: آیا مقایسه کل بلوک در سقف زمانی ممکن است؟ */
    private static function diffTokens(array $oldLines, array $newLines, array $del, array $ins, array $oldTokens, array $newTokens): ?array
    {
        return self::diff(
            array_map(fn ($i) => $oldTokens[$i], self::tokenRange($oldLines, $del)),
            array_map(fn ($i) => $newTokens[$i], self::tokenRange($newLines, $ins)),
        );
    }

    private static function tokenRange(array $lines, array $lineIndexes): array
    {
        $out = [];
        foreach ($lineIndexes as $index) {
            array_push($out, ...$lines[$index]['tokens']);
        }

        return $out;
    }

    /**
     * الگوریتم تفاوت Myers در حافظه خطی (روش «مار میانی» / divide and conquer).
     * خروجی: لیست [op, i, j] که op یکی از '=' (بدون تغییر)، '-' (حذف a[i])، '+' (درج b[j]) است.
     *
     * اگر فاصله ویرایش از سقف بیشتر شود null برمی‌گرداند (یا با replaceOnOverflow،
     * بخش میانی تغییرکرده یکجا «حذف + درج» حساب می‌شود). سقف به‌صورت خودکار برای
     * متن‌های بلند کمتر می‌شود تا زمان پردازش محدود بماند.
     *
     * @return array<int, array{0: string, 1: int, 2: int}>|null
     */
    public static function diff(array $a, array $b, int $maxD = self::MAX_EDIT_DISTANCE, bool $replaceOnOverflow = false): ?array
    {
        $a = array_values($a);
        $b = array_values($b);
        $n = count($a);
        $m = count($b);
        // سقف کار: حدود (N+M)×D مقایسه
        $maxD = max(0, min($maxD, intdiv(self::WORK_BUDGET, max(1, $n + $m))));

        $ops = [];
        if (self::diffRange($a, 0, $n, $b, 0, $m, $ops, $maxD)) {
            return $ops;
        }
        if (! $replaceOnOverflow) {
            return null;
        }

        // فقط ابتدا و انتهای مشترک حفظ و بقیه جایگزین شود
        $prefix = 0;
        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }
        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['=', $i, $i];
        }
        for ($i = $prefix; $i < $n - $suffix; $i++) {
            $ops[] = ['-', $i, $prefix];
        }
        for ($j = $prefix; $j < $m - $suffix; $j++) {
            $ops[] = ['+', $n - $suffix, $j];
        }
        for ($k = $suffix; $k > 0; $k--) {
            $ops[] = ['=', $n - $k, $m - $k];
        }

        return $ops;
    }

    /**
     * تفاوت بازه a[aLo..aHi) با b[bLo..bHi)؛ عملیات به $ops اضافه می‌شود.
     * $limit فقط برای سطح اول بررسی می‌شود (زیرمسئله‌ها فاصله کمتری دارند).
     */
    private static function diffRange(array $a, int $aLo, int $aHi, array $b, int $bLo, int $bHi, array &$ops, ?int $limit = null): bool
    {
        // بخش مشترک ابتدا
        while ($aLo < $aHi && $bLo < $bHi && $a[$aLo] === $b[$bLo]) {
            $ops[] = ['=', $aLo++, $bLo++];
        }
        // بخش مشترک انتها (بعداً اضافه می‌شود)
        $tail = [];
        while ($aLo < $aHi && $bLo < $bHi && $a[$aHi - 1] === $b[$bHi - 1]) {
            $tail[] = ['=', --$aHi, --$bHi];
        }

        if ($aLo === $aHi) {
            for ($j = $bLo; $j < $bHi; $j++) {
                $ops[] = ['+', $aLo, $j];
            }
        } elseif ($bLo === $bHi) {
            for ($i = $aLo; $i < $aHi; $i++) {
                $ops[] = ['-', $i, $bLo];
            }
        } else {
            $snake = self::middleSnake($a, $aLo, $aHi, $b, $bLo, $bHi, $limit);
            if ($snake === null) {
                return false;
            }
            [$x, $y, $u, $v] = $snake;
            self::diffRange($a, $aLo, $x, $b, $bLo, $y, $ops);
            for ($i = $x, $j = $y; $i < $u; $i++, $j++) {
                $ops[] = ['=', $i, $j];
            }
            self::diffRange($a, $u, $aHi, $b, $v, $bHi, $ops);
        }

        foreach (array_reverse($tail) as $op) {
            $ops[] = $op;
        }

        return true;
    }

    /**
     * پیدا کردن «مار میانی» مسیر بهینه (جستجوی هم‌زمان از ابتدا و انتها).
     * خروجی: [x, y, u, v] ابتدا و انتهای مار به مختصات مطلق، یا null اگر فاصله از سقف بیشتر باشد.
     */
    private static function middleSnake(array $a, int $aLo, int $aHi, array $b, int $bLo, int $bHi, ?int $limit): ?array
    {
        $n = $aHi - $aLo;
        $m = $bHi - $bLo;
        $delta = $n - $m;
        $odd = ($delta & 1) === 1;
        $maxD = intdiv($n + $m + 1, 2);
        if ($limit !== null) {
            $maxD = min($maxD, intdiv($limit + 1, 2));
        }

        $vf = [1 => 0];
        $vb = [1 => 0];
        for ($d = 0; $d <= $maxD; $d++) {
            // جستجوی رو به جلو
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && $vf[$k - 1] < $vf[$k + 1])) ? $vf[$k + 1] : $vf[$k - 1] + 1;
                $y = $x - $k;
                $x0 = $x;
                $y0 = $y;
                while ($x < $n && $y < $m && $a[$aLo + $x] === $b[$bLo + $y]) {
                    $x++;
                    $y++;
                }
                $vf[$k] = $x;
                $kb = $delta - $k;
                if ($odd && $kb >= -($d - 1) && $kb <= $d - 1 && isset($vb[$kb]) && $x + $vb[$kb] >= $n) {
                    return [$aLo + $x0, $bLo + $y0, $aLo + $x, $bLo + $y];
                }
            }
            // جستجوی رو به عقب (روی رشته‌های وارونه)
            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && $vb[$k - 1] < $vb[$k + 1])) ? $vb[$k + 1] : $vb[$k - 1] + 1;
                $y = $x - $k;
                $x0 = $x;
                $y0 = $y;
                while ($x < $n && $y < $m && $a[$aHi - 1 - $x] === $b[$bHi - 1 - $y]) {
                    $x++;
                    $y++;
                }
                $vb[$k] = $x;
                $kf = $delta - $k;
                if (! $odd && $kf >= -$d && $kf <= $d && isset($vf[$kf]) && $x + $vf[$kf] >= $n) {
                    return [$aHi - $x, $bHi - $y, $aHi - $x0, $bHi - $y0];
                }
            }
        }

        return null;
    }

    /** وقتی مقایسه دقیق خیلی سنگین است: همه قبلی‌ها حذف و همه جدیدها درج */
    private static function replaceAll(int $n, int $m): array
    {
        $ops = [];
        for ($i = 0; $i < $n; $i++) {
            $ops[] = ['-', $i, 0];
        }
        for ($j = 0; $j < $m; $j++) {
            $ops[] = ['+', $n, $j];
        }

        return $ops;
    }

    /** مثل replaceAll ولی ابتدا و انتهای مشترک حفظ می‌شود */
    private static function trimmedReplace(array $a, array $b): array
    {
        return self::diff($a, $b, 0, true) ?? self::replaceAll(count($a), count($b));
    }
}
