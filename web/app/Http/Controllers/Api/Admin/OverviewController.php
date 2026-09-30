<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\DirectMessage;
use App\Models\EditRequest;
use App\Models\GroupMessage;
use App\Models\GroupReport;
use App\Models\Marriage;
use App\Models\Media;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Models\User;
use App\Notifications\Broadcast;
use App\Services\Ai\AssistantService;
use App\Services\AuditLogger;
use App\Services\Sms\SmsManager;
use App\Support\Outbound;
use App\Support\ServerClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * نمای کلی پنل مدیریت: آمار سایت، سلامت سرور (ffmpeg، سقف آپلود، صف، زمان‌بند، فضای دیسک ...)
 * و ابزارهای کنترلی (خروج یک کاربر از همه دستگاه‌ها، اعلان همگانی).
 */
class OverviewController extends Controller
{
    public function index(): JsonResponse
    {
        $today = now('Asia/Tehran')->startOfDay()->utc();
        $day = now('Asia/Tehran')->format('Ymd');

        $stats = [
            'members' => User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')->count(),
            'active_30d' => User::query()->where('last_login_at', '>=', now()->subDays(30))->count(),
            'blocked' => User::query()->where('status', User::STATUS_BLOCKED)->count(),
            'pending_users' => User::query()->where('status', User::STATUS_PENDING)->count(),
            'pending_edits' => EditRequest::query()->where('status', EditRequest::STATUS_PENDING)->count(),
            'persons' => Person::query()->count(),
            'deceased' => Person::query()->where('is_deceased', true)->count(),
            'marriages' => Marriage::query()->count(),
            'images' => Media::query()->where('type', Media::TYPE_IMAGE)->count(),
            'videos' => Media::query()->where('type', Media::TYPE_VIDEO)->count(),
            'storage_bytes' => (int) Media::query()->sum('size'),
            'pending_media' => Media::query()->where('status', Media::STATUS_PENDING)->count(),
            'video_queue' => Media::query()->where('type', Media::TYPE_VIDEO)->whereIn('processing', ['queued', 'processing'])->count(),
            'messages_today' => DirectMessage::query()->where('created_at', '>=', $today)->count(),
            'group_today' => GroupMessage::query()->where('created_at', '>=', $today)->whereNotNull('user_id')->count(),
            'open_reports' => GroupReport::query()->whereNull('resolved_at')->distinct('message_id')->count('message_id'),
            'sms_today' => SmsMessage::query()->where('status', SmsMessage::STATUS_SENT)->whereDate('sent_on', now('Asia/Tehran')->toDateString())->count(),
            'ai_today' => RateLimiter::attempts('ai-g:'.$day),
            'ai_images_today' => RateLimiter::attempts('ai-img-g:'.$day),
        ];

        return response()->json(['data' => ['stats' => $stats, 'checks' => $this->checks()]]);
    }

    /** خروج یک کاربر از همه دستگاه‌ها (اپ و مرورگر) */
    public function logoutEverywhere(Request $request, User $user, AuditLogger $audit): JsonResponse
    {
        if ($user->isAdmin() && ! $request->user()->isSuperAdmin()) {
            throw new DomainException('فقط مدیر کل می‌تواند مدیران را از حساب خارج کند.', 403);
        }
        $user->tokens()->delete();
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $audit->log('admin.user_logged_out', $user->person, ['user' => $user->id], $request->user());

        return response()->json(['message' => 'از همه دستگاه‌ها خارج شد.']);
    }

