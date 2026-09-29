<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Ai\AssistantService;
use App\Services\Ai\FamilyFacts;
use App\Services\Ai\PhotoRestorer;
use App\Services\Ai\VoiceAi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * دستیار هوش مصنوعی: گفتگو در مرورگر نگه داشته می‌شود و سرور چیزی از آن ذخیره نمی‌کند.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantService $assistant,
        private readonly PhotoRestorer $restorer,
        private readonly VoiceAi $voice,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $this->assistant->configured(),
            'provider' => $this->assistant->label(),
            'remaining' => $this->assistant->remaining($request->user()),
            'max_chars' => AssistantService::MAX_CHARS,
            'modes' => collect(AssistantService::MODES)
                ->reject(fn ($m, $key) => $key === 'family_quiz' && ! FamilyFacts::enabled())
                ->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'emoji' => $m['emoji'], 'starter' => $m['starter'], 'family' => $key === 'family_quiz'])->values(),
            // صدا: گفتار به متن، خواندن پاسخ و گفتگوی صوتی زنده
            'voice' => $this->voice->summary($request->user()),
            'restore' => [
                'enabled' => $this->restorer->enabled(),
                'remaining' => $this->restorer->remaining($request->user()),
                'modes' => PhotoRestorer::MODES,
            ],
        ]]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:60'],
            'messages.*' => ['required', 'array:role,content'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:20000'],
            'mode' => ['nullable', 'string', Rule::in(array_keys(AssistantService::MODES))],
        ]);
        $reply = $this->assistant->chat($request->user(), $data['messages'], $data['mode'] ?? 'chat');

        return response()->json(['data' => [
            'reply' => $reply,
            'remaining' => $this->assistant->remaining($request->user()),
        ]]);
    }

    /** پیام صوتی فارسی به دستیار ← متن (پس از آن مثل پیام نوشتاری فرستاده می‌شود) */
    public function transcribe(Request $request): JsonResponse
    {
        $data = $request->validate(['voice' => ['required', 'file', 'max:6144']]);
        $text = $this->voice->transcribe($request->user(), $data['voice']);

        return response()->json(['data' => ['text' => $text, 'remaining' => $this->assistant->remaining($request->user())]]);
    }

    /** خواندن پاسخ دستیار با صدا (فایل صوتی؛ در سرور ذخیره نمی‌شود) */
    public function speak(Request $request): Response
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:6000']]);
        [$audio, $mime] = $this->voice->speak($request->user(), $data['text']);

        return response($audio, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    /** گفتگوی صوتی زنده: کلید یک‌بارمصرف کوتاه‌عمر برای اتصال مستقیم مرورگر به سرویس */
    public function live(Request $request): JsonResponse
    {
        $data = $request->validate(['mode' => ['nullable', 'string', Rule::in(array_keys(AssistantService::MODES))]]);

        return response()->json(['data' => $this->voice->liveSession($request->user(), $data['mode'] ?? 'chat')]);
    }

    /** بازسازی یا رنگی کردن یک عکس قدیمی؛ نسخه تازه در همان پروفایل ذخیره می‌شود */
    public function restorePhoto(Request $request, Media $media): JsonResponse
    {
        $person = $media->person;
        abort_if($person === null, 404);
        Gate::authorize('view', $person);
        Gate::authorize('uploadMedia', $person);
        $data = $request->validate(['mode' => ['required', 'string', Rule::in(array_keys(PhotoRestorer::MODES))]]);

        $restored = $this->restorer->restore($request->user(), $media, $data['mode']);

        return response()->json([
            'data' => new MediaResource($restored),
            'remaining' => $this->restorer->remaining($request->user()),
            'message' => $restored->isApproved() ? 'نسخه تازه در گالری ذخیره شد.' : 'نسخه تازه ساخته شد و پس از تأیید بستگان نمایش داده می‌شود.',
        ], 201);
    }
}
