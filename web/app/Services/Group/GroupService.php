<?php

namespace App\Services\Group;

use App\Exceptions\DomainException;
use App\Models\GroupMessage;
use App\Models\GroupReaction;
use App\Models\GroupReport;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\GroupReply;
use App\Services\AuditLogger;
use App\Services\Media\MediaService;
use App\Services\Media\VoiceService;
use App\Services\Occasions\BirthdayService;
use App\Services\Tree\NodePresenter;
use App\Support\PartialDate;
use App\Support\PersianText;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * «گروه خاطرات خاندان»: همه اعضا با هم گفتگو می‌کنند و عکس و فیلم‌های قدیمی می‌گذارند.
 *
 *  - فقط متن (با ایموجی) و عکس/فیلم؛ پیامِ فقط ایموجی پذیرفته نمی‌شود (باید کلمه‌ای هم باشد)
 *  - هر عکس و فیلم در پروفایل خود فرستنده هم به عنوان «خاطره» می‌ماند (با برچسب اشخاص داخل عکس)
 *  - ضد اسپم و فلود: فاصله بین پیام‌ها، سقف دقیقه‌ای و روزانه، رد پیام تکراری، بدون لینک
 *  - مدیران: سنجاق، حذف هر پیام، سکوت یک عضو، رسیدگی به گزارش‌ها
 */
class GroupService
{
    public const REACTIONS = ['❤️', '😂', '😢', '👍', '🙏', '😮', '🌹'];

    public const PINNED_MAX = 5;

    public const TAGS_MAX = 15;

    public function __construct(
        private readonly MediaService $media,
        private readonly AuditLogger $audit,
        private readonly VoiceService $voices,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('pedigree.group.enabled', true);
    }

    /** چرا این عضو نمی‌تواند در گروه پیام بگذارد؟ (null = می‌تواند) */
    public function postProblem(User $user): ?string
    {
        if (! $this->enabled()) {
            return 'گروه خاندان فعلاً غیرفعال است.';
        }
        if ($user->group_muted_until && $user->group_muted_until->isFuture()) {
            return $user->group_muted_until->year >= 2100
                ? 'مدیر سایت امکان پیام دادن شما در گروه را بسته است.'
                : 'مدیر سایت امکان پیام دادن شما در گروه را تا '.PersianText::toPersianDigits($user->group_muted_until->diffForHumans(['parts' => 1, 'syntax' => 1])).' بسته است.';
        }

        return null;
    }

    // ------------------------------------------------------------------ ارسال

    /** @throws DomainException */
    public function postText(User $user, string $body, ?int $replyTo = null): GroupMessage
    {
        $this->assertCanPost($user);
        $body = $this->cleanBody($body);
        $this->assertNotDuplicate($user, $body);
        $reply = $this->replyTarget($replyTo);
        $this->hit($user);

        $message = GroupMessage::create(['user_id' => $user->id, 'kind' => GroupMessage::KIND_TEXT, 'body' => $body, 'reply_to_id' => $reply?->id]);
        $this->notifyReply($user, $reply, $message);

        return $message;
    }

    /**
     * پیام صوتی (خاطره با صدای خود شخص؛ مثل وویس تلگرام). متن کوتاه اختیاری است.
     *
     * @throws DomainException
     */
    public function postVoice(User $user, UploadedFile $file, mixed $waveform = null, ?string $caption = null, ?int $replyTo = null): GroupMessage
    {
        $this->assertCanPost($user);
        $caption = $caption !== null && trim($caption) !== '' ? $this->cleanBody($caption) : '';
        $reply = $this->replyTarget($replyTo);
        $this->hit($user);
        $voice = $this->voices->store($user, $file, 'group', $waveform);

        $message = GroupMessage::create([
            'user_id' => $user->id,
            'kind' => GroupMessage::KIND_VOICE,
            'body' => $caption,
            'voice_id' => $voice->id,
            'reply_to_id' => $reply?->id,
        ]);
        $this->notifyReply($user, $reply, $message);

        return $message->setRelation('voice', $voice);
    }

