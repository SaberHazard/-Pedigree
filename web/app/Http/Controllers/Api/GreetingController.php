<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Services\KinshipDegrees;
use App\Services\Occasions\BirthdayService;
use App\Services\Sms\GreetingService;
use App\Services\Tree\NodePresenter;
use App\Services\Tree\RelationshipCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * تبریک تولد: تولدهای امروز و هفته آینده، ارسال پیامک تبریک از پنل سایت،
 * تنظیم تبریک خودکار و پیامک‌های ارسالی خود کاربر.
 */
class GreetingController extends Controller
{
    public function __construct(
        private readonly GreetingService $greetings,
        private readonly BirthdayService $birthdays,
    ) {}

    public function index(Request $request, KinshipDegrees $degrees, RelationshipCalculator $relations): JsonResponse
    {
        $user = $request->user();
        $eligibility = $this->greetings->eligibility($user);
        $me = $user->person;

        $rows = $this->birthdays->around(1, 7)->map(function (array $row) use ($user, $me, $degrees, $relations, $eligibility) {
            /** @var Person $p */
            $p = $row['person'];
            $problem = $row['in_days'] <= 0 && $row['in_days'] >= -1 ? $this->greetings->recipientProblem($user, $p, $row['in_days']) : 'هنوز روز تولد نرسیده است.';
            $degree = $me ? $degrees->degree($me, $p) : null;

            return [
                'person' => NodePresenter::person($p),
                'in_days' => $row['in_days'],
                'age' => $row['age'],
                'degree' => $degree,
                'relation' => $me && $degree !== null ? $relations->calculate($me, $p)['label'] ?? null : null,
                'is_me' => $me?->id === $p->id,
                'can_sms' => $eligibility['eligible'] && $problem === null,
                'problem' => $problem,
                'greeted' => $this->greetings->alreadyGreeted($user, $p),
            ];
        })->values();

        return response()->json([
            'eligibility' => $eligibility,
            'birthdays' => $rows,
            'auto' => $this->greetings->autoPreferences($user) + [
                'allowed' => (bool) config('pedigree.member_sms.auto_enabled', true),
                'max_scope' => (string) config('pedigree.member_sms.auto_max_scope', 'd2'),
                'send_hour' => (int) config('pedigree.member_sms.send_hour', 9),
            ],
            'default_template' => GreetingService::DEFAULT_TEMPLATE,
            'accept_greeting_sms' => (bool) ($me?->accept_greeting_sms ?? true),
            'history' => SmsMessage::with('recipient')->where('sender_user_id', $user->id)->latest('id')->limit(30)->get()
                ->map(fn (SmsMessage $m) => [
                    'id' => $m->id,
                    'recipient' => $m->recipient ? NodePresenter::person($m->recipient) : null,
                    'status' => $m->status,
                    'auto' => $m->auto,
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** ارسال پیامک تبریک */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $person = Person::query()->findOrFail($data['person_id']);
        Gate::authorize('view', $person);

        $record = $this->greetings->send($request->user(), $person, $data['message']);

        return response()->json([
            'message' => 'پیامک تبریک برای '.$person->first_name.' ارسال شد.',
            'data' => ['id' => $record->id, 'body' => $record->body],
            'limits' => $this->greetings->limits($request->user()),
        ], 201);
    }

    /** پیش‌نمایش متن نهایی (با نام فرستنده) */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'uuid'],
            'message' => ['required', 'string', 'max:2000'],
        ]);
        $person = Person::query()->findOrFail($data['person_id']);
        Gate::authorize('view', $person);
        $text = $this->greetings->compose($request->user(), $person, $data['message']);

        return response()->json(['text' => $text, 'length' => mb_strlen($text), 'parts' => $this->parts($text)]);
    }

    /** تنظیم تبریک خودکار */
    public function updateAuto(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'auto' => ['required', 'boolean'],
            'scope' => ['required', Rule::in(['all', 'd4', 'd3', 'd2', 'd1'])],
            'template' => ['required', 'string', 'max:2000'],
        ]);
        if ($data['auto'] && ! config('pedigree.member_sms.auto_enabled', true)) {
            throw new DomainException('تبریک خودکار از طرف مدیر سایت غیرفعال است.');
        }
        if (! str_contains($data['template'], '{name}')) {
            throw new DomainException('در متن، {name} را بگذارید تا نام هر شخص جایش نوشته شود.');
        }
        $template = $this->greetings->cleanMessage($data['template']);
        if ($data['auto']) {
            $eligibility = $this->greetings->eligibility($user);
            if (! $eligibility['eligible']) {
                throw new DomainException($eligibility['message'], 403, 'sms_'.$eligibility['reason']);
            }
        }

        $prefs = $user->preferences ?? [];
        $prefs['birthday_sms'] = ['auto' => $data['auto'], 'scope' => $this->greetings->effectiveScope($data['scope']), 'template' => $template];
        $user->preferences = $prefs;
        $user->save();

        return response()->json([
            'message' => $data['auto'] ? 'تبریک خودکار روشن شد.' : 'تبریک خودکار خاموش شد.',
            'auto' => $this->greetings->autoPreferences($user),
        ]);
    }

    /** تعداد بخش‌های پیامک (فارسی: ۷۰ نویسه، چندبخشی ۶۷) */
    private function parts(string $text): int
    {
        $length = mb_strlen($text);

        return $length <= 70 ? 1 : (int) ceil($length / 67);
    }
}
