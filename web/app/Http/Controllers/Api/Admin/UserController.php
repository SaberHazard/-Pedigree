<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Tree\NodePresenter;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * مدیریت کاربران (نقش و مسدودسازی)
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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

        $page = $query->latest('last_login_at')->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => [
                'id' => $u->id,
                'role' => $u->role,
                'status' => $u->status,
                'has_password' => $u->password !== null,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
                'last_login_ip' => $u->last_login_ip,
                'created_at' => $u->created_at?->toIso8601String(),
                'person' => $u->person ? NodePresenter::person($u->person) : null,
            ]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function update(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        $actor = $request->user();
        $data = $request->validate([
            'role' => ['sometimes', Rule::in([User::ROLE_MEMBER, User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])],
            'status' => ['sometimes', Rule::in([User::STATUS_ACTIVE, User::STATUS_BLOCKED])],
        ]);

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
}