    /**
     * عکس یا فیلم خاطره: در پروفایل فرستنده ذخیره (و به JPEG/MP4 فشرده) می‌شود و در گروه می‌آید
     *
     * @param  string[]  $tags  شناسه اشخاصی که در عکس هستند
     *
     * @throws DomainException
     */
    public function postMedia(User $user, UploadedFile $file, string $caption, ?string $takenAt = null, array $tags = [], ?int $replyTo = null): GroupMessage
    {
        $this->assertCanPost($user);
        if (! $user->person) {
            throw new DomainException('حساب شما به پروفایلی در شجره‌نامه وصل نیست.');
        }
        $caption = $this->cleanBody($caption, 'برای خاطره باید متن یا کلمه‌ای هم اضافه شود (فقط ایموجی کافی نیست).');
        if (mb_strlen($caption) > 300) {
            throw new DomainException('توضیح عکس حداکثر ۳۰۰ نویسه باشد.');
        }
        $reply = $this->replyTarget($replyTo);
        $tags = array_values(array_unique(array_filter($tags, 'is_string')));
        if (count($tags) > self::TAGS_MAX) {
            throw new DomainException('حداکثر '.PersianText::toPersianDigits((string) self::TAGS_MAX).' نفر را می‌شود در یک عکس نام برد.');
        }
        $people = $tags ? Person::query()->whereIn('id', $tags)->pluck('id')->all() : [];

        $key = 'grp-md:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('pedigree.group.daily_media', 20)))) {
            throw new DomainException('امروز به اندازه کافی عکس و فیلم در گروه گذاشته‌اید؛ فردا ادامه دهید.', 429, 'group_limit');
        }
        $this->hit($user);
        RateLimiter::hit($key, 86400);

        $media = $this->media->store($user->person, $file, $user, [
            'caption' => $caption,
            'taken_at' => PartialDate::normalize($takenAt),
            'category' => Media::CATEGORY_MEMORY,
        ]);
        if ($people) {
            $media->taggedPeople()->syncWithPivotValues($people, ['tagged_by' => $user->id]);
        }

        $message = GroupMessage::create([
            'user_id' => $user->id,
            'kind' => GroupMessage::KIND_MEDIA,
            'body' => $caption,
            'media_id' => $media->id,
            'reply_to_id' => $reply?->id,
        ]);
        $this->notifyReply($user, $reply, $message);

