<?php

namespace App\Services\Calls;

use App\Exceptions\DomainException;
use App\Models\Call;
use App\Models\CallParticipant;
use App\Models\CallSignal;
use App\Models\User;
use App\Notifications\IncomingCall;
use App\Notifications\MissedCall;
use App\Services\Messaging\MessagingService;
use App\Services\Tree\NodePresenter;
use App\Support\ErrorReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * تماس صوتی و تصویری مستقیم (WebRTC) بین اعضا، دونفره یا گروهی (هر نفر مستقیم به بقیه وصل می‌شود).
 *
 * صدا و تصویر مستقیم بین گوشی‌ها رد و بدل می‌شود و از سرور سایت نمی‌گذرد؛ سرور فقط پیام‌های کوچک راه‌اندازی
 * (پیشنهاد/پاسخ SDP و نامزدهای ICE) را بین شرکت‌کننده‌ها جابه‌جا می‌کند و بعد از چند دقیقه پاکشان می‌کند.
 * اگر اینترنت دو طرف اتصال مستقیم را اجازه ندهد، سرور رله (TURN) تنظیم‌شده در پنل مدیریت به کار می‌رود.
 *
 * امنیت: فقط اعضای فعالی که همدیگر را مسدود نکرده‌اند؛ هر پیام فقط به شرکت‌کنندگان همان تماس؛ اندازه و تعداد
 * پیام‌ها و تماس‌ها محدود؛ زنگ خوردن فقط با کش (بدون بار روی پایگاه‌داده).
 */
class CallService
{
    /** مدت زنگ خوردن (ثانیه) */
    public const RING_SECONDS = 45;

    /** شرکت‌کننده‌ای که این مدت خبری از او نشده، از تماس خارج‌شده حساب می‌شود */
    public const GONE_SECONDS = 40;

    public const MAX_SIGNAL_BYTES = 30_000;

    public const MAX_SIGNALS_PER_CALL = 3000;

    public const DAILY_CALLS = 150;

    public function __construct(private readonly MessagingService $messaging) {}

    public static function enabled(): bool
    {
        return (bool) config('pedigree.calls.enabled', true);
    }

    public static function maxParticipants(): int
    {
        return max(2, min(6, (int) config('pedigree.calls.max_participants', 4)));
    }

