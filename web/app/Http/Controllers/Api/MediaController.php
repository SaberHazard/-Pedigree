<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Models\MediaVote;
use App\Models\Person;
use App\Rules\PartialDateRule;
use App\Services\AuditLogger;
use App\Services\Media\ApprovalService;
use App\Services\Media\MediaService;
use App\Support\PartialDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * عکس‌ها و ویدیوها: آپلود، گالری، رأی‌گیری، عکس پروفایل
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
        private readonly ApprovalService $approvals,
        private readonly AuditLogger $audit,
    ) {}

    /** گالری یک شخص: تأییدشده‌ها برای همه + در انتظارها برای افراد مجاز */
    public function index(Request $request, Person $person): AnonymousResourceCollection
    {
        Gate::authorize('view', $person);
        $user = $request->user();

        $items = Media::query()
            ->with(['uploader.person', 'votes.user.person', 'person'])
            ->where('person_id', $person->id)
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->input('type')))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->input('category')))
            ->where(function (Builder $q) use ($user) {
                $q->where('status', Media::STATUS_APPROVED);
                if ($user) {
                    $q->orWhere(function (Builder $q) use ($user) {
                        $q->where('status', Media::STATUS_PENDING);
                        if (! $user->isAdmin()) {
                            $q->where(fn (Builder $q) => $q->where('uploaded_by', $user->id)
                                ->orWhereHas('votes', fn (Builder $v) => $v->where('user_id', $user->id)));
                        }
                    });
                }
            })
            ->latest()
            ->get();

        return MediaResource::collection($items);
    }

    /** آپلود عکس یا ویدیو */
    public function store(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('uploadMedia', $person);
        $data = $this->validateUpload($request);

        $media = $this->media->store($person, $request->file('file'), $request->user(), $data, (bool) ($data['as_avatar'] ?? false));

        return $this->created($media);
    }

    /** آپلود عکس پروفایل (تصویر برش‌خورده دایره‌ای از سمت کاربر) */
    public function uploadAvatar(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('uploadMedia', $person);
        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) config('pedigree.media.image.max_upload_kb'),
                'mimetypes:'.implode(',', config('pedigree.media.image.mimes'))],
        ]);

        $media = $this->media->store($person, $request->file('file'), $request->user(), ['caption' => 'عکس پروفایل'], true);

        return $this->created($media);
    }

    /** انتخاب یکی از عکس‌های تأییدشده به عنوان عکس پروفایل */
    public function setAvatar(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('update', $person);
        $data = $request->validate(['media_id' => ['nullable', 'uuid']]);

        if (! empty($data['media_id'])) {
            $media = Media::where('person_id', $person->id)->findOrFail($data['media_id']);
            if (! $media->isApproved() || ! $media->isImage()) {
                throw new DomainException('فقط عکس‌های تأییدشده می‌توانند عکس پروفایل باشند.');
            }
        }

        $person->avatar_media_id = $data['media_id'] ?? null;
        $person->save();
        $this->audit->log('person.avatar_changed', $person, ['media' => $data['media_id'] ?? null], $request->user());

        return response()->json(['message' => 'عکس پروفایل به‌روز شد.']);
    }

    public function show(Media $media): MediaResource
    {
        Gate::authorize('view', $media);

        return new MediaResource($media->load(['uploader.person', 'votes.user.person', 'person']));
    }

    public function update(Request $request, Media $media): MediaResource
    {
        Gate::authorize('update', $media);
        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'taken_at' => ['nullable', new PartialDateRule],
        ]);
        if (array_key_exists('taken_at', $data)) {
            $data['taken_at'] = PartialDate::normalize($data['taken_at']);
        }
        $media->fill($data)->save();

        return new MediaResource($media->load(['uploader.person', 'votes', 'person']));
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        Gate::authorize('delete', $media);
        $this->media->delete($media, $request->user());

        return response()->json(['message' => 'فایل حذف شد.']);
    }

    /** رأی دادن (موافق/مخالف) */
    public function vote(Request $request, Media $media): MediaResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([MediaVote::APPROVE, MediaVote::REJECT])],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $media = $this->approvals->vote($media, $request->user(), $data['decision'], $data['comment'] ?? null);

        return new MediaResource($media->load(['uploader.person', 'votes.user.person', 'person']));
    }

    /** تصمیم مستقیم مدیر */
    public function decide(Request $request, Media $media): MediaResource
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in([MediaVote::APPROVE, MediaVote::REJECT])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $media = $this->approvals->decide($media, $request->user(), $data['decision'], $data['note'] ?? null);

        return new MediaResource($media->load(['uploader.person', 'votes.user.person', 'person']));
    }

    /** صف تأیید من: مواردی که باید رأی بدهم + وضعیت آپلودهای خودم */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        $with = ['uploader.person', 'votes.user.person', 'person'];

        $toVote = Media::with($with)
            ->where('status', Media::STATUS_PENDING)
            ->whereHas('votes', fn (Builder $q) => $q->where('user_id', $user->id)->whereNull('decision'))
            ->oldest()->get();

        $mine = Media::with($with)
            ->where('uploaded_by', $user->id)
            ->latest()->limit(30)->get();

        $adminQueue = $user->isAdmin()
            ? Media::with($with)->where('status', Media::STATUS_PENDING)->oldest()->limit(100)->get()
            : collect();

        return response()->json([
            'to_vote' => MediaResource::collection($toVote),
            'mine' => MediaResource::collection($mine),
            'admin_queue' => MediaResource::collection($adminQueue),
        ]);
    }

    private function validateUpload(Request $request): array
    {
        $imageMimes = config('pedigree.media.image.mimes');
        $videoMimes = config('pedigree.media.video.enabled') ? config('pedigree.media.video.mimes') : [];
        $maxKb = max((int) config('pedigree.media.image.max_upload_kb'), $videoMimes ? (int) config('pedigree.media.video.max_upload_kb') : 0);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', array_merge($imageMimes, $videoMimes))],
            'caption' => ['nullable', 'string', 'max:300'],
            'description' => ['nullable', 'string', 'max:5000'],
            'taken_at' => ['nullable', new PartialDateRule],
            'as_avatar' => ['nullable', 'boolean'],
            // gallery = گالری ، story = استوری (عکس/فیلمی که همان لحظه با دوربین گرفته شده)
            'category' => ['nullable', Rule::in([Media::CATEGORY_GALLERY, Media::CATEGORY_STORY])],
        ], [], ['file' => 'فایل']);

        // محدودیت حجم جداگانه برای عکس
        $file = $request->file('file');
        if (str_starts_with((string) $file->getMimeType(), 'image/') && $file->getSize() > (int) config('pedigree.media.image.max_upload_kb') * 1024) {
            throw new DomainException('حجم عکس بیش از حد مجاز است.');
        }

        $data['taken_at'] = PartialDate::normalize($data['taken_at'] ?? null);

        return $data;
    }

    private function created(Media $media): JsonResponse
    {
        $message = match (true) {
            $media->isApproved() => 'فایل با موفقیت آپلود و منتشر شد.',
            $media->approval_mode === 'owner' => 'فایل آپلود شد و پس از تأیید صاحب پروفایل نمایش داده می‌شود.',
            $media->approval_mode === 'admin' => 'فایل آپلود شد و پس از بررسی مدیر نمایش داده می‌شود.',
            default => 'فایل آپلود شد و پس از رأی موافق اکثریت بستگان نمایش داده می‌شود.',
        };

        return (new MediaResource($media->load(['uploader.person', 'votes', 'person'])))
            ->additional(['message' => $message])
            ->response()->setStatusCode(201);
    }
}
