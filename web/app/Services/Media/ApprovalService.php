<?php

namespace App\Services\Media;

use App\Exceptions\DomainException;
use App\Models\Media;
use App\Models\MediaVote;
use App\Models\Person;
use App\Models\User;
use App\Notifications\MediaAwaitingVote;
use App\Notifications\MediaDecided;
use App\Services\AuditLogger;
use App\Services\Kinship;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * تأیید عکس و ویدیو با رأی‌گیری بستگان.
 *
 * چه کسی تصمیم می‌گیرد؟ (به ترتیب)
 *  ۱. آپلود توسط مدیر ← تأیید خودکار
 *  ۲. آپلود توسط خودِ صاحب پروفایل ← تأیید خودکار
 *  ۳. صاحب پروفایل زنده است و حساب فعال دارد ← فقط خودش تصمیم می‌گیرد (owner)
 *  ۴. در غیر این صورت رأی‌گیری (vote) بین اولین گروهِ دارای عضو فعال:
 *       درگذشته: فرزندان ← خواهر/برادرها ← همسران ← والدین
 *       زنده بدون حساب (مثلاً کودک یا سالمند): والدین ← خواهر/برادرها ← فرزندان ← همسران
 *  ۵. اگر هیچ رأی‌دهنده‌ای نبود ← تصمیم با مدیر (admin)
 *
 * قانون اکثریت: تأیید وقتی که موافقان «بیشتر از نصف» کل رأی‌دهندگان باشند؛
 * رد وقتی که دیگر رسیدن به اکثریت ممکن نباشد.
 */
class ApprovalService
{
    public function __construct(
        private readonly Kinship $kinship,
        private readonly AuditLogger $audit,
    ) {}

    public function initiate(Media $media, User $uploader): void
    {
        $person = $media->person()->first();
        $config = config('pedigree.approval');

        if ($uploader->isAdmin() && $config['admin_auto_approve']) {
            $this->finalize($media, Media::STATUS_APPROVED, null, 'auto');

            return;
        }
        if ($uploader->person_id === $person->id && $config['self_auto_approve']) {
            $this->finalize($media, Media::STATUS_APPROVED, null, 'auto');

            return;
        }

        $voters = collect();
        $mode = 'vote';

        if (! $person->is_deceased && $config['owner_decides_when_alive'] && $person->hasActiveAccount()) {
            $voters = collect([$person->user]);
            $mode = 'owner';
        } else {
            $groups = $config['voter_groups'][$person->is_deceased ? 'deceased' : 'living'] ?? [];
            foreach ($groups as $group) {
                $voters = $this->activeUsersOf($this->kinship->group($person, $group)->all())
                    ->reject(fn (User $u) => $u->id === $uploader->id)
                    ->values();
                if ($voters->isNotEmpty()) {
                    break;
                }
            }
        }

        if ($voters->isEmpty()) {
            if (($config['fallback'] ?? 'admin') === 'approve') {
                $this->finalize($media, Media::STATUS_APPROVED, null, 'auto');

                return;
            }
            $mode = 'admin';
            $voters = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
                ->where('status', User::STATUS_ACTIVE)->get();
        }

        $media->approval_mode = $mode;
        $media->save();

        foreach ($voters as $voter) {
            MediaVote::firstOrCreate(['media_id' => $media->id, 'user_id' => $voter->id]);
        }

        Notification::send($voters, new MediaAwaitingVote($media));
    }

    /** ثبت رأی یک عضو */
    public function vote(Media $media, User $user, string $decision, ?string $comment = null): Media
    {
        if (! $media->isPending()) {
            throw new DomainException('رأی‌گیری این مورد به پایان رسیده است.');
        }

        $vote = MediaVote::where('media_id', $media->id)->where('user_id', $user->id)->first();
        if (! $vote) {
            throw new DomainException('شما جزو رأی‌دهندگان این مورد نیستید.', 403);
        }

        DB::transaction(function () use ($vote, $decision, $comment, $media, $user) {
            $vote->decision = $decision;
            $vote->comment = $comment;
            $vote->voted_at = now();
            $vote->save();
            $this->audit->log('media.voted', $media, ['decision' => $decision], $user);
            $this->evaluate($media);
        });

        return $media->refresh();
    }