    /** اعلان همگانی به همه اعضای فعال (در سایت و روی گوشی) — فقط مدیر کل */
    public function broadcast(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'body' => ['required', 'string', 'max:500'],
        ]);
        $clean = fn (string $v) => trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $v) ?? '');
        $users = User::query()->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')->get();
        Notification::send($users, new Broadcast($clean($data['title']), $clean($data['body'])));
        $audit->log('admin.broadcast', null, ['title' => $data['title'], 'count' => $users->count()], $request->user());

        return response()->json(['message' => 'اعلان برای '.$users->count().' عضو فرستاده شد.']);
    }

    /** @return array<int, array{key:string, ok:?bool, label:string, value:?string, fix:?string}> */
    private function checks(): array
    {
        $bin = function (string $path, array $args = ['-version']): bool {
            try {
                $p = new Process([$path, ...$args]);
                $p->setTimeout(10);
                $p->run();

                return $p->isSuccessful() || str_contains($p->getOutput().$p->getErrorOutput(), 'heif');
            } catch (Throwable) {
                return false;
            }
        };
        $bytes = function (string $v): int {
            $v = trim($v);
            $n = (int) $v;

            return match (strtolower(substr($v, -1))) {
                'g' => $n * 1024 ** 3,
                'm' => $n * 1024 ** 2,
                'k' => $n * 1024,
                default => $n,
            };
        };
        $videoMb = (int) config('pedigree.media.video.max_upload_mb');
        $uploadLimit = min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')) ?: PHP_INT_MAX);
        $free = @disk_free_space(storage_path()) ?: null;
        $heartbeat = Cache::get('scheduler:heartbeat');
        $pending = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->count();
        $sms = app(SmsManager::class);

        return [
            ['key' => 'debug', 'ok' => ! config('app.debug'), 'label' => 'حالت اشکال‌زدایی (APP_DEBUG) خاموش است', 'value' => null, 'fix' => 'در .env بنویسید APP_DEBUG=false'],
            ['key' => 'https', 'ok' => str_starts_with((string) config('app.url'), 'https://'), 'label' => 'سایت روی HTTPS است', 'value' => (string) config('app.url'), 'fix' => 'گواهی SSL بگیرید و APP_URL را https کنید'],
            ['key' => 'cookie', 'ok' => (bool) config('session.secure'), 'label' => 'کوکی امن (فقط HTTPS)', 'value' => null, 'fix' => 'SESSION_SECURE_COOKIE=true'],
            ['key' => 'blind', 'ok' => ! empty(config('pedigree.blind_index_key')), 'label' => 'کلید ایندکس رمزنگاری تنظیم شده', 'value' => null, 'fix' => 'PEDIGREE_BLIND_INDEX_KEY را در .env بگذارید'],
            ['key' => 'scheduler', 'ok' => $heartbeat && now()->diffInMinutes($heartbeat) < 15, 'label' => 'زمان‌بند (cron) اجرا می‌شود', 'value' => $heartbeat, 'fix' => '* * * * * php artisan schedule:run'],
            ['key' => 'queue', 'ok' => $failed === 0, 'label' => 'صف کارها بدون خطا', 'value' => "در صف: {$pending} • ناموفق: {$failed}", 'fix' => 'php artisan queue:failed'],
            ['key' => 'ffmpeg', 'ok' => $bin((string) config('pedigree.media.video.ffmpeg', 'ffmpeg')), 'label' => 'ffmpeg (فشرده‌سازی فیلم و تبدیل TIFF و ...)', 'value' => null, 'fix' => 'apt install ffmpeg'],
            ['key' => 'ffprobe', 'ok' => $bin((string) config('pedigree.media.video.ffprobe', 'ffprobe')), 'label' => 'ffprobe (بررسی فیلم‌ها)', 'value' => null, 'fix' => 'apt install ffmpeg'],
            ['key' => 'heif', 'ok' => $bin((string) config('pedigree.media.image.heif_convert', 'heif-convert'), ['--version']), 'label' => 'heif-convert (عکس‌های HEIC آیفون)', 'value' => null, 'fix' => 'apt install libheif-examples'],
            ['key' => 'upload', 'ok' => $uploadLimit >= $videoMb * 1024 * 1024, 'label' => 'سقف آپلود PHP برای فیلم کافی است', 'value' => 'upload_max_filesize='.ini_get('upload_max_filesize').' • post_max_size='.ini_get('post_max_size').' • سقف فیلم='.$videoMb.'M', 'fix' => 'در php.ini هر دو را دست‌کم به اندازه سقف فیلم کنید'],
            ['key' => 'disk', 'ok' => $free === null ? null : $free > 2 * 1024 ** 3, 'label' => 'فضای خالی دیسک', 'value' => $free === null ? null : round($free / 1024 ** 3, 1).' GB', 'fix' => 'فضای سرور را بیشتر کنید یا فضای ابری (S3) را فعال کنید'],
            ['key' => 'sms', 'ok' => $sms->driverName() !== 'log', 'label' => 'پنل پیامکی واقعی تنظیم شده', 'value' => $sms->driverName(), 'fix' => 'در «تنظیمات و اتصال‌ها» پنل پیامکی را انتخاب کنید'],
            ['key' => 'ai', 'ok' => app(AssistantService::class)->configured() ? true : null, 'label' => 'دستیار هوش مصنوعی', 'value' => app(AssistantService::class)->label(), 'fix' => 'کلید یکی از سرویس‌ها را وارد کنید (اختیاری)'],
            ['key' => 'push', 'ok' => config('services.fcm.enabled') ? true : null, 'label' => 'اعلان روی گوشی (Firebase)', 'value' => null, 'fix' => 'اختیاری؛ برای اپ اندروید و iOS'],
            ['key' => 'clock', 'ok' => ($drift = ServerClock::drift()) === null ? null : abs($drift) <= 20, 'label' => 'ساعت سرور دقیق است (هشدارها و پیامک ساعت ۰۰:۰۰)', 'value' => $drift === null ? 'قابل سنجش نبود' : ($drift === 0 ? 'بدون اختلاف' : abs($drift).' ثانیه '.($drift > 0 ? 'جلو' : 'عقب')).' • وقت تهران '.now('Asia/Tehran')->format('H:i:s'), 'fix' => 'timedatectl set-ntp true (همگام‌سازی خودکار ساعت)'],
            ['key' => 'proxy', 'ok' => Outbound::location() === 'iran' ? (Outbound::foreign() !== null ? true : null) : true, 'label' => Outbound::location() === 'iran' ? 'پراکسی خروجی برای سرویس‌های خارجی (سرور ایران)' : 'سرور خارج از ایران', 'value' => Outbound::location() === 'iran' ? (Outbound::foreign() !== null ? 'تنظیم شده' : 'تنظیم نشده') : (Outbound::iran() !== null ? 'پراکسی داخل ایران تنظیم شده' : null), 'fix' => 'تنظیمات ← «محل سرور و پراکسی» (برای هوش مصنوعی و نوتیفیکیشن گوشی)'],
            ['key' => 'php', 'ok' => version_compare(PHP_VERSION, '8.3.0', '>='), 'label' => 'نسخه PHP', 'value' => PHP_VERSION.' • '.config('database.default'), 'fix' => 'PHP 8.3 یا بالاتر'],
        ];
    }
}
