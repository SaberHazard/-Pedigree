<?php

namespace Tests\Unit;

use App\Support\AttributedText;
use PHPUnit\Framework\TestCase;

/**
 * الگوریتم «نویسنده هر کلمه»: درستی و بهینه بودن تفاوت‌یابی، حفظ نویسنده کلمه‌های
 * دست‌نخورده و کارایی روی متن‌های بلند.
 */
class AttributedTextTest extends TestCase
{
    /** نویسنده هر کلمه (بدون فاصله‌ها) */
    private static function wordAuthors(array $segments): array
    {
        $out = [];
        foreach ($segments as [$author, $text]) {
            foreach (preg_split('/\s+/u', trim($text)) as $word) {
                if ($word !== '') {
                    $out[] = [$word, $author];
                }
            }
        }

        return $out;
    }

    public function test_typo_fix_is_attributed_only_to_the_changed_word(): void
    {
        $v1 = AttributedText::apply([], 'او مردی بسیار مهربن و بخشنده بود', 1);
        $v2 = AttributedText::apply($v1['segments'], 'او مردی بسیار مهربان و بخشنده بود', 2);

        $this->assertSame(1, $v2['added']);
        $this->assertSame(1, $v2['removed']);
        $this->assertSame([
            ['او', 1], ['مردی', 1], ['بسیار', 1], ['مهربان', 2], ['و', 1], ['بخشنده', 1], ['بود', 1],
        ], self::wordAuthors($v2['segments']));
    }

    public function test_inserted_paragraph_and_deleted_words(): void
    {
        $v1 = AttributedText::apply([], "خط اول\nخط دوم\nخط سوم", 1);
        $v2 = AttributedText::apply($v1['segments'], "خط اول\nپاراگراف تازه\nخط دوم\nسوم", 2);

        $this->assertSame("خط اول\nپاراگراف تازه\nخط دوم\nسوم", AttributedText::plain($v2['segments']));
        $this->assertSame([
            ['خط', 1], ['اول', 1], ['پاراگراف', 2], ['تازه', 2], ['خط', 1], ['دوم', 1], ['سوم', 1],
        ], self::wordAuthors($v2['segments']));
        $this->assertSame(2, $v2['added']);
        $this->assertSame(1, $v2['removed']);
    }

    public function test_diff_is_valid_and_minimal_on_random_inputs(): void
    {
        mt_srand(2026);
        for ($t = 0; $t < 1500; $t++) {
            $alphabet = mt_rand(2, 6);
            $a = array_map(fn () => (string) mt_rand(1, $alphabet), range(1, mt_rand(1, 30)));
            $b = array_map(fn () => (string) mt_rand(1, $alphabet), range(1, mt_rand(1, 30)));
            if (mt_rand(0, 4) === 0) {
                $a = [];
            }

            $ops = AttributedText::diff($a, $b);
            $i = $j = $equal = 0;
            foreach ($ops as [$op, $x, $y]) {
                if ($op === '=') {
                    $this->assertSame([$i, $j], [$x, $y]);
                    $this->assertSame($a[$x], $b[$y]);
                    $i++;
                    $j++;
                    $equal++;
                } elseif ($op === '-') {
                    $this->assertSame($i++, $x);
                } else {
                    $this->assertSame($j++, $y);
                }
            }
            $this->assertSame([count($a), count($b)], [$i, $j]);
            $this->assertSame(self::lcs($a, $b), $equal, 'diff must keep the longest common subsequence');
        }
    }

    public function test_text_round_trips_through_many_random_edits(): void
    {
        mt_srand(7);
        $words = ['الف', 'ب', 'پ', 'سلام', 'دنیا', "\n", ' ', '  ', 'نیم‌فاصله'];
        $segments = [];
        for ($t = 0; $t < 300; $t++) {
            $text = '';
            for ($i = 0, $n = mt_rand(0, 40); $i < $n; $i++) {
                $text .= $words[mt_rand(0, count($words) - 1)].(mt_rand(0, 3) ? ' ' : '');
            }
            $segments = AttributedText::apply($segments, $text, $t)['segments'];
            $this->assertSame($text, AttributedText::plain($segments));
        }
    }

    public function test_long_text_with_many_changes_stays_fast_and_precise(): void
    {
        $text = str_repeat("این یک جمله نمونه برای آزمون سرعت است و کلمه‌های زیادی دارد.\n", 800);
        $v1 = AttributedText::apply([], $text, 1);

        $start = microtime(true);
        $v2 = AttributedText::apply($v1['segments'], str_replace('سرعت است', 'سرعت بود', $text), 2);
        $this->assertLessThan(5.0, microtime(true) - $start);
        // فقط کلمه عوض‌شده در هر خط به نام ویرایشگر دوم است
        $this->assertSame(800, $v2['added']);
        $this->assertSame(800, $v2['removed']);
        $authors = array_count_values(array_column(self::wordAuthors($v2['segments']), 1));
        $this->assertSame(800, $authors[2]);
    }

    public function test_authors_and_sanitize(): void
    {
        $segments = [[3, 'سلام '], [null, 'دنیا'], ['x', ''], 'bad', [5, ' ']];
        $this->assertSame([[3, 'سلام '], [null, 'دنیا'], [5, ' ']], AttributedText::sanitize($segments));
        $this->assertSame([3], AttributedText::authors(AttributedText::sanitize($segments)));
    }

    private static function lcs(array $a, array $b): int
    {
        $prev = array_fill(0, count($b) + 1, 0);
        foreach ($a as $x) {
            $row = [0];
            foreach ($b as $j => $y) {
                $row[] = $x === $y ? $prev[$j] + 1 : max($prev[$j + 1], $row[$j]);
            }
            $prev = $row;
        }

        return end($prev);
    }
}
