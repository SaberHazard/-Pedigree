<?php

namespace App\Http\Controllers;

use App\Exceptions\DomainException;
use App\Services\Donate\DonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * بازگشت از درگاه پرداخت (GET یا POST، بدون نیاز به ورود): تأیید سرور-به-سرور و بازگشت به صفحه حمایت
 */
class DonateCallbackController extends Controller
{
    public function __invoke(Request $request, string $token, DonationService $donations): RedirectResponse
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);
        $query = array_filter($request->only(['Authority', 'Status', 'trackId', 'success', 'status', 'token']), 'is_string');
        try {
            $donation = $donations->complete($token, $query);
        } catch (DomainException) {
            return redirect('/#/donate?result=failed');
        }

        return redirect('/#/donate?result='.($donation->status === 'paid' ? 'paid' : 'failed').'&id='.$donation->id);
    }
}
