<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Models\CallInvite;
use App\Models\CallSignal;
use App\Models\User;
use App\Models\UserBlock;
use App\Notifications\CallInvited;
use App\Services\Calls\CallLinks;
use App\Services\Calls\CallService;
use App\Services\Tree\NodePresenter;
use App\Support\ErrorReporter;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * تماس‌ها: تماس مستقیم صوتی/تصویری داخل سایت (WebRTC) و دعوت به تماس گروهی با لینک سرویس‌های بیرونی.
 */
class CallController extends Controller
{
    public function __construct(private readonly CallService $calls) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $invites = CallInvite::query()
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhereHas('invitees', fn ($i) => $i->where('users.id', $user->id)))
            ->where('created_at', '>=', now()->subDays(14))
            ->with('user.person.avatar')->latest('id')->limit(30)->get();

        return response()->json([
            'data' => $this->calls->history($user),
            'invites' => $invites->map(fn (CallInvite $i) => [
                'id' => $i->id,
                'mine' => $i->user_id === $user->id,
                'from' => ['user_id' => $i->user_id, 'name' => $i->user?->displayName() ?? 'عضو'],
                'provider' => $i->provider,
                'label' => CallLinks::PROVIDERS[$i->provider]['label'] ?? '',
                'url' => $i->url,
                'title' => $i->title,
                'starts_at' => $i->starts_at?->toIso8601String(),
                'recipients' => $i->recipients,
                'at' => $i->created_at->toIso8601String(),
            ]),
            'config' => [
                'enabled' => CallService::enabled(),
                'max_participants' => CallService::maxParticipants(),
                'links' => (bool) config('pedigree.calls.links', true),
                'providers' => array_map(fn ($p) => $p['label'], CallLinks::PROVIDERS),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'person_ids' => ['required', 'array', 'min:1', 'max:5'],
            'person_ids.*' => ['string', 'max:36'],
            'kind' => ['required', Rule::in(Call::KINDS)],
        ]);
        $userIds = User::query()->whereIn('person_id', array_unique($data['person_ids']))->pluck('id')->all();
        if (count($userIds) !== count(array_unique($data['person_ids']))) {
            throw new DomainException('این شخص هنوز عضو سایت نیست؛ وقتی وارد سایت شود می‌توانید با او تماس بگیرید.', 422, 'not_member');
        }
        $call = $this->calls->start($request->user(), $userIds, $data['kind']);

        return response()->json(['data' => $this->calls->state($call->refresh(), $request->user(), true)], 201);
    }

    /** جستجوی اعضای فعال برای تماس یا دعوت (فقط کسانی که حساب فعال دارند و مسدود نیستند) */
    public function people(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:60']]);
        $user = $request->user();
        $blocked = UserBlock::query()->where('blocker_id', $user->id)->pluck('blocked_id')
            ->merge(UserBlock::query()->where('blocked_id', $user->id)->pluck('blocker_id'))->all();
        $query = User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')->whereNotNull('person_id')
            ->where('id', '!=', $user->id)->whereNotIn('id', $blocked ?: [0])
            ->with('person.avatar');
        $q = trim((string) ($data['q'] ?? ''));
        if ($q !== '') {
            $query->whereHas('person', function ($p) use ($q) {
                foreach (array_slice(explode(' ', PersianText::searchable($q)), 0, 4) as $word) {
                    if ($word !== '') {
                        $p->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
                    }
                }
            });
        }

        return response()->json(['data' => $query->orderByDesc('last_login_at')->limit(30)->get()
            ->filter(fn (User $u) => $u->person !== null)
            ->map(fn (User $u) => NodePresenter::person($u->person))->values()]);
    }

    /** تماسی که همین حالا برای من زنگ می‌خورد (هر چند ثانیه وقتی صفحه باز است) */
    public function ring(Request $request): JsonResponse
    {
        $data = CallService::enabled() ? $this->calls->ringing($request->user()) : null;

        return response()->json(['data' => $data], 200, ['Cache-Control' => 'no-store']);
    }

    public function answer(Request $request, Call $call): JsonResponse
    {
        return response()->json(['data' => $this->calls->answer($request->user(), $call)]);
    }

    public function decline(Request $request, Call $call): JsonResponse
    {
        $this->calls->decline($request->user(), $call);

        return response()->json(['ok' => true]);
    }

    public function leave(Request $request, Call $call): JsonResponse
    {
        $this->calls->leave($request->user(), $call);

        return response()->json(['ok' => true]);
    }

    public function poll(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);

        return response()->json($this->calls->poll($request->user(), $call, (int) ($data['after'] ?? 0)), 200, ['Cache-Control' => 'no-store']);
    }

    public function signal(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(CallSignal::TYPES)],
            'data' => ['present', 'array'],
        ]);
        $this->calls->signal($request->user(), $call, (int) $data['to'], $data['type'], (string) json_encode($data['data'], JSON_UNESCAPED_SLASHES));

        return response()->json(['ok' => true]);
    }

    /** دعوت بستگان به تماس گروهی با لینک سرویس بیرونی */
    public function invite(Request $request): JsonResponse
    {
        if (! config('pedigree.calls.links', true)) {
            throw new DomainException('دعوت به تماس با لینک خاموش است.', 403);
        }
        $data = $request->validate([
            'url' => ['required', 'string', 'max:300'],
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['nullable', 'date', 'after:-1 hour', 'before:+60 days'],
            'person_ids' => ['required', 'array', 'min:1', 'max:60'],
            'person_ids.*' => ['string', 'max:36'],
        ]);
        $link = CallLinks::parse($data['url']);
        if ($link === null) {
            throw new DomainException('این لینک پذیرفته نیست. لینک تماس واتس‌اپ (call.whatsapp.com)، گوگل‌میت، جیتسی، اسکای‌روم، زوم یا ویدیوچت تلگرام را کامل بچسبانید.');
        }
        $user = $request->user();
        $recipients = User::query()->whereIn('person_id', array_unique($data['person_ids']))->where('id', '!=', $user->id)
            ->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')->get()
            ->reject(fn (User $u) => UserBlock::between($user->id, $u->id));
        if ($recipients->isEmpty()) {
            throw new DomainException('هیچ‌کدام از این افراد عضو فعال سایت نیستند.');
        }

        $invite = DB::transaction(function () use ($user, $link, $data, $recipients) {
            $invite = new CallInvite([
                'provider' => $link['provider'],
                'url' => $link['url'],
                'title' => trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $data['title']) ?? '') ?: $link['label'],
                'starts_at' => $data['starts_at'] ?? null,
                'recipients' => $recipients->count(),
            ]);
            $invite->user_id = $user->id;
            $invite->save();
            $invite->invitees()->attach($recipients->pluck('id')->all());

            return $invite;
        });
        foreach ($recipients as $r) {
            try {
                $r->notify(new CallInvited($invite, $user));
            } catch (Throwable $e) {
                ErrorReporter::record($e, $request);
            }
        }

        return response()->json(['message' => PersianText::toPersianDigits('دعوت برای '.$recipients->count().' نفر فرستاده شد.'), 'id' => $invite->id], 201);
    }

    public function destroyInvite(Request $request, CallInvite $invite): JsonResponse
    {
        abort_unless($invite->user_id === $request->user()->id, 404);
        $invite->delete();

        return response()->json(['message' => 'دعوت حذف شد.']);
    }
}
