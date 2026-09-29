<?php

namespace App\Services\Messaging;

use App\Exceptions\DomainException;
use App\Models\Conversation;
use App\Models\DirectMessage;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\VoiceNote;
use App\Notifications\MessageReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * پیام‌رسان داخلی: فقط متن و ایموجی (بدون عکس، فیلم و استیکر تا فضای سرور اشغال نشود).
 *
 *  - هر عضو فعال به هر عضو فعال دیگری که حداقل یک بار وارد شده پیام می‌دهد
 *  - فقط دو طرف گفتگو پیام‌ها را می‌بینند (حتی مدیر سایت نه)؛ متن رمزنگاری‌شده ذخیره می‌شود
 *  - مسدود کردن، سقف ارسال (دقیقه‌ای و روزانه)، حذف پیام توسط فرستنده
 */
class MessagingService
{
    public const MAX_LENGTH = 2000;

    /** @throws DomainException */
    public function assertCanMessage(User $from, User $to): void
    {
        if ($from->id === $to->id) {
            throw new DomainException('به خودتان نمی‌توانید پیام بدهید.');
        }
        if (! $to->isActive() || $to->last_login_at === null) {
            throw new DomainException('این شخص هنوز عضو فعال سایت نیست؛ وقتی وارد سایت شود می‌توانید به او پیام بدهید.', 422, 'not_member');
        }
        if (UserBlock::between($from->id, $to->id)) {
            throw new DomainException('امکان پیام دادن بین شما و این شخص وجود ندارد.', 403, 'blocked');
        }
    }

    /**
     * ارسال پیام
     *
     * @throws DomainException
     */
    public function send(User $from, Conversation $conversation, string $body, ?VoiceNote $voice = null): DirectMessage
    {
        if (! $conversation->hasParticipant($from)) {
            throw new DomainException('گفتگو پیدا نشد.', 404);
        }
        $to = User::query()->find($conversation->otherId($from));
        if ($to === null) {
            throw new DomainException('گفتگو پیدا نشد.', 404);
        }
        $this->assertCanMessage($from, $to);
        // پیام صوتی بدون متن هم مجاز است
        $body = $voice !== null && trim($body) === '' ? '' : self::clean($body);

        // سقف ارسال: ۲۰ در دقیقه و ۵۰۰ در روز (جلوگیری از هرزنامه)
        foreach ([['msg-m:', 20, 60], ['msg-d:', (int) config('pedigree.messaging.daily_limit', 500), 86400]] as [$prefix, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($prefix.$from->id, $max)) {
                throw new DomainException('تعداد پیام‌های شما زیاد شده است؛ کمی بعد دوباره امتحان کنید.', 429, 'message_limit');
            }
        }
        RateLimiter::hit('msg-m:'.$from->id, 60);
        RateLimiter::hit('msg-d:'.$from->id, 86400);

        $message = DB::transaction(function () use ($from, $conversation, $body, $voice) {
            $message = DirectMessage::create(['conversation_id' => $conversation->id, 'sender_id' => $from->id, 'body' => $body, 'voice_id' => $voice?->id]);
            $conversation->forceFill(['last_message_at' => now()])->save();

            return $message;
        });

        // اعلان روی گوشی (بدون متن پیام، برای حریم خصوصی)؛ هر گفتگو حداکثر هر دو دقیقه یک بار
        if (config('services.fcm.enabled') && Cache::add("msg-push:{$conversation->id}:{$to->id}", 1, 120)) {
            $to->notify(new MessageReceived($from, $conversation));
        }

        return $message;
    }

    /** یکدست‌سازی متن: حذف نویسه‌های کنترلی و جهت‌دهی مخفی، حداکثر ۲ خط خالی پشت سر هم */
    public static function clean(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $body) ?? '';
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? '';
        $body = trim($body);
        if ($body === '') {
            throw new DomainException('پیام خالی است.');
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            throw new DomainException('پیام حداکثر ۲۰۰۰ نویسه باشد.');
        }

        return $body;
    }

    /** تعداد پیام‌های خوانده‌نشده کاربر */
    public function unreadCount(User $user): int
    {
        if (! config('pedigree.messaging.enabled', true)) {
            return 0;
        }

        return DirectMessage::query()
            ->whereIn('conversation_id', Conversation::query()->for($user)->select('id'))
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->whereNull('deleted_at')
            ->count();
    }

    /** علامت خوانده‌شدن پیام‌های طرف مقابل */
    public function markRead(User $user, Conversation $conversation): int
    {
        return DirectMessage::query()->where('conversation_id', $conversation->id)
            ->where('sender_id', '!=', $user->id)->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
