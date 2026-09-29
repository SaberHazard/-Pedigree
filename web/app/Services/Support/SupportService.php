<?php

namespace App\Services\Support;

use App\Exceptions\DomainException;
use App\Models\SupportMessage;
use App\Models\SupportThread;
use App\Models\User;
use App\Models\VoiceNote;
use App\Notifications\SupportReplied;
use App\Notifications\SupportRequested;
use App\Services\Media\VoiceService;
use App\Services\Messaging\MessagingService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * گفتگو با پشتیبانی: تنها راه ارتباط اعضا با مدیران سایت (متن، ایموجی و پیام صوتی)
 */
class SupportService
{
    public function __construct(private readonly VoiceService $voices) {}

    public function threadFor(User $user): SupportThread
    {
        return SupportThread::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /** @throws DomainException */
    public function post(User $sender, SupportThread $thread, string $body, ?UploadedFile $voiceFile = null, mixed $waveform = null): SupportMessage
    {
        $fromAdmin = $sender->isAdmin() && $thread->user_id !== $sender->id;
        if (! $fromAdmin && $thread->user_id !== $sender->id) {
            throw new DomainException('گفتگو پیدا نشد.', 404);
        }
        // ضد اسپم: ۱۰ پیام در دقیقه و ۱۰۰ در روز برای هر عضو (مدیران محدودیت روزانه ندارند)
        $limits = $fromAdmin ? [['sup-m:', 30, 60]] : [['sup-m:', 10, 60], ['sup-d:', 100, 86400]];
        foreach ($limits as [$prefix, $max]) {
            if (RateLimiter::tooManyAttempts($prefix.$sender->id, $max)) {
                throw new DomainException('پیام‌های شما زیاد شده است؛ کمی بعد دوباره امتحان کنید.', 429, 'support_limit');
            }
        }
        $body = $voiceFile !== null && trim($body) === '' ? '' : MessagingService::clean($body);
        foreach ($limits as [$prefix, , $decay]) {
            RateLimiter::hit($prefix.$sender->id, $decay);
        }
        $voice = $voiceFile ? $this->voices->store($sender, $voiceFile, 'support', $waveform) : null;

        $message = DB::transaction(function () use ($sender, $thread, $body, $voice, $fromAdmin) {
            $message = SupportMessage::create([
                'thread_id' => $thread->id,
                'sender_id' => $sender->id,
                'from_admin' => $fromAdmin,
                'body' => $body,
                'voice_id' => $voice?->id,
            ]);
            $thread->forceFill([
                'last_message_at' => now(),
                'closed' => false,
                $fromAdmin ? 'admin_read_id' : 'user_read_id' => $message->id,
            ])->save();

            return $message;
        });
        if ($voice) {
            $message->setRelation('voice', $voice);
        }

        // اعلان (هر گفتگو حداکثر هر ۱۰ دقیقه یک بار)
        if ($fromAdmin) {
            if (Cache::add('sup-notify-user:'.$thread->id, 1, 600)) {
                $thread->user?->notify(new SupportReplied($thread));
            }
        } elseif (Cache::add('sup-notify-admin:'.$thread->id, 1, 600)) {
            Notification::send(User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->where('status', User::STATUS_ACTIVE)->get(), new SupportRequested($thread, $sender));
        }

        return $message;
    }

    public function markRead(User $viewer, SupportThread $thread): void
    {
        $last = (int) SupportMessage::query()->where('thread_id', $thread->id)->max('id');
        $column = $thread->user_id === $viewer->id ? 'user_read_id' : 'admin_read_id';
        if ($last > (int) $thread->{$column}) {
            $thread->forceFill([$column => $last])->save();
        }
    }

    /** پیام‌های پشتیبانیِ نخوانده برای عضو */
    public function unreadForUser(User $user): int
    {
        $thread = SupportThread::query()->where('user_id', $user->id)->first();

        return $thread ? SupportMessage::query()->where('thread_id', $thread->id)->where('from_admin', true)->where('id', '>', $thread->user_read_id)->count() : 0;
    }

    /** گفتگوهایی که پیام نخوانده برای مدیران دارند */
    public function unreadThreadsForAdmins(): int
    {
        return SupportThread::query()->whereExists(function ($q) {
            $q->select(DB::raw(1))->from('support_messages')
                ->whereColumn('support_messages.thread_id', 'support_threads.id')
                ->where('support_messages.from_admin', false)
                ->whereColumn('support_messages.id', '>', 'support_threads.admin_read_id');
        })->count();
    }

    public function delete(User $actor, SupportMessage $message): void
    {
        if ($message->sender_id !== $actor->id) {
            throw new DomainException('فقط فرستنده می‌تواند پیامش را حذف کند.', 403);
        }
        if ($message->voice_id) {
            $this->voices->delete($message->voice);
        }
        $message->forceFill(['body' => '', 'voice_id' => null, 'deleted_at' => now()])->save();
    }

    public function present(SupportMessage $m, User $viewer): array
    {
        $deleted = $m->deleted_at !== null;

        return [
            'id' => $m->id,
            'mine' => $m->sender_id === $viewer->id,
            'from_admin' => $m->from_admin,
            'sender' => $m->from_admin ? ($viewer->isAdmin() ? $m->sender?->displayName() : 'پشتیبانی') : $m->sender?->displayName(),
            'body' => $deleted ? null : $m->body,
            'voice' => ! $deleted && $m->voice_id && $m->voice instanceof VoiceNote ? $m->voice->present() : null,
            'deleted' => $deleted,
            'at' => $m->created_at?->toIso8601String(),
        ];
    }
}
