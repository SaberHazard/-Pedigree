<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Donation;
use App\Models\DonationAccount;
use App\Services\Donate\DonationService;
use App\Services\Tree\NodePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * صفحه «حمایت از سازنده»: درگاه پرداخت، شماره کارت/شبا/حساب و لینک‌های پرداخت
 */
class DonateController extends Controller
{
    public function __construct(private readonly DonationService $donations) {}

    public function index(Request $request): JsonResponse
    {
        if (! config('pedigree.donate.enabled', true)) {
            return response()->json(['data' => ['enabled' => false]]);
        }
        $gateway = $this->donations->gateway();
        $thanks = Donation::query()->with('user.person')->where('status', Donation::STATUS_PAID)->where('anonymous', false)
            ->latest('paid_at')->limit(20)->get();

        return response()->json(['data' => [
            'enabled' => true,
            'title' => (string) config('pedigree.donate.title', 'حمایت از سازنده'),
            'message' => (string) config('pedigree.donate.message', ''),
            'gateway' => $gateway ? ['key' => $gateway, 'label' => DonationService::GATEWAYS[$gateway]] : null,
            'limits' => $this->donations->limits(),
            'accounts' => DonationAccount::query()->where('active', true)->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (DonationAccount $a) => $a->only(['id', 'kind', 'title', 'holder', 'value', 'note'])),
            'thanks' => $thanks->map(fn (Donation $d) => [
                'name' => $d->user?->person ? $d->user->person->fullName() : ($d->user?->displayName() ?? 'یک عضو'),
                'person' => $d->user?->person ? NodePresenter::person($d->user->person) : null,
                'message' => $d->message,
                'at' => $d->paid_at?->toIso8601String(),
            ]),
            'mine' => Donation::query()->where('user_id', $request->user()->id)->where('status', Donation::STATUS_PAID)->latest('paid_at')->limit(10)->get()
                ->map(fn (Donation $d) => ['amount' => $d->amount, 'ref' => $d->ref_id, 'at' => $d->paid_at?->toIso8601String()]),
        ]]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'message' => ['nullable', 'string', 'max:200'],
            'anonymous' => ['sometimes', 'boolean'],
        ]);
        $result = $this->donations->start($request->user(), (int) $data['amount'], $data['message'] ?? null, (bool) ($data['anonymous'] ?? false), $request->ip());

        return response()->json(['data' => ['url' => $result['url'], 'id' => $result['donation']->id]], 201);
    }

    /** نتیجه یک پرداخت خودم (پس از بازگشت از درگاه) */
    public function show(Request $request, int $donation): JsonResponse
    {
        $d = Donation::query()->where('user_id', $request->user()->id)->findOrFail($donation);

        return response()->json(['data' => ['id' => $d->id, 'status' => $d->status, 'amount' => $d->amount, 'ref' => $d->ref_id]]);
    }
}
