<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Person;
use App\Services\KinshipDegrees;
use App\Services\Tree\RelationshipCalculator;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * بازی‌های خانوادگی از روی خود شجره‌نامه (بدون هوش مصنوعی):
 *  - «این کیه؟»: از روی عکس پروفایل، شخص را بشناس
 *  - «نسبت فامیلی»: فلانی چه نسبتی با تو دارد؟
 *  - «کی بزرگ‌تره؟»: از دو نفر کدام زودتر به دنیا آمده؟
 */
class GameController extends Controller
{
    private const KIN_POOL = [
        'پدربزرگ', 'مادربزرگ', 'عمو', 'عمه', 'دایی', 'خاله', 'پسرعمو', 'دخترعمو', 'پسرخاله', 'دخترخاله',
        'پسردایی', 'دختردایی', 'پسرعمه', 'دخترعمه', 'برادرزاده', 'خواهرزاده', 'نوه', 'نتیجه', 'باجناق', 'جاری',
        'پدرزن', 'مادرزن', 'پدرشوهر', 'مادرشوهر', 'عروس', 'داماد', 'برادرزن', 'خواهرشوهر',
    ];

    public function question(Request $request, KinshipDegrees $degrees, RelationshipCalculator $relations): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(['who', 'kin', 'older'])]])['type'];

        return response()->json(['data' => match ($type) {
            'who' => $this->who(),
            'kin' => $this->kin($request, $degrees, $relations),
            'older' => $this->older(),
        }]);
    }

    /** «این کیه؟» */
    private function who(): array
    {
        $person = Person::query()->with('avatar')->whereNotNull('avatar_media_id')
            ->whereHas('avatar', fn ($q) => $q->where('status', Media::STATUS_APPROVED)->where('type', Media::TYPE_IMAGE))
            ->inRandomOrder()->first();
        if (! $person) {
            throw new DomainException('هنوز عکس پروفایل کافی در شجره‌نامه نیست؛ بعد از اضافه شدن عکس‌ها دوباره سر بزنید.', 422, 'game_empty');
        }
        $others = $this->distractors($person, 3);
        if ($others->count() < 2) {
            throw new DomainException('برای این بازی دست‌کم سه نفر در شجره‌نامه لازم است.', 422, 'game_empty');
        }
        $options = $others->push($person)->shuffle()->map(fn (Person $p) => ['id' => $p->id, 'label' => $p->fullName()])->values();

        return [
            'type' => 'who',
            'question' => 'این کیه؟',
            'image' => $person->avatar->url('medium') ?? $person->avatar->url('original'),
            'options' => $options,
            'answer' => $person->id,
            'explain' => $person->fullName(),
            'person_id' => $person->id,
        ];
    }

    /** «نسبت فامیلی» */
    private function kin(Request $request, KinshipDegrees $degrees, RelationshipCalculator $relations): array
    {
        $me = $request->user()->person_id ? Person::query()->find($request->user()->person_id) : null;
        if (! $me) {
            throw new DomainException('حساب شما به پروفایلی در شجره‌نامه وصل نیست.', 422, 'game_empty');
        }
        $kin = $degrees->from($me, 3);
        $paths = [];
        foreach ($kin as $row) {
            array_push($paths, ...$row['path']);
        }
        $relations->preload($paths);
        $labels = [];
        foreach ($kin as $id => $row) {
            $label = $relations->labelForPath($row['path']);
            if ($label !== '' && ! str_contains($label, '(') && mb_strlen($label) < 25) {
                $labels[$id] = $label;
            }
        }
        if (! $labels) {
            throw new DomainException('هنوز بستگان کافی در درخت شما ثبت نشده است.', 422, 'game_empty');
        }
        $targetId = array_rand($labels);
        $target = Person::query()->with('avatar')->findOrFail($targetId);
        $answer = $labels[$targetId];
        $pool = array_values(array_unique(array_merge(array_values($labels), self::KIN_POOL)));
        $pool = array_values(array_filter($pool, fn ($l) => $l !== $answer));
        shuffle($pool);
        $options = collect(array_slice($pool, 0, 3))->push($answer)->shuffle()->map(fn ($l) => ['id' => $l, 'label' => $l])->values();

        return [
            'type' => 'kin',
            'question' => '«'.$target->fullName().'» چه نسبتی با شما دارد؟',
            'image' => $target->avatar?->isApproved() ? $target->avatar->url('thumb') : null,
            'options' => $options,
            'answer' => $answer,
            'explain' => $target->fullName().' '.$answer.' شماست.',
            'person_id' => $target->id,
        ];
    }

    /** «کی بزرگ‌تره؟» */
    private function older(): array
    {
        $people = Person::query()->with('avatar')->whereNotNull('birth_date')->where('birth_date', 'not like', '0000%')
            ->inRandomOrder()->limit(40)->get()
            ->filter(fn (Person $p) => (int) substr((string) $p->birth_date, 0, 4) > 0);
        $pair = null;
        foreach ($people as $a) {
            $b = $people->first(fn (Person $p) => $p->id !== $a->id && substr((string) $p->birth_date, 0, 4) !== substr((string) $a->birth_date, 0, 4));
            if ($b) {
                $pair = [$a, $b];
                break;
            }
        }
        if (! $pair) {
            throw new DomainException('برای این بازی تاریخ تولد دست‌کم دو نفر لازم است.', 422, 'game_empty');
        }
        [$a, $b] = $pair;
        $older = (string) $a->birth_date < (string) $b->birth_date ? $a : $b;
        $year = fn (Person $p) => PersianText::toPersianDigits(substr((string) $p->birth_date, 0, 4));

        return [
            'type' => 'older',
            'question' => 'کدام‌یک بزرگ‌تر است؟',
            'image' => null,
            'options' => collect([$a, $b])->map(fn (Person $p) => [
                'id' => $p->id,
                'label' => $p->fullName(),
                'image' => $p->avatar?->isApproved() ? $p->avatar->url('thumb') : null,
            ])->values(),
            'answer' => $older->id,
            'explain' => $a->fullName().' متولد '.$year($a).' و '.$b->fullName().' متولد '.$year($b),
            'person_id' => $older->id,
        ];
    }

    /** @return Collection<int, Person> گزینه‌های غلط (ترجیحاً هم‌جنس) */
    private function distractors(Person $person, int $count): Collection
    {
        $same = Person::query()->where('id', '!=', $person->id)->where('gender', $person->gender)
            ->where('first_name', '!=', '')->inRandomOrder()->limit($count)->get();
        if ($same->count() >= $count) {
            return $same;
        }

        return $same->merge(Person::query()->where('id', '!=', $person->id)->whereNotIn('id', $same->pluck('id'))
            ->inRandomOrder()->limit($count - $same->count())->get());
    }
}
