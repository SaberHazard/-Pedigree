<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Person;
use App\Services\Ai\AssistantService;
use App\Services\Ai\Biographer;
use App\Services\Ai\PhotoReader;
use App\Services\Insights\ClanInsights;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * بینش‌های خاندان و ابزارهای هوشمند:
 * بررسی ناسازگاری، آمار خاندان، خط زمان زندگی، زندگی‌نامه‌نویس و خواندن عکس/سند با هوش مصنوعی.
 */
class InsightController extends Controller
{
    public function __construct(
        private readonly ClanInsights $insights,
        private readonly AssistantService $assistant,
    ) {}

    public function stats(): JsonResponse
    {
        return response()->json(['data' => $this->insights->stats()]);
    }

    public function consistency(): JsonResponse
    {
        return response()->json(['data' => $this->insights->consistency() + ['codes' => ClanInsights::CODES]]);
    }

    public function timeline(Person $person): JsonResponse
    {
        Gate::authorize('view', $person);

        return response()->json(['data' => $this->insights->timeline($person)]);
    }

    /** وضعیت ابزارهای هوشمند برای نمایش دکمه‌ها */
    public function aiTools(Request $request): JsonResponse
    {
        $ready = $this->assistant->configured();

        return response()->json(['data' => [
            'biographer' => $ready && Biographer::enabled(),
            'photo_reader' => $ready && PhotoReader::enabled(),
            'tones' => collect(Biographer::TONES)->map(fn ($t) => $t['label']),
            'tasks' => PhotoReader::TASKS,
            'remaining' => $this->assistant->remaining($request->user()),
        ]]);
    }

    /** پیش‌نویس زندگی‌نامه با هوش مصنوعی (ذخیره نمی‌شود؛ کاربر ویرایش و ذخیره می‌کند) */
    public function biography(Request $request, Person $person, Biographer $biographer): JsonResponse
    {
        Gate::authorize('update', $person);
        $data = $request->validate(['tone' => ['nullable', 'string', Rule::in(array_keys(Biographer::TONES))]]);

        return response()->json(['data' => [
            'text' => $biographer->draft($request->user(), $person, $data['tone'] ?? 'warm'),
            'remaining' => $this->assistant->remaining($request->user()),
        ]]);
    }

    /** توضیح عکس یا خواندن دست‌خط/سند با هوش مصنوعی */
    public function readPhoto(Request $request, Media $media, PhotoReader $reader): JsonResponse
    {
        Gate::authorize('view', $media);
        $data = $request->validate(['task' => ['required', 'string', Rule::in(array_keys(PhotoReader::TASKS))]]);

        return response()->json(['data' => [
            'text' => $reader->read($request->user(), $media, $data['task']),
            'can_save' => Gate::allows('update', $media),
            'remaining' => $this->assistant->remaining($request->user()),
        ]]);
    }
}
