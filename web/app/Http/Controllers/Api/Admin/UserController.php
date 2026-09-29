<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Notifications\MemberApproved;
use App\Services\AuditLogger;
use App\Services\Tree\NodePresenter;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * مدیریت کاربران (نقش و مسدودسازی)
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'string', Rule::in([User::ROLE_MEMBER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])],
            'status' => ['nullable', 'string', Rule::in([User::STATUS_ACTIVE, User::STATUS_BLOCKED, User::STATUS_PENDING])],
        ]);
        $query = User::query()->with('person.avatar');

        if ($q = trim((string) $request->input('q'))) {
            $words = array_filter(explode(' ', PersianText::searchable($q)));
            $query->whereHas('person', function ($p) use ($words) {
                foreach ($words as $word) {
                    $p->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            });
        }
        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $page = $request->input('status') === User::STATUS_PENDING
            ? $query->oldest('id')->paginate(30)
            : $query->latest('last_login_at')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'role' => $u->role,
                'status' => $u->status,
                'has_password' => $u->password !== null,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'last_login_ip' => $u->last_login_ip,
                'created_at' => $u->created_at?->toIso8601String(),
                'join_note' => $u->join_note,
                'person' => $u->person ? NodePresenter::person($u->person) : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'pending' => User::query()->where('status', User::STATUS_PENDING)->count(),
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'role' => ['sometimes', Rule::in([User::ROLE_MEMBER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_BLOCKED])],
        ]);
        if ($user->isPending() && isset($data['status'])) {
            throw new DomainException('برای عضو در انتظار از دکمه‌های «تأیید عضویت» یا «رد» استفاده کنید.');
        }

        if ($user->id === $actor->id) {
            throw new DomainException('نقش یا وضعیت حساب خودتان را نمی‌توانید تغییر دهید.');
        }
        // فقط مدیر کل می‌تواند مدیر بسازد یا نقش مدیران را تغییر دهد
        if ((isset($data['role']) || $user->isAdmin()) && ! $actor->isSuperAdmin()) {
            throw new DomainException('فقط مدیر کل می‌تواند نقش مدیران را تغییر دهد.', 403);
        }

        $user->fill($data)->save();
        if (($data['status'] ?? null) === User::STATUS_BLOCKED) {
            $user->tokens()->delete();
        }
        $audit->log('admin.user_updated', $user->person, ['user' => $user->id] + $data, $actor);

        return response()->json(['message' => 'تغییرات ذخیره شد.']);
    }

    /** تأیید عضویت کسی که خودش ثبت‌نام کرده است */
    public function approve(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        if (! $user->isPending()) {
            throw new DomainException('این حساب در انتظار تأیید نیست.');
        }
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'approved_at' => now(), 'approved_by' => $request->user()->id])->save();
        $user->notify(new MemberApproved);
        $audit->log('admin.member_approved', $user->person, ['user' => $user->id], $request->user());

        return response()->json(['message' => 'عضویت تأیید شد.']);
    }

    /** رد درخواست عضویت: حساب مسدود و پروفایلی که خودش ساخته بود (اگر به کسی وصل نیست) حذف می‌شود */
    public function reject(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        if (! $user->isPending()) {
            throw new DomainException('این حساب در انتظار تأیید نیست.');
        }
        DB::transaction(function () use ($user) {
            $user->forceFill(['status' => User::STATUS_BLOCKED])->save();
            $user->tokens()->delete();
            $person = $user->person;
            $linked = $person && ($person->father_id || $person->mother_id
                || Person::query()->where('father_id', $person->id)->orWhere('mother_id', $person->id)->exists()
                || Marriage::query()->where('husband_id', $person->id)->orWhere('wife_id', $person->id)->exists());
            if ($person && ! $linked) {
                $person->delete();
            }
        });
        $audit->log('admin.member_rejected', null, ['user' => $user->id], $request->user());

        return response()->json(['message' => 'درخواست عضویت رد شد.']);
    }
}
