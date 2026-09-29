<?php

namespace App\Http\Controllers;

use App\Models\VoiceNote;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * پخش پیام صوتی با لینک امضاشده (پشتیبانی Range برای جلو/عقب بردن)
 */
class VoiceFileController extends Controller
{
    public function show(VoiceNote $voice): Response
    {
        $disk = Storage::disk($voice->disk);
        abort_unless($disk->exists($voice->path), 404);
        $headers = [
            'Content-Type' => $voice->mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; media-src 'self'",
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ((config('filesystems.disks.'.$voice->disk)['driver'] ?? null) === 'local') {
            return response()->file($disk->path($voice->path), $headers);
        }

        return redirect()->away($disk->temporaryUrl($voice->path, now()->addMinutes(30)));
    }
}
