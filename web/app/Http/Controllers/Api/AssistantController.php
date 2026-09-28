<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Ai\AssistantService;
use App\Services\Ai\PhotoRestorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $this->assistant->configured(),
            'provider' => $this->assistant->label(),
            'remaining' => $this->assistant->remaining($request->user()),
            'max_chars' => AssistantService::MAX_CHARS,
            'modes' => collect(AssistantService::MODES)->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'emoji' => $m['emoji'], 'starter' => $m['starter']])->values(),
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
