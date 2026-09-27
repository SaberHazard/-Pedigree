<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Person;
use App\Services\Access\PersonAccess;
use App\Services\Social\SocialAvatarService;
use App\Support\SocialNetworks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * شبکه‌های اجتماعی پروفایل:
 *  - پیش‌نمایش شناسه هنگام تایپ (لینک مستقیم، نام و عکس پروفایل عمومی)
 *  - دریافت/آپلود عکس پروفایل هر شبکه برای شخص
 */
class SocialController extends Controller
{
    public function __construct(private readonly SocialAvatarService $service) {}

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'network' => ['required', 'string', Rule::in(array_keys(SocialNetworks::all()))],
            'value' => ['required', 'string', 'max:300'],
        ]);
        $value = SocialNetworks::normalize($data['network'], $data['value']);
        if ($value === null) {
            throw new DomainException('شناسه یا لینک وارد شده برای این شبکه معتبر نیست.');
        }

        return response()->json(['data' => $this->service->preview($data['network'], $value)]);
    }

    /** دریافت عکس پروفایل از شبکه، یا آپلود دستی آن (مثلاً عکس پروفایل واتس‌اپ) */
    public function storeAvatar(Request $request, Person $person, PersonAccess $access): JsonResponse
    {
        Gate::authorize('uploadMedia', $person);
        $data = $request->validate([
            'network' => ['required', 'string', Rule::in(array_keys(SocialNetworks::all()))],
            'file' => ['nullable', 'file', 'max:'.(int) config('pedigree.media.image.max_upload_kb'),
                'mimetypes:'.implode(',', config('pedigree.media.image.mimes'))],
        ]);
        $value = $person->social[$data['network']] ?? null;
        // شماره واتس‌اپ/تلگرام مثل موبایل است؛ کسی که آن را نمی‌بیند برایش عکس نمی‌گذارد
        if ($value !== null && SocialNetworks::isPhone($data['network'], $value) && ! $access->canViewContact($request->user(), $person)) {
            throw new DomainException('اجازه این کار را ندارید.', 403);
        }

        $media = $this->service->store($person, $data['network'], $request->user(), $request->file('file'));

        return response()->json([
            'data' => new MediaResource($media->load(['uploader.person', 'votes.user.person', 'person'])),
            'message' => $media->isApproved() ? 'عکس ذخیره شد.' : 'عکس ذخیره شد و پس از تأیید نمایش داده می‌شود.',
        ], 201);
    }
}
