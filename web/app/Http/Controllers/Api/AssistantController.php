<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * دستیار هوش مصنوعی: گفتگو در مرورگر نگه داشته می‌شود و سرور چیزی از آن ذخیره نمی‌کند.
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantService $assistant) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'enabled' => $this->assistant->configured(),
            'provider' => $this->assistant->label(),
            'remaining' => $this->assistant->remaining($request->user()),
            'max_chars' => AssistantService::MAX_CHARS,
        ]]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:60'],
            'messages.*' => ['required', 'array:role,content'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:20000'],
        ]);
        $reply = $this->assistant->chat($request->user(), $data['messages']);

        return response()->json(['data' => [
            'reply' => $reply,
            'remaining' => $this->assistant->remaining($request->user()),
        ]]);
    }
}
