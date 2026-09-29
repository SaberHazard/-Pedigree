<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\GroupMessage;
use App\Models\GroupReport;
use App\Models\User;
use App\Rules\PartialDateRule;
use App\Services\Group\GroupService;
use App\Services\Media\MediaFormats;
use App\Services\Tree\NodePresenter;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * «گروه خاطرات خاندان» برای همه اعضای فعال.
 */
class GroupController extends Controller
{
    private const PAGE = 40;

    public function __construct(private readonly GroupService $group) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertEnabled();
        $pinned = GroupMessage::query()->whereNotNull('pinned_at')->whereNull('deleted_at')->orderByDesc('pinned_at')->limit(GroupService::PINNED_MAX)->get();

        return response()->json(['data' => [
            'name' => (string) config('pedigree.group.name'),
            'members' => User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')->count(),
            'can_post' => $this->group->postProblem($user) === null,
            'problem' => $this->group->postProblem($user),
            'is_admin' => $user->isAdmin(),
            'reactions' => GroupService::REACTIONS,
            'max_length' => (int) config('pedigree.group.max_length', 2000),
            'allow_links' => (bool) config('pedigree.group.allow_links'),
            'video_enabled' => (bool) config('pedigree.media.video.enabled'),
            'read_id' => (int) $user->group_read_id,
            'unread' => $this->group->unreadCount($user),
            'pinned' => $this->group->present($pinned, $user),
        ]]);
    }

    /** پیام‌ها: آخرین ۴۰ تا، یا قدیمی‌تر از before، یا تازه‌تر از after (+ پیام‌های تغییرکرده از since) */
    public function messages(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $user = $request->user();
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'integer', 'min:0'],
            'around' => ['nullable', 'integer', 'min:1'],
            'since' => ['nullable', 'date'],
        ]);

        $query = GroupMessage::query();
        if (isset($data['after'])) {
            $messages = $query->where('id', '>', $data['after'])->orderBy('id')->limit(100)->get();
        } elseif (isset($data['around'])) {
            $older = GroupMessage::query()->where('id', '<=', $data['around'])->orderByDesc('id')->limit(20)->get();
            $newer = GroupMessage::query()->where('id', '>', $data['around'])->orderBy('id')->limit(20)->get();
            $messages = $older->reverse()->concat($newer)->values();
        } else {
            $messages = $query->when(isset($data['before']), fn ($q) => $q->where('id', '<', $data['before']))
                ->orderByDesc('id')->limit(self::PAGE)->get()->reverse()->values();
        }

        // واکنش‌ها، حذف‌ها و سنجاق‌های تازه روی پیام‌های قبلی
        $updated = [];
        if (isset($data['after'], $data['since']) && $data['after'] > 0) {
            // یک ثانیه هم‌پوشانی تا تغییرِ همان ثانیه از دست نرود (تکرار بی‌ضرر است)
            $since = Carbon::parse($data['since'])->setTimezone((string) config('app.timezone'))->subSecond();
            $changed = GroupMessage::query()->where('id', '<=', $data['after'])->where('updated_at', '>=', $since)
                ->orderByDesc('id')->limit(100)->get();
            $updated = $this->group->present($changed, $user);
        }

        return response()->json([
            'data' => $this->group->present($messages, $user),
            'updated' => $updated,
            'has_more' => ! isset($data['after']) && ! isset($data['around']) && $messages->count() === self::PAGE,
            'server_time' => now()->toIso8601String(),
            'unread' => $this->group->unreadCount($user),
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate([
            'body' => ['required', 'string', 'max:6000'],
            'reply_to' => ['nullable', 'integer', 'min:1'],
        ]);
        $message = $this->group->postText($request->user(), $data['body'], $data['reply_to'] ?? null);
        $this->group->markRead($request->user(), $message->id);

        return response()->json(['data' => $this->group->present(collect([$message]), $request->user())[0]], 201);
    }

    /** عکس یا فیلم خاطره (در پروفایل فرستنده هم ذخیره می‌شود) */
    public function sendMedia(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $videoOn = (bool) config('pedigree.media.video.enabled');
        $maxKb = max((int) config('pedigree.media.image.max_upload_kb'), $videoOn ? (int) config('pedigree.media.video.max_upload_mb') * 1024 : 0);
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb],
            'caption' => ['required', 'string', 'max:1000'],
            'taken_at' => ['nullable', new PartialDateRule],
            'tags' => ['nullable', 'array', 'max:'.GroupService::TAGS_MAX],
            'tags.*' => ['uuid'],
            'reply_to' => ['nullable', 'integer', 'min:1'],
        ], [], ['file' => 'فایل', 'caption' => 'توضیح']);

        $file = $request->file('file');
        $kind = MediaFormats::classify((string) $file->getRealPath(), $file->getMimeType());
        if ($kind === null || ($kind === 'video' && ! $videoOn)) {
            throw ValidationException::withMessages(['file' => 'در گروه فقط عکس'.($videoOn ? ' و فیلم' : '').' می‌شود گذاشت.']);
        }
        if ($kind === 'video' && $file->getSize() > (int) config('pedigree.media.video.max_upload_mb') * 1024 * 1024) {
            throw new DomainException(PersianText::toPersianDigits('حجم فیلم بیش از '.(int) config('pedigree.media.video.max_upload_mb').' مگابایت است.'));
        }

        $message = $this->group->postMedia($request->user(), $file, $data['caption'], $data['taken_at'] ?? null, $data['tags'] ?? [], $data['reply_to'] ?? null);
        $this->group->markRead($request->user(), $message->id);

        return response()->json(['data' => $this->group->present(collect([$message]), $request->user())[0]], 201);
    }

    /** پیام صوتی در گروه (خاطره با صدای خود شخص) */
    public function sendVoice(Request $request): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate([
            'voice' => ['required', 'file', 'max:'.(int) config('pedigree.voice.max_kb', 10240)],
            'waveform' => ['nullable', 'string', 'max:400'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'reply_to' => ['nullable', 'integer', 'min:1'],
        ]);
        $message = $this->group->postVoice($request->user(), $data['voice'], $data['waveform'] ?? null, $data['caption'] ?? null, $data['reply_to'] ?? null);

        return response()->json(['data' => $this->group->present(collect([$message]), $request->user())[0]], 201);
    }

    public function react(Request $request, int $message): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate(['emoji' => ['nullable', 'string', Rule::in(GroupService::REACTIONS)]]);
        $msg = GroupMessage::query()->findOrFail($message);
        $this->group->react($request->user(), $msg, $data['emoji'] ?? null);

        return response()->json(['data' => $this->group->present(collect([$msg->fresh()]), $request->user())[0]]);
    }

    public function destroy(Request $request, int $message): JsonResponse
    {
        $msg = GroupMessage::query()->findOrFail($message);
        $this->group->delete($request->user(), $msg);

        return response()->json(['message' => 'پیام حذف شد.', 'data' => $this->group->present(collect([$msg->fresh()]), $request->user())[0]]);
    }

    public function report(Request $request, int $message): JsonResponse
    {
        $this->assertEnabled();
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->group->report($request->user(), GroupMessage::query()->findOrFail($message), $data['reason'] ?? null);

        return response()->json(['message' => 'گزارش شما برای مدیران فرستاده شد. ممنون.']);
    }

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate(['up_to' => ['required', 'integer', 'min:0']]);
        $this->group->markRead($request->user(), $data['up_to']);

        return response()->json(['unread' => $this->group->unreadCount($request->user())]);
    }

    // ------------------------------------------------------------------ مدیران

    public function pin(Request $request, int $message): JsonResponse
    {
        $data = $request->validate(['pinned' => ['required', 'boolean']]);
        $msg = GroupMessage::query()->findOrFail($message);
        $this->group->pin($request->user(), $msg, $data['pinned']);

        return response()->json(['message' => $data['pinned'] ? 'سنجاق شد.' : 'از سنجاق برداشته شد.']);
    }

    public function reports(Request $request): JsonResponse
    {
        $open = GroupReport::query()->with(['message.user.person', 'user.person'])->whereNull('resolved_at')->latest('id')->limit(200)->get();

        return response()->json(['data' => $open->groupBy('message_id')->map(function ($rows) use ($request) {
            $message = $rows->first()->message;

            return [
                'message' => $message ? $this->group->present(collect([$message]), $request->user())[0] : null,
                'author' => $message?->user ? ['id' => $message->user->id, 'name' => $message->user->displayName(), 'muted_until' => $message->user->group_muted_until?->toIso8601String()] : null,
                'reports' => $rows->map(fn (GroupReport $r) => ['id' => $r->id, 'by' => $r->user?->displayName(), 'reason' => $r->reason, 'at' => $r->created_at?->toIso8601String()])->values(),
            ];
        })->values(), 'muted' => $this->mutedList()]);
    }

    /** رسیدگی به گزارش: رد گزارش، حذف پیام، یا حذف و سکوت فرستنده */
    public function resolve(Request $request, int $message): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['dismiss', 'delete', 'mute'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);
        $msg = GroupMessage::query()->findOrFail($message);
        if ($data['action'] !== 'dismiss' && ! $msg->deleted_at) {
            $this->group->delete($request->user(), $msg);
        }
        if ($data['action'] === 'mute' && $msg->user) {
            $this->group->mute($request->user(), $msg->user, $data['days'] ?? null);
        }
        GroupReport::query()->where('message_id', $msg->id)->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);

        return response()->json(['message' => 'رسیدگی شد.']);
    }

    public function mute(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:0', 'max:365']]);
        $this->group->mute($request->user(), $user, array_key_exists('days', $data) ? $data['days'] : null);

        return response()->json(['message' => ($data['days'] ?? null) === 0 ? 'محدودیت برداشته شد.' : 'این عضو دیگر نمی‌تواند در گروه پیام بدهد.', 'muted' => $this->mutedList()]);
    }

    private function mutedList(): array
    {
        return User::query()->with('person.avatar')->where('group_muted_until', '>', now())->limit(200)->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->displayName(),
                'person' => $u->person ? NodePresenter::person($u->person) : null,
                'until' => $u->group_muted_until?->toIso8601String(),
                'forever' => $u->group_muted_until && $u->group_muted_until->year >= 2100,
            ])->values()->all();
    }

    private function assertEnabled(): void
    {
        if (! $this->group->enabled()) {
            throw new DomainException('گروه خاندان فعلاً غیرفعال است.', 403, 'group_off');
        }
    }
}
