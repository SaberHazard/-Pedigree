<?php

namespace App\Services\Tree;

use App\Models\Marriage;
use App\Models\Person;
use App\Support\Jalali;
use App\Support\PartialDate;
use Illuminate\Support\Collection;

/**
 * خروجی GEDCOM 5.5.1 (فرمت استاندارد جهانی شجره‌نامه)
 *
 * با این فایل می‌توان درخت را در نرم‌افزارهایی مثل MyHeritage، Gramps،
 * Ancestry و FamilySearch باز کرد. تاریخ‌های شمسی به میلادی تبدیل می‌شوند
 * و تاریخ شمسی اصلی هم در یادداشت (NOTE) حفظ می‌شود.
 */
class GedcomExporter
{
    private const MONTHS = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];

    /** @var string[] */
    private array $lines = [];

    /**
     * @param  Collection<int, Person>  $persons
     * @param  Collection<int, Marriage>  $marriages
     */
    public function export(Collection $persons, Collection $marriages, string $title): string
    {
        $this->lines = [];
        $byId = $persons->keyBy('id');
        $indi = [];
        $i = 1;
        foreach ($persons as $p) {
            $indi[$p->id] = '@I'.$i++.'@';
        }

        // خانواده‌ها: از روی ازدواج‌ها + ترکیب پدر/مادرِ فرزندان
        $families = [];
        foreach ($marriages as $m) {
            if (isset($indi[$m->husband_id]) && isset($indi[$m->wife_id])) {
                $families[$m->husband_id.'|'.$m->wife_id] = ['husband' => $m->husband_id, 'wife' => $m->wife_id, 'marriage' => $m, 'children' => []];
            }
        }
        foreach ($persons as $p) {
            $father = $p->father_id && isset($indi[$p->father_id]) ? $p->father_id : null;
            $mother = $p->mother_id && isset($indi[$p->mother_id]) ? $p->mother_id : null;
            if (! $father && ! $mother) {
                continue;
            }
            $key = ($father ?? '').'|'.($mother ?? '');
            $families[$key] ??= ['husband' => $father, 'wife' => $mother, 'marriage' => null, 'children' => []];
            $families[$key]['children'][] = $p->id;
        }
        $famIds = [];
        $f = 1;
        foreach (array_keys($families) as $key) {
            $famIds[$key] = '@F'.$f++.'@';
        }

        $this->header($title);

        foreach ($persons as $p) {
            $this->line(0, $indi[$p->id].' INDI');
            $given = trim(($p->first_name ?? ''));
            $surname = trim((string) $p->last_name);
            $this->line(1, 'NAME '.$given.' /'.$surname.'/');
            $this->line(2, 'GIVN '.$given);
            if ($surname !== '') {
                $this->line(2, 'SURN '.$surname);
            }
            if ($p->title) {
                $this->line(2, 'NPFX '.$p->title);
            }
            if ($p->nickname) {
                $this->line(2, 'NICK '.$p->nickname);
            }
            $this->line(1, 'SEX '.($p->gender === Person::MALE ? 'M' : 'F'));
            $this->event('BIRT', $p->birth_date, $p->birth_place);
            if ($p->is_deceased) {
                if ($p->death_date || $p->death_place) {
                    $this->event('DEAT', $p->death_date, $p->death_place);
                } else {
                    $this->line(1, 'DEAT Y');
                }
                if ($p->burial_place) {
                    $this->line(1, 'BURI');
                    $this->line(2, 'PLAC '.$p->burial_place);
                }
            }
            if ($p->occupation) {
                $this->line(1, 'OCCU '.$p->occupation);
            }
            if ($p->education) {
                $this->line(1, 'EDUC '.$p->education);
            }
            if ($p->residence) {
                $this->line(1, 'RESI');
                $this->line(2, 'PLAC '.$p->residence);
            }
            if ($p->biography) {
                $this->note(1, $p->biography);
            }
            $this->line(1, 'REFN '.$p->code);
            foreach ($families as $key => $fam) {
                if ($fam['husband'] === $p->id || $fam['wife'] === $p->id) {
                    $this->line(1, 'FAMS '.$famIds[$key]);
                }
                if (in_array($p->id, $fam['children'], true)) {
                    $this->line(1, 'FAMC '.$famIds[$key]);
                }
            }
        }

        foreach ($families as $key => $fam) {
            $this->line(0, $famIds[$key].' FAM');
            if ($fam['husband']) {
                $this->line(1, 'HUSB '.$indi[$fam['husband']]);
            }
            if ($fam['wife']) {
                $this->line(1, 'WIFE '.$indi[$fam['wife']]);
            }
            foreach ($fam['children'] as $childId) {
                $this->line(1, 'CHIL '.$indi[$childId]);
            }
            if ($m = $fam['marriage']) {
                $this->event('MARR', $m->marriage_date, null, true);
                if ($m->status === 'divorced') {
                    $this->event('DIV', $m->end_date, null, true);
                }
            }
        }

        $this->line(0, 'TRLR');

        return implode("\r\n", $this->lines)."\r\n";
    }

    private function header(string $title): void
    {
        [$y, $m, $d] = [(int) date('Y'), (int) date('n'), (int) date('j')];
        $this->line(0, 'HEAD');
        $this->line(1, 'SOUR PEDIGREE');
        $this->line(2, 'NAME '.config('pedigree.site_name'));
        // نشانی سایت (برای معرفی سایت هنگام اشتراک خروجی)
        if (preg_match('#^https?://[^\s]+$#', (string) config('app.url'))) {
            $this->line(2, 'CORP '.config('pedigree.site_name'));
            $this->line(3, 'WWW '.rtrim((string) config('app.url'), '/'));
        }
        $this->line(1, 'DATE '.$d.' '.self::MONTHS[$m - 1].' '.$y);
        $this->line(1, 'GEDC');
        $this->line(2, 'VERS 5.5.1');
        $this->line(2, 'FORM LINEAGE-LINKED');
        $this->line(1, 'CHAR UTF-8');
        $this->line(1, 'LANG Persian');
        $this->note(1, $title);
    }

    private function event(string $tag, ?string $date, ?string $place, bool $force = false): void
    {
        if (! $date && ! $place && ! $force) {
            return;
        }
        $this->line(1, $tag);
        if ($date && ($parsed = PartialDate::parse($date))) {
            $this->line(2, 'DATE '.$this->gregorian($parsed));
            $this->line(2, 'NOTE تاریخ شمسی: '.str_replace('-', '/', $parsed->toString()));
        }
        if ($place) {
            $this->line(2, 'PLAC '.$place);
        }
    }

    private function gregorian(PartialDate $date): string
    {
        if ($date->month !== null && $date->day !== null) {
            [$gy, $gm, $gd] = Jalali::toGregorian($date->year, $date->month, $date->day);

            return $gd.' '.self::MONTHS[$gm - 1].' '.$gy;
        }

        return 'ABT '.$date->approximateGregorianYear();
    }

    /** یادداشت چندخطی با CONT/CONC (حداکثر طول خط در GEDCOM محدود است) */
    private function note(int $level, string $text): void
    {
        $rows = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($rows as $index => $row) {
            $chunks = mb_str_split($row, 200) ?: [''];
            foreach ($chunks as $c => $chunk) {
                if ($index === 0 && $c === 0) {
                    $this->line($level, 'NOTE '.$chunk);
                } elseif ($c === 0) {
                    $this->line($level + 1, 'CONT '.$chunk);
                } else {
                    $this->line($level + 1, 'CONC '.$chunk);
                }
            }
        }
    }

    private function line(int $level, string $content): void
    {
        $this->lines[] = $level.' '.rtrim(str_replace(["\r", "\n"], ' ', $content));
    }
}