        return $message;
    }

    /**
     * یکدست‌سازی متن و قوانین ضد اسپم
     *
     * @throws DomainException
     */
    public function cleanBody(string $body, ?string $emojiOnlyMessage = null): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace('/[\x00-\x09\x0B-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}\x{FEFF}]/u', '', $body) ?? '';
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? '';
        $body = preg_replace('/[ \t]{3,}/u', '  ', $body) ?? '';
        $body = trim($body);

        if ($body === '') {
            throw new DomainException('پیام خالی است.');
        }
        $max = (int) config('pedigree.group.max_length', 2000);
        if (mb_strlen($body) > $max) {
            throw new DomainException('پیام حداکثر '.PersianText::toPersianDigits((string) $max).' نویسه باشد.');
        }
        // فقط ایموجی (یا علامت) پذیرفته نیست: دست‌کم یک حرف یا عدد لازم است
        if (! preg_match('/[\p{L}\p{N}]/u', $body)) {
            throw new DomainException($emojiOnlyMessage ?? 'فقط ایموجی نمی‌شود فرستاد؛ باید متن یا کلمه‌ای هم اضافه شود.', 422, 'emoji_only');
        }
        if (! config('pedigree.group.allow_links') && preg_match('~(\b[a-z][a-z0-9+.-]{1,15}://|www\.|\bt\.me/|\b[a-z0-9-]{2,}\s*(\.|\[\.\]|\(\.\))\s*(com|ir|net|org|me|io|app|info|xyz|site|online|top|link|click|ly|co|ru|de|uk|cc|tk|to|gg|in|us|tv|ai|sh|gl|gd|ws|su|tr|ae|eu|biz|pro|dev|shop|store|club|live|news|blog|space|fun|website|page|icu|vip|win|bid|cam|lol)\b)~iu', $body)) {
            throw new DomainException('فرستادن لینک در گروه خاندان مجاز نیست.', 422, 'links');
        }
        // بیش از ۲۰ تکرار پشت سر هم یک نویسه (مثل «ههههههه...» طولانی) کوتاه می‌شود
        $body = preg_replace('/(.)\1{20,}/u', str_repeat('$1', 20), $body) ?? $body;

        return $body;
    }

    /** @throws DomainException */
    private function assertCanPost(User $user): void
    {
        if ($problem = $this->postProblem($user)) {
            throw new DomainException($problem, 403, 'group_blocked');
        }
        $slow = max(0, (int) config('pedigree.group.slow_mode_seconds', 3));
        if ($slow > 0 && RateLimiter::tooManyAttempts('grp-s:'.$user->id, 1)) {
            throw new DomainException('کمی آهسته‌تر؛ چند ثانیه بعد بفرستید.', 429, 'slow_mode');
        }
        if (RateLimiter::tooManyAttempts('grp-m:'.$user->id, max(1, (int) config('pedigree.group.per_minute', 15)))
            || RateLimiter::tooManyAttempts('grp-d:'.$user->id, max(1, (int) config('pedigree.group.daily_messages', 300)))) {
            throw new DomainException('تعداد پیام‌های شما در گروه زیاد شده است؛ کمی بعد دوباره امتحان کنید.', 429, 'group_limit');
        }
    }

    private function hit(User $user): void
    {
        $slow = max(0, (int) config('pedigree.group.slow_mode_seconds', 3));
        if ($slow > 0) {
            RateLimiter::hit('grp-s:'.$user->id, $slow);
        }
        RateLimiter::hit('grp-m:'.$user->id, 60);
        RateLimiter::hit('grp-d:'.$user->id, 86400);
    }

    /** همان متن دوباره در کمتر از ۲ دقیقه (پیام تکراری / اسپم) */
    private function assertNotDuplicate(User $user, string $body): void
    {
        $last = GroupMessage::query()->where('user_id', $user->id)->where('kind', GroupMessage::KIND_TEXT)
            ->whereNull('deleted_at')->where('created_at', '>=', now()->subMinutes(2))->latest('id')->first();
        if ($last && $last->body === $body) {
            throw new DomainException('این پیام را همین الان فرستاده‌اید.', 422, 'duplicate');
        }
    }

    private function replyTarget(?int $id): ?GroupMessage
    {
        if ($id === null) {
            return null;
        }
        $reply = GroupMessage::query()->find($id);
        if ($reply === null || $reply->deleted_at) {
            throw new DomainException('پیامی که به آن پاسخ می‌دهید پیدا نشد.');
        }

        return $reply;
    }

    private function notifyReply(User $from, ?GroupMessage $reply, GroupMessage $message): void
    {
        // هر نفر برای یک نفر حداکثر هر ۵ دقیقه یک اعلان پاسخ (جلوگیری از مزاحمت با پاسخ‌های پشت سر هم)
        if ($reply && $reply->user_id && $reply->user_id !== $from->id
            && Cache::add("grp-reply:{$from->id}:{$reply->user_id}", 1, 300)) {
            $reply->user?->notify(new GroupReply($from, $message));
        }
    }

    // ------------------------------------------------------------------ واکنش، حذف، گزارش، سنجاق، سکوت

    /** واکنش (یک واکنش برای هر نفر؛ null = برداشتن) */
    public function react(User $user, GroupMessage $message, ?string $emoji): void
    {
        if ($message->deleted_at || ! $this->enabled()) {
            throw new DomainException('این پیام در دسترس نیست.');
        }
        if ($emoji !== null && ! in_array($emoji, self::REACTIONS, true)) {
            throw new DomainException('این واکنش مجاز نیست.');
        }
        if (RateLimiter::tooManyAttempts('grp-r:'.$user->id, 60)) {
            throw new DomainException('کمی آهسته‌تر.', 429, 'slow_mode');
        }
        RateLimiter::hit('grp-r:'.$user->id, 60);

        DB::transaction(function () use ($user, $message, $emoji) {
            GroupReaction::query()->where('message_id', $message->id)->where('user_id', $user->id)->delete();
            if ($emoji !== null) {
                GroupReaction::query()->create(['message_id' => $message->id, 'user_id' => $user->id, 'emoji' => $emoji]);
            }
            $message->touch();
        });
    }

    /** حذف پیام (فرستنده یا مدیر). عکس/فیلم در پروفایل فرستنده می‌ماند. */
    public function delete(User $actor, GroupMessage $message): void
    {
        if ($message->user_id !== $actor->id && ! $actor->isAdmin()) {
            throw new DomainException('فقط فرستنده یا مدیر می‌تواند این پیام را حذف کند.', 403);
        }
        if ($message->voice_id) {
            $this->voices->delete($message->voice);
        }
        $message->forceFill(['body' => '', 'deleted_at' => now(), 'pinned_at' => null, 'media_id' => null, 'voice_id' => null])->save();
        GroupReaction::query()->where('message_id', $message->id)->delete();
        if ($message->user_id !== $actor->id) {
            $this->audit->log('group.message_deleted', null, ['message' => $message->id, 'author' => $message->user_id], $actor);
        }
    }

    public function report(User $user, GroupMessage $message, ?string $reason): void
    {
        if ($message->deleted_at || $message->user_id === $user->id) {
            throw new DomainException('این پیام قابل گزارش نیست.');
        }
        if (RateLimiter::tooManyAttempts('grp-rep:'.$user->id, 20)) {
            throw new DomainException('گزارش‌های زیادی فرستاده‌اید؛ بعداً امتحان کنید.', 429);
        }
        RateLimiter::hit('grp-rep:'.$user->id, 3600);
        $reason = $reason !== null ? mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $reason) ?? ''), 0, 200) : null;
        GroupReport::query()->updateOrCreate(['message_id' => $message->id, 'user_id' => $user->id], ['reason' => $reason ?: null]);
    }

    public function pin(User $admin, GroupMessage $message, bool $pin): void
    {
        if ($message->deleted_at) {
            throw new DomainException('این پیام حذف شده است.');
        }
        if ($pin && ! $message->pinned_at && GroupMessage::query()->whereNotNull('pinned_at')->count() >= self::PINNED_MAX) {
            throw new DomainException('حداکثر '.PersianText::toPersianDigits((string) self::PINNED_MAX).' پیام سنجاق می‌شود؛ ابتدا یکی را بردارید.');
        }
        $message->forceFill(['pinned_at' => $pin ? now() : null])->save();
        $this->audit->log($pin ? 'group.pinned' : 'group.unpinned', null, ['message' => $message->id], $admin);
    }

    /** سکوت: عضو فقط می‌خواند (days = null یعنی تا وقتی مدیر بردارد؛ ۰ = برداشتن) */
    public function mute(User $admin, User $target, ?int $days): void
    {
        if ($target->isAdmin() && ! $admin->isSuperAdmin()) {
            throw new DomainException('مدیران را فقط مدیر کل می‌تواند محدود کند.', 403);
        }
        if ($target->id === $admin->id) {
            throw new DomainException('خودتان را نمی‌توانید محدود کنید.');
        }
        $until = match (true) {
            $days === 0 => null,
            $days === null => now()->addYears(100),
            default => now()->addDays(max(1, min(365, $days))),
        };
        $target->forceFill(['group_muted_until' => $until])->save();
        $this->audit->log($until ? 'group.muted' : 'group.unmuted', $target->person, ['user' => $target->id, 'days' => $days], $admin);
    }

    // ------------------------------------------------------------------ خواندن

    public function unreadCount(User $user): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        return min(999, GroupMessage::query()->where('id', '>', (int) $user->group_read_id)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->whereNull('deleted_at')->count());
    }

    public function markRead(User $user, int $upTo): void
    {
        $max = (int) GroupMessage::query()->max('id');
        $upTo = min($upTo, $max);
        if ($upTo > (int) $user->group_read_id) {
            $user->forceFill(['group_read_id' => $upTo])->saveQuietly();
        }
    }

    /**
     * نمایش پیام‌ها (با بارگذاری دسته‌ای)
     *
     * @param  Collection<int, GroupMessage>  $messages
     */
    public function present(Collection $messages, User $viewer): array
    {
        if ($messages->isEmpty()) {
            return [];
        }
        $messages = (new EloquentCollection($messages->all()))->loadMissing(['user.person.avatar', 'media.taggedPeople', 'replyTo.user.person', 'voice']);
        $ids = $messages->pluck('id')->all();
        $reactions = GroupReaction::query()->whereIn('message_id', $ids)->get(['message_id', 'user_id', 'emoji'])->groupBy('message_id');

        return $messages->map(function (GroupMessage $m) use ($viewer, $reactions) {
            $deleted = $m->deleted_at !== null;
            $rows = $reactions->get($m->id, collect());
            $grouped = [];
            foreach (self::REACTIONS as $emoji) {
                $count = $rows->where('emoji', $emoji)->count();
                if ($count) {
                    $grouped[] = ['emoji' => $emoji, 'count' => $count, 'mine' => $rows->where('emoji', $emoji)->contains('user_id', $viewer->id)];
                }
            }
            $media = ! $deleted && $m->media ? $this->presentMedia($m->media, $viewer) : null;
            $reply = $m->replyTo;

            return [
                'id' => $m->id,
                'kind' => $m->kind,
                'body' => $deleted ? null : $m->body,
                'deleted' => $deleted,
                'mine' => $m->user_id === $viewer->id,
                'user' => $m->user ? [
                    'id' => $m->user->id,
                    'name' => $m->user->person ? $m->user->person->fullName() : $m->user->displayName(),
                    'person' => $m->user->person ? NodePresenter::person($m->user->person) : null,
                ] : null,
                'reply_to' => $reply ? [
                    'id' => $reply->id,
                    'name' => $reply->user?->person?->first_name ?? ($reply->user ? $reply->user->displayName() : 'سؤال روز'),
                    'text' => $reply->deleted_at ? 'پیام حذف شد' : ($reply->kind === GroupMessage::KIND_VOICE && (string) $reply->body === '' ? '🎤 پیام صوتی' : mb_substr((string) $reply->body, 0, 80)),
                    'kind' => $reply->kind,
                ] : null,
                'media' => $media,
                'voice' => ! $deleted && $m->voice ? $m->voice->present() : null,
                'reactions' => $grouped,
                'pinned' => $m->pinned_at !== null,
                'at' => $m->created_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    private function presentMedia(Media $media, User $viewer): ?array
    {
        $visible = $media->isApproved() || $media->uploaded_by === $viewer->id || $viewer->isAdmin();

        return [
            'id' => $media->id,
            'type' => $media->type,
            'status' => $media->status,
            'processing' => $media->processing,
            'visible' => $visible,
            'width' => $media->width,
            'height' => $media->height,
            'duration' => $media->duration,
            'taken_at' => $media->taken_at,
            'person_id' => $media->person_id,
            'urls' => $visible && $media->status !== Media::STATUS_REJECTED ? [
                'thumb' => $media->url('thumb'),
                'medium' => $media->isImage() ? $media->url('medium') : null,
                'poster' => $media->isVideo() && $media->pathFor('poster') ? $media->url('poster') : null,
                'original' => $media->url('original'),
            ] : null,
            'tags' => $media->taggedPeople->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->fullName()])->values(),
        ];
    }

    // ------------------------------------------------------------------ سؤال روز

    /** «سؤال روز» برای زنده کردن خاطرات (هر روز یک بار) */
    public function postDailyPrompt(bool $force = false): ?GroupMessage
    {
        if (! $this->enabled() || ! config('pedigree.group.daily_prompt', true)) {
            return null;
        }
        $now = now('Asia/Tehran');
        if (! $force && $now->hour < (int) config('pedigree.group.prompt_hour', 10)) {
            return null;
        }
        if (! BirthdayService::claim('group-prompt:'.$now->toDateString())) {
            return null;
        }
        $prompts = MemoryPrompts::ALL;
        $question = $prompts[$now->dayOfYear % count($prompts)];

        return GroupMessage::create(['user_id' => null, 'kind' => GroupMessage::KIND_PROMPT, 'body' => $question]);
    }
}
