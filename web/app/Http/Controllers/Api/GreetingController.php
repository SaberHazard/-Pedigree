<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\KinshipDegrees;
use App\Services\Occasions\BirthdayService;
use App\Services\Occasions\OccasionCalendar;
use App\Services\Sms\GreetingService;
use App\Services\Sms\SmsTemplates;
use App\Services\Tree\NodePresenter;
use App\Services\Tree\RelationshipCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * تبریک مناسبت‌ها (تولد، سالگرد ازدواج، نوروز، یلدا): فهرست کسانی که امروز می‌شود به آن‌ها تبریک گفت،
 * ارسال پیامک تبریک با قالب‌های ثابت مدیر کل، تبریک خودکار تولد و پیامک‌های ارسالی خود کاربر.
 */
class GreetingController extends Controller
{
    /** بیشترین تعداد بستگان در فهرست تبریک نوروز/یلدا */
    private const SEASONAL_MAX = 300;

    public function __construct(
        private readonly GreetingService $greetings,
        private readonly BirthdayService $birthdays,
        private readonly OccasionCalendar $calendar,
    ) {}

    public function index(Request $request, KinshipDegrees $degrees, RelationshipCalculator $relations): JsonResponse
    {
        $user = $request->user();
        $eligibility = $this->greetings->eligibility($user);
        $me = $user->person;
        $row = function (Person $p, string $occasion, int $inDays, array $extra = []) use ($user, $me, $degrees, $relations, $eligibility) {
            $problem = $inDays <= 0 && $inDays >= -1 ? $this->greetings->recipientProblem($user, $p, $occasion, $occasion === 'birthday' ? $inDays : null) : 'هنوز روز آن نرسیده است.';
            $degree = $me ? $degrees->degree($me, $p) : null;

            return [
                'occasion' => $occasion,
                'person' => NodePresenter::person($p),
                'in_days' => $inDays,
                'degree' => $degree,
                'relation' => $me && $degree !== null ? $relations->calculate($me, $p)['label'] ?? null : null,
                'is_me' => $me?->id === $p->id,
                'can_sms' => $eligibility['eligible'] && $problem === null,
                'problem' => $problem,
                'greeted' => $this->greetings->alreadyGreeted($user, $p, $occasion),
            ] + $extra;
        };

        $birthdays = $this->birthdays->around(1, 7)->map(fn (array $r) => $row($r['person'], 'birthday', $r['in_days'], ['age' => $r['age']]))->values();

        $anniversaries = collect();
        foreach ($this->calendar->anniversaries(1, 7) as $r) {
            $m = $r['marriage'];
            foreach ([[$m->husband, $m->wife], [$m->wife, $m->husband]] as [$person, $spouse]) {
                if ($person->is_deceased) {
                    continue;
                }
                $anniversaries->push($row($person, 'anniversary', $r['in_days'], ['years' => $r['years'], 'spouse' => NodePresenter::person($spouse)]));
            }
        }

        $occasions = [];
        foreach (SmsTemplates::OCCASIONS as $key => $o) {
            $occasions[] = [
                'key' => $key,
                'label' => $o['label'],
                'emoji' => $o['emoji'],
                'open' => $this->calendar->isOpen($key),
                'window' => OccasionCalendar::WINDOWS[$key],
                'templates' => GreetingService::templates($key),
            ];
        }

        return response()->json([
            'eligibility' => $eligibility,
            'occasions' => $occasions,
            'birthdays' => $birthdays,
            'anniversaries' => $anniversaries->values(),
            'relatives' => $this->seasonalRelatives($user, $eligibility['eligible'], $degrees, $relations),
            'auto' => $this->greetings->autoPreferences($user) + [
                'max_scope' => (string) config('pedigree.member_sms.auto_max_scope', 'd2'),
                'send_hour' => (int) config('pedigree.member_sms.send_hour', 9),
            ],
            'note_max' => GreetingService::NOTE_MAX,
            'history' => SmsMessage::with('recipient')->where('sender_user_id', $user->id)->latest('id')->limit(30)->get()
                ->map(fn (SmsMessage $m) => [
                    'id' => $m->id,
                    'kind' => $m->kind,
                    'recipient' => $m->recipient ? NodePresenter::person($m->recipient) : null,
                    'status' => $m->status,
                    'auto' => $m->auto,
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /**
     * بستگان تا درجه ۴ (زنده و دارای موبایل) برای تبریک نوروز و یلدا؛ فقط وقتی بازه یکی از آن‌ها باز است
     *
     * @return array<int, array>
     */
    private function seasonalRelatives(User $user, bool $eligible, KinshipDegrees $degrees, RelationshipCalculator $relations): array
    {
        $open = array_values(array_filter(['nowruz', 'yalda'], fn ($o) => $this->calendar->isOpen($o) && SmsTemplates::active($o)->isNotEmpty()));
        $me = $user->person;
        if (! $open || ! $me) {
            return [];
        }
        $kin = $degrees->from($me, 4);
        uasort($kin, fn ($a, $b) => $a['degree'] <=> $b['degree']);
        $people = Person::query()->with('avatar')->whereIn('id', array_slice(array_keys($kin), 0, 2000))
            ->where('is_deceased', false)->whereNotNull('phone_hash')->get()->keyBy('id');
        $greeted = SmsMessage::query()->where('sender_user_id', $user->id)->whereIn('kind', $open)
            ->where('status', SmsMessage::STATUS_SENT)->whereDate('sent_on', '>=', now('Asia/Tehran')->subDays(300)->toDateString())
            ->get(['recipient_person_id', 'kind'])->groupBy('kind')->map(fn ($rows) => $rows->pluck('recipient_person_id')->flip());

        $paths = [];
        $rows = [];
        foreach ($kin as $id => $k) {
            if (! $people->has($id)) {
                continue;
            }
            $rows[$id] = $k;
            array_push($paths, ...$k['path']);
            if (count($rows) >= self::SEASONAL_MAX) {
                break;
            }
        }
        $relations->preload($paths);

        $out = [];
        foreach ($rows as $id => $k) {
            $done = [];
            foreach ($open as $o) {
                $done[$o] = isset($greeted[$o][$id]);
            }
            $out[] = [
                'person' => NodePresenter::person($people[$id]),
                'degree' => $k['degree'],
                'relation' => $relations->labelForPath($k['path']),
                'greeted' => $done,
                'can_sms' => $eligible,
            ];
        }

        return $out;
    }

    /** ارسال پیامک تبریک (قالب ثابت + یادداشت کوتاه اختیاری) */
    public function send(Request $request): JsonResponse
    {
        $data = $this->validateGreeting($request);
        $person = Person::query()->findOrFail($data['person_id']);
        Gate::authorize('view', $person);

        $record = $this->greetings->send($request->user(), $person, $data['occasion'], $data['template'] ?? null, $data['note'] ?? null);

        return response()->json([
            'message' => 'پیامک تبریک برای '.$person->first_name.' ارسال شد.',
            'data' => ['id' => $record->id, 'body' => $record->body, 'kind' => $record->kind],
            'limits' => $this->greetings->limits($request->user()),
        ], 201);
    }

    /** پیش‌نمایش متن نهایی (با نام‌ها، عنوان‌ها و نسبت فامیلی) */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateGreeting($request);
        $person = Person::query()->findOrFail($data['person_id']);
        Gate::authorize('view', $person);
        $text = $this->greetings->compose($request->user(), $person, $data['occasion'], $data['template'] ?? null, $data['note'] ?? null);

        return response()->json([
            'text' => $text,
            'length' => mb_strlen($text),
            'parts' => SmsTemplates::parts($text),
            'problem' => $this->greetings->recipientProblem($request->user(), $person, $data['occasion']),
        ]);
    }

    /** تنظیم تبریک خودکار تولد (قالب ثابت + یادداشت کوتاه) */
    public function updateAuto(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'auto' => ['required', 'boolean'],
            'scope' => ['required', Rule::in(['all', 'd4', 'd3', 'd2', 'd1'])],
            'template' => ['nullable', 'integer', Rule::in(SmsTemplates::active('birthday')->pluck('id')->all())],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $note = $this->greetings->cleanNote($data['note'] ?? null);
        if ($data['auto']) {
            $eligibility = $this->greetings->eligibility($user);
            if (! $eligibility['eligible']) {
                throw new DomainException($eligibility['message'], 403, 'sms_'.$eligibility['reason']);
            }
        }

        $prefs = $user->preferences ?? [];
        $prefs['birthday_sms'] = [
            'auto' => $data['auto'],
            'scope' => $this->greetings->effectiveScope($data['scope']),
            'template' => $data['template'] ?? null,
            'note' => $note,
        ];
        $user->preferences = $prefs;
        $user->save();

        return response()->json([
            'message' => $data['auto'] ? 'تبریک خودکار روشن شد.' : 'تبریک خودکار خاموش شد.',
            'auto' => $this->greetings->autoPreferences($user),
        ]);
    }

    private function validateGreeting(Request $request): array
    {
        return $request->validate([
            'person_id' => ['required', 'uuid'],
            'occasion' => ['sometimes', 'string', Rule::in(array_keys(SmsTemplates::OCCASIONS))],
            'template' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:200'],
        ]) + ['occasion' => 'birthday'];
    }
}