    /**
     * شروع تماس با یک یا چند عضو
     *
     * @param  int[]  $userIds
     *
     * @throws DomainException
     */
    public function start(User $caller, array $userIds, string $kind): Call
    {
        if (! self::enabled()) {
            throw new DomainException('تماس داخل سایت خاموش است.', 403, 'calls_disabled');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), fn (int $id) => $id > 0 && $id !== $caller->id)));
        if ($ids === []) {
            throw new DomainException('با چه کسی می‌خواهید تماس بگیرید؟');
        }
        if (count($ids) > self::maxParticipants() - 1) {
            throw new DomainException('در هر تماس گروهی حداکثر '.self::maxParticipants().' نفر (با خودتان) می‌توانند باشند.');
        }
        $today = Call::query()->where('created_by', $caller->id)->where('created_at', '>=', now()->subDay())->count();
        if ($today >= self::DAILY_CALLS) {
            throw new DomainException('تعداد تماس‌های امروز شما زیاد شده است؛ کمی بعد دوباره امتحان کنید.', 429, 'call_limit');
        }
        $users = User::query()->whereIn('id', $ids)->get();
        if ($users->count() !== count($ids)) {
            throw new DomainException('یکی از افراد پیدا نشد.', 404);
        }
        foreach ($users as $user) {
            // همان قانون پیام خصوصی: عضو فعال و بدون مسدودسازی
            $this->messaging->assertCanMessage($caller, $user);
        }

        $call = DB::transaction(function () use ($caller, $users, $kind) {
            $call = Call::query()->create(['created_by' => $caller->id, 'kind' => $kind, 'status' => 'ringing']);
            $call->participants()->create(['user_id' => $caller->id, 'state' => 'joined', 'joined_at' => now(), 'seen_at' => now()]);
            foreach ($users as $user) {
                $call->participants()->create(['user_id' => $user->id, 'state' => 'invited']);
            }

            return $call;
        });

        foreach ($users as $user) {
            Cache::put("call-ring:{$user->id}", $call->id, self::RING_SECONDS);
            try {
                $user->notify(new IncomingCall($call, $caller));
            } catch (Throwable $e) {
                ErrorReporter::record($e);
            }
        }

        return $call;
    }

    /** تماسی که همین حالا برای این کاربر زنگ می‌خورد (فقط از کش؛ سبک برای پرسش چندثانیه‌ای) */
    public function ringing(User $user): ?array
    {
        $id = Cache::get("call-ring:{$user->id}");
        if (! is_string($id)) {
            return null;
        }
        $call = Call::query()->with(['participants.user.person.avatar', 'creator.person.avatar'])->find($id);
        $me = $call?->participants->firstWhere('user_id', $user->id);
        if (! $call || $call->status === 'ended' || ! $me || $me->state !== 'invited' || $call->created_at->lt(now()->subSeconds(self::RING_SECONDS))) {
            Cache::forget("call-ring:{$user->id}");

            return null;
        }

        return [
            'id' => $call->id,
            'kind' => $call->kind,
            'caller' => $this->person($call->creator),
            'others' => $call->participants->filter(fn ($p) => $p->user_id !== $user->id && $p->user_id !== $call->created_by)
                ->map(fn ($p) => $this->person($p->user))->values(),
            'expires_in' => max(1, self::RING_SECONDS - (int) $call->created_at->diffInSeconds(now(), true)),
        ];
    }

    /** @throws DomainException */
    public function answer(User $user, Call $call): array
    {
        $me = $this->participant($user, $call);
        if ($call->status === 'ended') {
            throw new DomainException('این تماس تمام شده است.', 410, 'call_ended');
        }
        $me->forceFill(['state' => 'joined', 'joined_at' => $me->joined_at ?? now(), 'left_at' => null, 'seen_at' => now()])->save();
        if ($call->status === 'ringing') {
            $call->forceFill(['status' => 'active', 'started_at' => now()])->save();
        }
        Cache::forget("call-ring:{$user->id}");

        return $this->state($call->refresh(), $user, true);
    }

    public function decline(User $user, Call $call): void
    {
        $me = $this->participant($user, $call);
        Cache::forget("call-ring:{$user->id}");
        if ($me->state === 'invited') {
            $me->forceFill(['state' => 'declined', 'left_at' => now()])->save();
        }
        $this->settle($call->refresh());
    }

    public function leave(User $user, Call $call): void
    {
        $me = $this->participant($user, $call);
        Cache::forget("call-ring:{$user->id}");
        if (in_array($me->state, ['joined', 'invited'], true)) {
            $me->forceFill(['state' => $me->state === 'joined' ? 'left' : 'declined', 'left_at' => now()])->save();
        }
        $this->settle($call->refresh());
    }

    /**
     * پیام‌های راه‌اندازی تازه برای من و وضعیت تماس (هر یک تا دو ثانیه در طول تماس)
     *
     * @return array{signals: array, call: array}
     */
    public function poll(User $user, Call $call, int $after): array
    {
        $me = $this->participant($user, $call);
        if ($me->state === 'joined' && ($me->seen_at === null || $me->seen_at->lt(now()->subSeconds(5)))) {
            $me->forceFill(['seen_at' => now()])->save();
        }
        $this->settle($call);

        $signals = $me->state === 'joined'
            ? CallSignal::query()->where('call_id', $call->id)->where('to_user_id', $user->id)->where('id', '>', max(0, $after))
                ->orderBy('id')->limit(100)->get(['id', 'from_user_id', 'type', 'payload'])
                ->map(fn (CallSignal $s) => ['id' => $s->id, 'from' => $s->from_user_id, 'type' => $s->type, 'data' => json_decode($s->payload, true)])
                ->all()
            : [];

        return ['signals' => $signals, 'call' => $this->state($call->refresh(), $user)];
    }

    /** @throws DomainException */
    public function signal(User $user, Call $call, int $to, string $type, string $payload): void
    {
        $me = $this->participant($user, $call);
        if ($me->state !== 'joined' || $call->status === 'ended') {
            throw new DomainException('شما در این تماس نیستید.', 409, 'not_in_call');
        }
        if ($to === $user->id || ! $call->participants()->where('user_id', $to)->exists()) {
            throw new DomainException('گیرنده در این تماس نیست.', 422);
        }
        if (! in_array($type, CallSignal::TYPES, true) || strlen($payload) > self::MAX_SIGNAL_BYTES || ! json_validate($payload)) {
            throw new DomainException('پیام تماس نامعتبر است.', 422);
        }
        if (CallSignal::query()->where('call_id', $call->id)->count() >= self::MAX_SIGNALS_PER_CALL) {
            throw new DomainException('پیام‌های این تماس بیش از حد شده است.', 429);
        }
        CallSignal::query()->create(['call_id' => $call->id, 'from_user_id' => $user->id, 'to_user_id' => $to, 'type' => $type, 'payload' => $payload]);
    }

    /** وضعیت تماس برای یک شرکت‌کننده */
    public function state(Call $call, User $user, bool $withIce = false): array
    {
        $call->loadMissing('participants.user.person.avatar');
        $gone = now()->subSeconds(self::GONE_SECONDS);

        return array_filter([
            'id' => $call->id,
            'kind' => $call->kind,
            'status' => $call->status,
            'created_by' => $call->created_by,
            'started_at' => $call->started_at?->toIso8601String(),
            'me' => $user->id,
            'participants' => $call->participants->map(fn (CallParticipant $p) => $this->person($p->user) + [
                'user_id' => $p->user_id,
                'state' => $p->state,
                'online' => $p->state === 'joined' && $p->seen_at !== null && $p->seen_at->gt($gone),
            ])->values()->all(),
            'ice' => $withIce ? $this->iceConfig($user) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * سرورهای STUN/TURN برای مرورگر (از پنل مدیریت)؛ اعتبار TURN با رمز مشترک coturn موقت (۶ ساعت) ساخته می‌شود
     *
     * @return array{servers: array, relay_only: bool}
     */
    public function iceConfig(User $user): array
    {
        $servers = [];
        $stun = array_values(array_filter(array_map('trim', explode(',', (string) config('pedigree.calls.stun'))), fn ($u) => preg_match('#^stuns?:[A-Za-z0-9.\-]+(:\d{2,5})?$#', $u)));
        if ($stun) {
            $servers[] = ['urls' => array_slice($stun, 0, 4)];
        }
        $turn = array_values(array_filter(array_map('trim', explode(',', (string) config('pedigree.calls.turn_urls'))), fn ($u) => preg_match('#^turns?:[A-Za-z0-9.\-]+(:\d{2,5})?(\?transport=(udp|tcp))?$#', $u)));
        if ($turn) {
            $secret = (string) config('pedigree.calls.turn_secret');
            if ($secret !== '') {
                $username = (now()->addHours(6)->getTimestamp()).':'.$user->id;
                $servers[] = ['urls' => array_slice($turn, 0, 4), 'username' => $username, 'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true))];
            } elseif ((string) config('pedigree.calls.turn_username') !== '') {
                $servers[] = ['urls' => array_slice($turn, 0, 4), 'username' => (string) config('pedigree.calls.turn_username'), 'credential' => (string) config('pedigree.calls.turn_credential')];
            }
        }

        return ['servers' => $servers, 'relay_only' => $turn !== [] && (bool) config('pedigree.calls.relay_only', false)];
    }

    /**
     * تماس‌های اخیر من (برای فهرست «تماس‌ها»)
     *
     * @return array<int, array>
     */
    public function history(User $user, int $limit = 40): array
    {
        return CallParticipant::query()->where('user_id', $user->id)->latest('id')->limit($limit)
            ->with(['call.participants.user.person.avatar'])->get()
            ->filter(fn (CallParticipant $p) => $p->call !== null)
            ->map(function (CallParticipant $mine) use ($user) {
                $call = $mine->call;
                $others = $call->participants->filter(fn ($p) => $p->user_id !== $user->id);

                return [
                    'id' => $call->id,
                    'kind' => $call->kind,
                    'outgoing' => $call->created_by === $user->id,
                    'state' => $mine->state,
                    'status' => $call->status,
                    'answered' => $call->started_at !== null,
                    'at' => $call->created_at->toIso8601String(),
                    'duration' => $call->started_at && $call->ended_at ? (int) $call->started_at->diffInSeconds($call->ended_at, true) : null,
                    'people' => $others->map(fn ($p) => $this->person($p->user) + ['user_id' => $p->user_id])->values()->all(),
                ];
            })->values()->all();
    }

    /** پاک‌سازی: پیام‌های راه‌اندازی قدیمی، تماس‌های رهاشده و تماس‌های خیلی قدیمی */
    public function prune(): void
    {
        CallSignal::query()->where('created_at', '<', now()->subMinutes(15))->delete();
        Call::query()->where('status', '!=', 'ended')->where('updated_at', '<', now()->subMinutes(3))->limit(500)->get()
            ->each(fn (Call $call) => $this->settle($call));
        Call::query()->where('created_at', '<', now()->subDays(90))->delete();
    }

    /**
     * وضعیت تماس را با واقعیت هماهنگ می‌کند: زنگ بی‌پاسخ ← «از دست رفته»، شرکت‌کننده بی‌خبر ← خارج، کمتر از دو نفر ← پایان
     */
    private function settle(Call $call): void
    {
        if ($call->status === 'ended') {
            return;
        }
        $call->load('participants');
        $gone = now()->subSeconds(self::GONE_SECONDS);
        foreach ($call->participants as $p) {
            if ($p->state === 'joined' && ($p->seen_at === null || $p->seen_at->lt($gone))) {
                $p->forceFill(['state' => 'left', 'left_at' => now()])->save();
            }
        }
        $ringingOver = $call->created_at->lt(now()->subSeconds(self::RING_SECONDS));
        if ($ringingOver) {
            foreach ($call->participants->where('state', 'invited') as $p) {
                $p->forceFill(['state' => 'missed'])->save();
                Cache::forget("call-ring:{$p->user_id}");
                $this->notifyMissed($call, $p);
            }
        }
        $joined = $call->participants->where('state', 'joined')->count();
        $waiting = $call->participants->where('state', 'invited')->count();
        if ($joined === 0 || ($joined === 1 && ($waiting === 0 || $call->status === 'active'))) {
            $call->forceFill(['status' => 'ended', 'ended_at' => now()])->save();
            foreach ($call->participants as $p) {
                if ($p->state === 'joined') {
                    $p->forceFill(['state' => 'left', 'left_at' => now()])->save();
                } elseif ($p->state === 'invited') {
                    $p->forceFill(['state' => 'missed'])->save();
                    Cache::forget("call-ring:{$p->user_id}");
                    $this->notifyMissed($call, $p);
                }
            }
        } else {
            $call->touch();
        }
    }

    private function notifyMissed(Call $call, CallParticipant $p): void
    {
        try {
            $caller = $call->creator ?? User::query()->find($call->created_by);
            if ($caller && $p->user) {
                $p->user->notify(new MissedCall($call, $caller));
            }
        } catch (Throwable $e) {
            ErrorReporter::record($e);
        }
    }

    /** @throws DomainException */
    private function participant(User $user, Call $call): CallParticipant
    {
        $me = CallParticipant::query()->where('call_id', $call->id)->where('user_id', $user->id)->first();
        if (! $me) {
            // تماس دیگران: وجودش هم فاش نشود
            throw new DomainException('تماس پیدا نشد.', 404);
        }

        return $me;
    }

    /** نام و عکس یک عضو برای نمایش در تماس */
    private function person(?User $user): array
    {
        return [
            'user_id' => $user?->id,
            'name' => $user?->displayName() ?? 'عضو',
            'person' => $user?->person ? NodePresenter::person($user->person) : null,
        ];
    }
}
