<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Services\Messaging\MessagingService;
use App\Services\Support\SupportService;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * گفتگوی عضو با پشتیبانی سایت (و پاسخ مدیران در پنل مدیریت)
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportService $support) {}

    /** گفتگوی خودم با پشتیبانی */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $thread = SupportThread::query()->where('user_id', $user->id)->first();

        return response()->json($this->payload($request, $thread));
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.(MessagingService::MAX_LENGTH * 2)]]);
        $thread = $this->support->threadFor($request->user());
        $message = $this->support->post($request->user(), $thread, $data['body']);

        return response()->json(['data' => $this->support->present($message, $request->user())], 201);
    }

    public function sendVoice(Request $request): JsonResponse
    {
        $data = $this->validateVoice($request);
        $thread = $this->support->threadFor($request->user());
        $message = $this->support->post($request->user(), $thread, '', $data['voice'], $data['waveform'] ?? null);

        return response()->json(['data' => $this->support->present($message, $request->user())], 201);
    }

    public function destroy(Request $request, int $message): JsonResponse
    {
        $msg = SupportMessage::query()->with('thread')->find($message);
        $user = $request->user();
        if ($msg === null || ($msg->thread->user_id !== $user->id && ! $user->isAdmin())) {
            throw new DomainException('پیام پیدا نشد.', 404);
        }
        $this->support->delete($user, $msg);

        return response()->json(['data' => $this->support->present($msg, $user)]);
    }

    // ------------------------------------------------------------------ مدیران

    /** فهرست گفتگوهای پشتیبانی (نخوانده‌ها اول) */
    public function threads(Request $request): JsonResponse
    {
        $threads = SupportThread::query()->with('user.person.avatar')->whereNotNull('last_message_at')
            ->orderByDesc('last_message_at')->limit(200)->get();
        $last = SupportMessage::query()->whereIn('id', SupportMessage::query()->whereIn('thread_id', $threads->pluck('id'))
            ->selectRaw('max(id)')->groupBy('thread_id'))->get()->keyBy('thread_id');
        // تعداد نخوانده‌ها با یک پرس‌وجو (نه یکی برای هر گفتگو)
        $unreadCounts = SupportMessage::query()
            ->join('support_threads', 'support_threads.id', '=', 'support_messages.thread_id')
            ->whereIn('support_messages.thread_id', $threads->pluck('id'))
            ->where('support_messages.from_admin', false)
            ->whereColumn('support_messages.id', '>', 'support_threads.admin_read_id')
            ->groupBy('support_messages.thread_id')
            ->selectRaw('support_messages.thread_id as tid, count(*) as c')
            ->pluck('c', 'tid');
        $rows = $threads->map(function (SupportThread $t) use ($last, $unreadCounts) {
            $m = $last->get($t->id);
            $unread = (int) ($unreadCounts[$t->id] ?? 0);

            return [
                'id' => $t->id,
                'user' => $t->user ? ['id' => $t->user->id, 'name' => $t->user->displayName(), 'person' => $t->user->person ? NodePresenter::person($t->user->person) : null] : null,
                'last' => $m ? ['text' => $m->deleted_at ? 'پیام حذف شد' : ($m->body === '' && $m->voice_id ? '🎤 پیام صوتی' : mb_substr($m->body, 0, 80)), 'from_admin' => $m->from_admin, 'at' => $m->created_at?->toIso8601String()] : null,
                'unread' => $unread,
            ];
        })->sortByDesc(fn ($r) => $r['unread'] > 0)->values();

        return response()->json(['data' => $rows, 'unread_threads' => $this->support->unreadThreadsForAdmins()]);
    }

    public function thread(Request $request, SupportThread $thread): JsonResponse
    {
        return response()->json($this->payload($request, $thread));
    }

    public function reply(Request $request, SupportThread $thread): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.(MessagingService::MAX_LENGTH * 2)]]);
        $message = $this->support->post($request->user(), $thread, $data['body']);

        return response()->json(['data' => $this->support->present($message, $request->user())], 201);
    }

    public function replyVoice(Request $request, SupportThread $thread): JsonResponse
    {
        $data = $this->validateVoice($request);
        $message = $this->support->post($request->user(), $thread, '', $data['voice'], $data['waveform'] ?? null);

        return response()->json(['data' => $this->support->present($message, $request->user())], 201);
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function validateVoice(Request $request): array
    {
        return $request->validate([
            'voice' => ['required', 'file', 'max:'.(int) config('pedigree.voice.max_kb', 10240)],
            'waveform' => ['nullable', 'string', 'max:400'],
        ]);
    }

    private function payload(Request $request, ?SupportThread $thread): array
    {
        $viewer = $request->user();
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $messages = collect();
        if ($thread) {
            $messages = SupportMessage::query()->with(['voice', 'sender'])->where('thread_id', $thread->id)
                ->when(isset($data['after']), fn ($q) => $q->where('id', '>', $data['after'])->orderBy('id')->limit(100),
                    fn ($q) => $q->orderByDesc('id')->limit(100))
                ->get()->sortBy('id')->values();
            $this->support->markRead($viewer, $thread);
        }
        $owner = $thread?->user;

        return [
            'thread' => $thread ? [
                'id' => $thread->id,
                'user' => $owner ? ['id' => $owner->id, 'name' => $owner->displayName(), 'person' => $owner->person ? NodePresenter::person($owner->person) : null] : null,
            ] : null,
            'data' => $messages->map(fn (SupportMessage $m) => $this->support->present($m, $viewer))->values(),
            'voice' => ['enabled' => (bool) config('pedigree.voice.enabled', true), 'max_seconds' => (int) config('pedigree.voice.max_seconds', 300)],
        ];
    }
}
