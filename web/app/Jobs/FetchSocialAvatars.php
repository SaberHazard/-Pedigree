<?php

namespace App\Jobs;

use App\Models\Person;
use App\Models\User;
use App\Services\Social\SocialAvatarService;
use App\Services\Social\SocialProfileFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * دریافت خودکار عکس پروفایل شبکه‌هایی که شناسه‌شان تازه ثبت یا عوض شده.
 * پس از ارسال پاسخ به کاربر اجرا می‌شود (کاربر منتظر نمی‌ماند) و خطاها فقط لاگ می‌شوند.
 */
class FetchSocialAvatars implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param string[] $networks */
    public function __construct(
        public string $personId,
        public array $networks,
        public int $actorId,
    ) {}

    public function handle(SocialAvatarService $service, SocialProfileFetcher $fetcher): void
    {
        $person = Person::find($this->personId);
        $actor = User::find($this->actorId);
        if (! $person || ! $actor || ! $actor->isActive()) {
            return;
        }
        foreach (array_slice($this->networks, 0, 5) as $network) {
            $value = $person->social[$network] ?? null;
            if (! $fetcher->canFetch($network, $value)) {
                continue;
            }
            try {
                $service->store($person->fresh(), $network, $actor);
            } catch (Throwable $e) {
                Log::info('social avatar fetch skipped', ['network' => $network, 'reason' => $e->getMessage()]);
            }
        }
    }
}