    /** بررسی نتیجه رأی‌ها */
    public function evaluate(Media $media): void
    {
        $votes = MediaVote::where('media_id', $media->id)->get();
        $total = $votes->count();
        if ($total === 0) {
            return;
        }

        $approve = $votes->where('decision', MediaVote::APPROVE)->count();
        $reject = $votes->where('decision', MediaVote::REJECT)->count();
        $needed = $total * (float) config('pedigree.approval.threshold', 0.5);

        if ($approve > $needed) {
            $this->finalize($media, Media::STATUS_APPROVED);
        } elseif ($total - $reject <= $needed) {
            $this->finalize($media, Media::STATUS_REJECTED);
        }
    }

    /** تصمیم مستقیم مدیر (بدون رأی‌گیری) */
    public function decide(Media $media, User $admin, string $decision, ?string $note = null): Media
    {
        if (! $admin->isAdmin()) {
            throw new DomainException('فقط مدیر می‌تواند مستقیماً تصمیم بگیرد.', 403);
        }
        if (! $media->isPending()) {
            throw new DomainException('این مورد قبلاً بررسی شده است.');
        }
        $status = $decision === MediaVote::APPROVE ? Media::STATUS_APPROVED : Media::STATUS_REJECTED;
        $this->finalize($media, $status, $admin, null, $note);

        return $media->refresh();
    }

    /**
     * جمع‌بندی رأی‌گیری‌های طولانی (اجرا توسط زمان‌بند روزانه).
     * موافق بیشتر ← تأیید ، مخالف بیشتر یا مساوی ← رد ، بدون هیچ رأی ← ارجاع به مدیر
     */
    public function resolveStale(): int
    {
        $days = (int) config('pedigree.approval.timeout_days', 14);
        $count = 0;
        $stale = Media::where('status', Media::STATUS_PENDING)
            ->whereIn('approval_mode', ['vote', 'owner'])
            ->where('created_at', '<', now()->subDays($days))
            ->get();

        foreach ($stale as $media) {
            $votes = MediaVote::where('media_id', $media->id)->whereNotNull('decision')->get();
            $approve = $votes->where('decision', MediaVote::APPROVE)->count();
            $reject = $votes->where('decision', MediaVote::REJECT)->count();

            if ($votes->isEmpty()) {
                $media->approval_mode = 'admin';
                $media->save();
                $admins = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])->where('status', User::STATUS_ACTIVE)->get();
                foreach ($admins as $admin) {
                    MediaVote::firstOrCreate(['media_id' => $media->id, 'user_id' => $admin->id]);
                }
                Notification::send($admins, new MediaAwaitingVote($media));
            } else {
                $this->finalize($media, $approve > $reject ? Media::STATUS_APPROVED : Media::STATUS_REJECTED, null, null, 'جمع‌بندی خودکار پس از پایان مهلت رأی‌گیری');
            }
            $count++;
        }

        return $count;
    }

    private function finalize(Media $media, string $status, ?User $decider = null, ?string $mode = null, ?string $note = null): void
    {
        $media->status = $status;
        $media->decided_at = now();
        $media->decided_by = $decider?->id;
        $media->decision_note = $note;
        if ($mode) {
            $media->approval_mode = $mode;
        }
        $media->save();

        if ($status === Media::STATUS_APPROVED && $media->set_as_avatar && $media->isImage()) {
            Person::whereKey($media->person_id)->update(['avatar_media_id' => $media->id]);
        }

        // فایلِ ردشده نگه داشته نمی‌شود (ممکن است محتوای نامناسب باشد)
        if ($status === Media::STATUS_REJECTED) {
            $paths = array_filter(array_merge([$media->path], array_values($media->variants ?? [])));
            Storage::disk($media->disk)->delete($paths);
        }

        $this->audit->log('media.'.$status, $media, ['mode' => $media->approval_mode], $decider);

        $uploader = $media->uploader;
        if ($uploader && $uploader->id !== $decider?->id && $media->approval_mode !== 'auto') {
            $uploader->notify(new MediaDecided($media));
        }
    }

    /**
     * کاربران فعالِ متصل به این اشخاص (فقط کسانی که حداقل یکبار وارد شده‌اند)
     *
     * @param  Person[]  $persons
     */
    private function activeUsersOf(array $persons): Collection
    {
        $ids = array_map(fn (Person $p) => $p->id, $persons);
        if (! $ids) {
            return collect();
        }

        return User::whereIn('person_id', $ids)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('last_login_at')
            ->get();
    }

    /** آیا کاربر می‌تواند یک رسانه در انتظار را ببیند؟ */
    public function canSeePending(User $user, Media $media): bool
    {
        return $user->isAdmin()
            || $media->uploaded_by === $user->id
            || MediaVote::where('media_id', $media->id)->where('user_id', $user->id)->exists();
    }
}
