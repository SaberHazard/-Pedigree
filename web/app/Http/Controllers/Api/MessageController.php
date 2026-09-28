<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\Person;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Messaging\MessagingService;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * پیام‌رسان داخلی اعضا (فقط متن و ایموجی).
 *
 * همه مسیرها فقط برای دو طرف گفتگو پاسخ می‌دهند؛ برای بقیه «پیدا نشد» (۴۰۴) تا حتی وجود گفتگو معلوم نشود.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessagingService $messaging) {}

    /** فهرست گفتگوهای من با آخرین پیام و تعداد نخوانده */
    public function index(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        $conversations = Conversation::query()->for($user)->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')->limit(100)->get();

        $otherIds = $conversations->map(fn (Conversation $c) => $c->otherId($user))->all();
        $others = User::query()->with('person.avatar')->whereIn('id', $otherIds)->get()->keyBy('id');
        $ids = $conversations->pluck('id')->all();

        $unread = $ids ? DirectMessage::query()->whereIn('conversation_id', $ids)->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')->whereNull('deleted_at')
            ->select('conversation_id', DB::raw('count(*) as n'))->groupBy('conversation_id')->pluck('n', 'conversation_id') : collect();
        $lastIds = $ids ? DirectMessage::query()->whereIn('conversation_id', $ids)
            ->select(DB::raw('max(id) as id'))->groupBy('conversation_id')->pluck('id') : collect();
        $last = DirectMessage::query()->whereIn('id', $lastIds)->get()->keyBy('conversation_id');

        return response()->json([
            'data' => $conversations->map(function (Conversation $c) use ($user, $others, $unread, $last) {
                $other = $others->get($c->otherId($user));
                $message = $last->get($c->id);

                return [
                    'id' => $c->id,
                    'other' => $this->presentUser($other),
                    'last' => $message ? [
                        'text' => $message->deleted_at ? null : mb_substr($message->body, 0, 80),
                        'deleted' => $message->deleted_at !== null,
                        'mine' => $message->sender_id === $user->id,
                        'read' => $message->read_at !== null,
                        'at' => $message->created_at?->toIso8601String(),
                    ] : null,
                    'unread' => (int) ($unread[$c->id] ?? 0),
                ];
            })->values(),
            'unread_total' => $this->messaging->unreadCount($user),
        ]);
    }

    /** شروع (یا ادامه) گفتگو با صاحب یک پروفایل */
    public function start(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate(['person_id' => ['required', 'uuid']]);
        $person = Person::query()->findOrFail($data['person_id']);
        $other = User::query()->where('person_id', $person->id)->first();
        if ($other === null) {
            throw new DomainException('این شخص هنوز عضو سایت نیست؛ وقتی وارد سایت شود می‌توانید به او پیام بدهید.', 422, 'not_member');
        }
        $this->messaging->assertCanMessage($request->user(), $other);
        $conversation = Conversation::findOrCreateBetween($request->user(), $other);

        return response()->json(['data' => ['id' => $conversation->id]]);
    }

    /** پیام‌های یک گفتگو (۵۰ تایی؛ before برای قدیمی‌تر، after برای تازه‌ها) — پیام‌های طرف مقابل خوانده می‌شوند */
    public function show(Request $request, int $conversation): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        $conv = $this->conversationFor($user, $conversation);
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = DirectMessage::query()->where('conversation_id', $conv->id);
        if (isset($data['after'])) {
            $messages = $query->where('id', '>', $data['after'])->orderBy('id')->limit(100)->get();
        } else {
            $messages = $query->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))
                ->orderByDesc('id')->limit(50)->get()->reverse()->values();
        }
        $this->messaging->markRead($user, $conv);
        $other = User::query()->with('person.avatar')->find($conv->otherId($user));

        return response()->json([
            'conversation' => [
                'id' => $conv->id,
                'other' => $this->presentUser($other),
                'blocked_by_me' => UserBlock::query()->where('blocker_id', $user->id)->where('blocked_id', $other?->id)->exists(),
                'can_send' => $other !== null && ! UserBlock::between($user->id, $other->id) && $other->isActive(),
                // آخرین پیام من که طرف مقابل خوانده (برای تیک دوتایی)
                'read_up_to' => (int) DirectMessage::query()->where('conversation_id', $conv->id)->where('sender_id', $user->id)->whereNotNull('read_at')->max('id'),
            ],
            'data' => $messages->map(fn (DirectMessage $m) => $this->presentMessage($m, $user)),
            'has_more' => ! isset($data['after']) && $messages->count() === 50,
        ]);
    }

    public function send(Request $request, int $conversation): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate(['body' => ['required', 'string', 'max:'.(MessagingService::MAX_LENGTH * 2)]]);
        $conv = $this->conversationFor($request->user(), $conversation);
        $message = $this->messaging->send($request->user(), $conv, $data['body']);

        return response()->json(['data' => $this->presentMessage($message, $request->user())], 201);
    }

    /** حذف پیام توسط فرستنده (برای هر دو طرف) */
    public function destroy(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $msg = DirectMessage::query()->find($message);
        if ($msg === null || $msg->sender_id !== $user->id) {
            throw new DomainException('پیام پیدا نشد.', 404);
        }
        $msg->forceFill(['body' => '', 'deleted_at' => now()])->save();

        return response()->json(['data' => $this->presentMessage($msg, $user)]);
    }

    /** مسدود کردن / رفع مسدودی طرف مقابل */
    public function block(Request $request, int $conversation): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        $conv = $this->conversationFor($user, $conversation);
        $blocked = $request->validate(['blocked' => ['required', 'boolean']])['blocked'];
        $otherId = $conv->otherId($user);
        if ($blocked) {
            UserBlock::query()->firstOrCreate(['blocker_id' => $user->id, 'blocked_id' => $otherId]);
        } else {
            UserBlock::query()->where('blocker_id', $user->id)->where('blocked_id', $otherId)->delete();
        }

        return response()->json(['message' => $blocked ? 'مسدود شد؛ دیگر نمی‌تواند به شما پیام بدهد.' : 'از مسدودی خارج شد.', 'blocked' => $blocked]);
    }

    public function unread(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->messaging->unreadCount($request->user())]);
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function assertEnabled(): void
    {
        if (! config('pedigree.messaging.enabled', true)) {
            throw new DomainException('پیام‌رسان خصوصی فعلاً غیرفعال است.', 403, 'messaging_off');
        }
    }

    private function conversationFor(User $user, int $id): Conversation
    {
        $conv = Conversation::query()->find($id);
        if ($conv === null || ! $conv->hasParticipant($user)) {
            throw new DomainException('گفتگو پیدا نشد.', 404);
        }

        return $conv;
    }

    private function presentUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'user_id' => $user->id,
            'person' => $user->person ? NodePresenter::person($user->person) : null,
            'name' => $user->displayName(),
            'active' => $user->isActive(),
        ];
    }

    private function presentMessage(DirectMessage $m, User $viewer): array
    {
        return [
            'id' => $m->id,
            'mine' => $m->sender_id === $viewer->id,
            'body' => $m->deleted_at ? null : $m->body,
            'deleted' => $m->deleted_at !== null,
            'read' => $m->read_at !== null,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }
}
