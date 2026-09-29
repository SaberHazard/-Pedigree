<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationAccount;
use App\Services\AuditLogger;
use App\Support\BankNumbers;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * پنل مدیریت ← «حمایت از سازنده» (فقط مدیر کل): حساب‌ها و لینک‌ها، و گزارش پرداخت‌ها
 */
class DonationAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $paid = Donation::query()->where('status', Donation::STATUS_PAID);

        return response()->json([
            'accounts' => DonationAccount::query()->orderBy('sort_order')->orderBy('id')->get(),
            'donations' => Donation::query()->with('user.person')->latest('id')->limit(100)->get()->map(fn (Donation $d) => [
                'id' => $d->id,
                'name' => $d->user?->person ? $d->user->person->fullName() : ($d->user?->displayName() ?? '—'),
                'amount' => $d->amount,
                'status' => $d->status,
                'gateway' => $d->gateway,
                'ref' => $d->ref_id,
                'card' => $d->card,
                'message' => $d->message,
                'anonymous' => $d->anonymous,
                'at' => ($d->paid_at ?? $d->created_at)?->toIso8601String(),
            ]),
            'totals' => [
                'count' => (clone $paid)->count(),
                'sum' => (int) (clone $paid)->sum('amount'),
                'month' => (int) (clone $paid)->where('paid_at', '>=', now()->subDays(30))->sum('amount'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $account = DonationAccount::create($this->validated($request) + ['sort_order' => (int) DonationAccount::query()->max('sort_order') + 1]);
        $this->audit->log('donation_account.created', null, ['id' => $account->id, 'kind' => $account->kind], $request->user());

        return response()->json(['data' => $account, 'message' => 'اضافه شد.'], 201);
    }

    public function update(Request $request, DonationAccount $account): JsonResponse
    {
        $account->fill($this->validated($request))->save();
        $this->audit->log('donation_account.updated', null, ['id' => $account->id], $request->user());

        return response()->json(['data' => $account, 'message' => 'ذخیره شد.']);
    }

    public function destroy(Request $request, DonationAccount $account): JsonResponse
    {
        $account->delete();
        $this->audit->log('donation_account.deleted', null, ['id' => $account->id], $request->user());

        return response()->json(['message' => 'حذف شد.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer']])['ids'];
        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                DonationAccount::query()->whereKey($id)->update(['sort_order' => $i]);
            }
        });

        return response()->json(['message' => 'ترتیب ذخیره شد.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(DonationAccount::KINDS))],
            'title' => ['required', 'string', 'max:80'],
            'holder' => ['nullable', 'string', 'max:80'],
            'value' => ['required', 'string', 'max:300'],
            'note' => ['nullable', 'string', 'max:200'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $clean = fn (?string $v) => $v === null ? null : trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $v) ?? '');
        $data['title'] = $clean($data['title']);
        $data['holder'] = $clean($data['holder'] ?? null) ?: null;
        $data['note'] = $clean($data['note'] ?? null) ?: null;
        $value = trim((string) $data['value']);
        $data['value'] = match ($data['kind']) {
            'card' => BankNumbers::validCard($value) ? BankNumbers::digits($value) : throw ValidationException::withMessages(['value' => 'شماره کارت ۱۶ رقمی معتبر نیست.']),
            'sheba' => BankNumbers::validSheba($value) ? BankNumbers::normalizeSheba($value) : throw ValidationException::withMessages(['value' => 'شماره شبا معتبر نیست (IR و ۲۴ رقم).']),
            'account' => preg_match('/^[0-9.\-\/ ]{5,34}$/', BankNumbers::digits($value) !== '' ? PersianText::toLatinDigits($value) : '') ? PersianText::toLatinDigits($value) : throw ValidationException::withMessages(['value' => 'شماره حساب معتبر نیست.']),
            'link' => preg_match('#^https://[^\s"\'<>]+$#i', $value) && parse_url($value, PHP_URL_HOST) ? $value : throw ValidationException::withMessages(['value' => 'یک لینک https معتبر وارد کنید.']),
        };

        return $data;
    }
}
