<?php

namespace App\Support;

use App\Models\VoiceNote;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * لینک امضاشده و زمان‌دار پیام صوتی (فقط برای کسی ساخته می‌شود که اجازه شنیدن دارد)
 */
final class VoiceUrl
{
    public static function for(VoiceNote $voice): string
    {
        $window = 6 * 3600;
        $expires = (int) (ceil((time() + 12 * 3600) / $window) * $window);

        return URL::temporarySignedRoute('voice.file', Carbon::createFromTimestamp($expires), ['voice' => $voice->id], absolute: false);
    }
}
