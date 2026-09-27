<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\PersonComment;
use App\Models\PersonRating;
use App\Models\User;
use App\Notifications\CommentAdded;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * نظرسنجی کل خاندان درباره هر شخص:
 *  - نظر متنی (با نام نویسنده)
 *  - امتیاز ۱ تا ۵ به ویژگی‌ها (شوخ‌طبعی، کاریزما، مهربانی ...)
 *
 * همه اعضای فعال می‌توانند نظر بدهند و امتیاز بدهند (جز امتیاز دادن به خودشان).
 * خود شخص، بستگان درجه یکش و مدیر می‌توانند نظر نامناسب را مخفی کنند.
 */
class OpinionController extends Controller
{
    public function __construct(
        private readonly PersonAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------ نظرها

    public function comments(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);
        $this->ensureEnabled('comments');
        $user = $request->user();
        $moderator = $this->canModerate($user, $person);

        $page = PersonComment::with('user.person.avatar')
            ->where('person_id', $person->id)
            ->when(! $moderator, fn ($q) => $q->where(fn ($q) => $q->whereNull('hidden_at')->orWhere('user_id', $user?->id)))
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (PersonComment $c) => $this->presentComment($c, $user, $moderator)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'can' => ['write' => $user !== null && $user->isActive(), 'moderate' => $moderator],
        ]);
    }

    public function storeComment(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);
        $this->ensureEnabled('comments');
        $user = $request->user();
        $body = $this->validateBody($request);

        $comment = new PersonComment(['body' => $body]);
        $comment->person_id = $person->id;
        $comment->user_id = $user->id;
        $comment->save();
        // متن نظر در تاریخچه عمومی ذخیره نمی‌شود (اگر بعداً مخفی شود نباید از راه تاریخچه دیده شود)
        $this->audit->log('comment.created', $person, ['comment' => $comment->id], $user);

        $owner = $person->user;
        if ($owner && $owner->id !== $user->id && $owner->isActive() && $owner->last_login_at) {
            $owner->notify(new CommentAdded($person, $comment, $user));
        }

        return response()->json(['data' => $this->presentComment($comment->load('user.person.avatar'), $user, $this->canModerate($user, $person))], 201);
    }

    public function updateComment(Request $request, PersonComment $comment): JsonResponse
    {
        $user = $request->user();
        if ($comment->user_id !== $user->id) {
            throw new DomainException('فقط نویسنده نظر می‌تواند آن را ویرایش کند.', 403);
        }
        $body = $this->validateBody($request);
        if ($body !== $comment->body) {
            $old = $comment->body;
            $comment->body = $body;
            $comment->edited_at = now();
            $comment->save();
            $this->audit->log('comment.updated', $comment->person, ['comment' => $comment->id, 'length' => [mb_strlen($old), mb_strlen($body)]], $user);
        }

        return response()->json(['data' => $this->presentComment($comment->load('user.person.avatar'), $user, $this->canModerate($user, $comment->person))]);
    }

    public function destroyComment(Request $request, PersonComment $comment): JsonResponse
    {
        $user = $request->user();
        $person = $comment->person;
        if ($comment->user_id !== $user->id && ! $user->isAdmin()) {
            throw new DomainException('فقط نویسنده نظر یا مدیر می‌تواند آن را حذف کند. (می‌توانید آن را مخفی کنید)', 403);
        }
        $comment->delete();
        $this->audit->log('comment.deleted', $person, ['comment' => $comment->id], $user);

        return response()->json(['message' => 'نظر حذف شد.']);
    }

    /** مخفی/آشکار کردن نظر توسط خود شخص، بستگان درجه یک یا مدیر */
    public function hideComment(Request $request, PersonComment $comment): JsonResponse
    {
        $user = $request->user();
        if (! $this->canModerate($user, $comment->person)) {
            throw new DomainException('اجازه مدیریت نظرهای این پروفایل را ندارید.', 403);
        }
        $hide = $request->boolean('hidden', true);
        $comment->hidden_at = $hide ? now() : null;
        $comment->hidden_by = $hide ? $user->id : null;
        $comment->save();
        $this->audit->log($hide ? 'comment.hidden' : 'comment.unhidden', $comment->person, ['comment' => $comment->id], $user);

        return response()->json(['data' => $this->presentComment($comment->load('user.person.avatar'), $user, true)]);
    }

    // ------------------------------------------------------------------ امتیازها

    public function ratings(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);
        $this->ensureEnabled('ratings');
        $user = $request->user();
        $traits = config('pedigree.ratings.traits', []);

        $rows = PersonRating::with('user.person')->where('person_id', $person->id)->get();
        $mine = $user ? $rows->where('user_id', $user->id)->pluck('score', 'trait') : collect();

        $summary = [];
        foreach ($traits as $key => $label) {
            $scores = $rows->where('trait', $key)->pluck('score');
            $summary[] = [
                'key' => $key,
                'label' => $label,
                'average' => $scores->count() ? round($scores->avg(), 2) : null,
                'count' => $scores->count(),
                'distribution' => array_map(fn ($s) => $scores->filter(fn ($v) => $v === $s)->count(), [1, 2, 3, 4, 5]),
                'mine' => $mine->get($key),
            ];
        }

        $raters = null;
        if (config('pedigree.ratings.show_raters', true)) {
            $raters = $rows->groupBy('user_id')->map(fn ($items) => [
                'user' => ['id' => $items->first()->user_id, 'name' => $items->first()->user?->displayName() ?? 'کاربر حذف‌شده', 'person_id' => $items->first()->user?->person_id],
                'scores' => $items->pluck('score', 'trait'),
                'updated_at' => $items->max('updated_at')?->toIso8601String(),
            ])->values();
        }

        return response()->json([
            'traits' => $summary,
            'raters_count' => $rows->pluck('user_id')->unique()->count(),
            'raters' => $raters,
            'can_rate' => $this->canRate($user, $person),
        ]);
    }

    /** ثبت/تغییر امتیازهای خود: {scores: {humor: 5, charisma: 4, kindness: null}} ؛ null = حذف امتیاز */
    public function rate(Request $request, Person $person): JsonResponse
    {
        Gate::authorize('view', $person);
        $this->ensureEnabled('ratings');
        $user = $request->user();
        if (! $this->canRate($user, $person)) {
            throw new DomainException('به خودتان نمی‌توانید امتیاز بدهید.', 403);
        }
        $traits = array_keys(config('pedigree.ratings.traits', []));
        $data = $request->validate([
            'scores' => ['required', 'array', 'array:'.implode(',', $traits)],
            'scores.*' => ['nullable', 'integer', 'between:1,5'],
        ]);

        DB::transaction(function () use ($data, $person, $user) {
            foreach ($data['scores'] as $trait => $score) {
                if ($score === null) {
                    PersonRating::where(['person_id' => $person->id, 'user_id' => $user->id, 'trait' => $trait])->delete();
                } else {
                    PersonRating::updateOrCreate(
                        ['person_id' => $person->id, 'user_id' => $user->id, 'trait' => $trait],
                        ['score' => (int) $score],
                    );
                }
            }
        });
        // اگر نام امتیازدهندگان خصوصی است، امتیازها هم در تاریخچه ثبت نشود
        $this->audit->log('rating.updated', $person, config('pedigree.ratings.show_raters', true) ? ['scores' => $data['scores']] : [], $user);

        return $this->ratings($request, $person);
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function presentComment(PersonComment $c, ?User $viewer, bool $moderator): array
    {
        $author = $c->user;

        return [
            'id' => $c->id,
            'body' => $c->body,
            'author' => $author ? [
                'id' => $author->id,
                'name' => $author->displayName(),
                'person_id' => $author->person_id,
                'avatar' => $author->person?->avatar?->isApproved() ? $author->person->avatar->url('thumb') : null,
                'gender' => $author->person?->gender,
            ] : null,
            'hidden' => $c->isHidden(),
            'edited_at' => $c->edited_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'can' => [
                'edit' => $viewer !== null && $viewer->id === $c->user_id,
                'delete' => $viewer !== null && ($viewer->id === $c->user_id || $viewer->isAdmin()),
                'hide' => $moderator,
            ],
        ];
    }

    private function validateBody(Request $request): string
    {
        $max = (int) config('pedigree.comments.max_length', 3000);
        $data = $request->validate(['body' => ['required', 'string', 'max:'.($max * 2)]], [], ['body' => 'متن نظر']);
        $body = PersianText::normalizeMultiline($data['body']);
        if ($body === '') {
            throw new DomainException('متن نظر خالی است.');
        }
        if (mb_strlen($body) > $max) {
            throw new DomainException('متن نظر طولانی‌تر از حد مجاز است.');
        }

        return $body;
    }

    private function canModerate(?User $user, ?Person $person): bool
    {
        return $user !== null && $person !== null && ($user->isAdmin() || $user->person_id === $person->id || $this->access->canEdit($user, $person));
    }

    private function canRate(?User $user, Person $person): bool
    {
        return $user !== null && $user->isActive() && $user->person_id !== $person->id;
    }

    private function ensureEnabled(string $feature): void
    {
        if (! config("pedigree.{$feature}.enabled", true)) {
            throw new DomainException('این بخش توسط مدیر غیرفعال شده است.', 404);
        }
    }
}
