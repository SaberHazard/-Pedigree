<?php

namespace App\Services\People;

use App\Exceptions\DomainException;
use App\Models\LinkRequest;
use App\Models\Person;
use App\Models\User;
use App\Notifications\LinkRequestDecided;
use App\Notifications\LinkRequested;
use App\Services\Access\PersonAccess;
use App\Services\AuditLogger;
use App\Services\Kinship;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * اتصال دو شخصِ «موجود» به هم؛ مهم‌ترین کاربرد: وصل کردن درخت‌ها.
 *
 * مثال: همسرِ من در درخت خانواده پدری‌اش ثبت شده؛ با این سرویس او را به
 * عنوان همسر به من وصل می‌کنم و از آن به بعد با یک کلیک می‌توان درخت
 * خانوادگی او را تا جد اعلایش دید.
 *
 * اگر درخواست‌دهنده حق ویرایش هر دو طرف را نداشته باشد، یک «درخواست اتصال»
 * ساخته می‌شود که یکی از بستگانِ طرف مقابل باید تأییدش کند.
 */
class LinkService
{
    public function __construct(
        private readonly PersonAccess $access,
        private readonly Kinship $kinship,
        private readonly RelativeService $relatives,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{status: 'linked'|'requested', request?: LinkRequest}
     */
    public function link(Person $subject, string $type, Person $target, User $actor, array $payload = [], ?string $message = null): array
    {
        if (! $this->access->canEdit($actor, $subject)) {
            throw new DomainException('شما اجازه ویرایش این شخص را ندارید.', 403);
        }

        $this->validate($subject, $type, $target, $payload);

        $direct = $this->access->canEdit($actor, $target) || ! config('pedigree.permissions.link_requires_approval');
        if ($direct) {
            $this->perform($subject, $type, $target, $actor, $payload);

            return ['status' => 'linked'];
        }

        $duplicate = LinkRequest::where('subject_id', $subject->id)->where('target_id', $target->id)
            ->where('type', $type)->where('status', 'pending')->first();
        if ($duplicate) {
            return ['status' => 'requested', 'request' => $duplicate];
        }

        $request = new LinkRequest([
            'type' => $type,
            'subject_id' => $subject->id,
            'target_id' => $target->id,
            'payload' => $payload ?: null,
            'message' => $message,
        ]);
        $request->requested_by = $actor->id;
        $request->save();

        $this->audit->log('link.requested', $request, ['type' => $type, 'subject' => $subject->id, 'target' => $target->id], $actor);

        $approvers = $this->approversFor($target)->reject(fn (User $u) => $u->id === $actor->id);
        Notification::send($approvers, new LinkRequested($request));

        return ['status' => 'requested', 'request' => $request];
    }

    /** کسانی که می‌توانند درخواست اتصال به target را تأیید کنند */
    public function approversFor(Person $target)
    {
        $editors = $this->access->editorsOf($target);
        if ($editors->isEmpty()) {
            $editors = User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
                ->where('status', User::STATUS_ACTIVE)->get();
        }

        return $editors;
    }

    public function canDecide(User $user, LinkRequest $request): bool
    {
        return $request->status === 'pending' && $this->access->canEdit($user, $request->target);
    }

    public function accept(LinkRequest $request, User $actor): void
    {
        if (! $this->canDecide($actor, $request)) {
            throw new DomainException('شما اجازه تأیید این درخواست را ندارید.', 403);
        }

        DB::transaction(function () use ($request, $actor) {
            $subject = $request->subject()->firstOrFail();
            $target = $request->target()->firstOrFail();
            $this->validate($subject, $request->type, $target, $request->payload ?? []);
            $this->perform($subject, $request->type, $target, $actor, $request->payload ?? []);

            $request->status = 'accepted';
            $request->decided_by = $actor->id;
            $request->decided_at = now();
            $request->save();
            $this->audit->log('link.accepted', $request, [], $actor);
        });

        $request->requester?->notify(new LinkRequestDecided($request));
    }

    public function reject(LinkRequest $request, User $actor): void
    {
        if (! $this->canDecide($actor, $request)) {
            throw new DomainException('شما اجازه رد این درخواست را ندارید.', 403);
        }
        $request->status = 'rejected';
        $request->decided_by = $actor->id;
        $request->decided_at = now();
        $request->save();
        $this->audit->log('link.rejected', $request, [], $actor);
        $request->requester?->notify(new LinkRequestDecided($request));
    }

    public function cancel(LinkRequest $request, User $actor): void
    {
        if ($request->requested_by !== $actor->id && ! $actor->isAdmin()) {
            throw new DomainException('فقط درخواست‌دهنده می‌تواند درخواست را لغو کند.', 403);
        }
        if ($request->status !== 'pending') {
            throw new DomainException('این درخواست دیگر در انتظار نیست.');
        }
        $request->status = 'cancelled';
        $request->save();
    }

    /** بررسی منطقی بودن اتصال (جنسیت، دور در درخت، جای خالی والد) */
    public function validate(Person $subject, string $type, Person $target, array $payload = []): void
    {
        if ($subject->id === $target->id) {
            throw new DomainException('یک شخص نمی‌تواند به خودش وصل شود.');
        }

        switch ($type) {
            case 'spouse':
                if ($subject->gender === $target->gender) {
                    throw new DomainException('ازدواج فقط بین زن و مرد قابل ثبت است.');
                }
                if ($this->kinship->areMarried($subject->id, $target->id)) {
                    throw new DomainException('این دو نفر از قبل همسر هم ثبت شده‌اند.');
                }
                if ($this->kinship->isAncestorOf($subject->id, $target) || $this->kinship->isAncestorOf($target->id, $subject)) {
                    throw new DomainException('امکان ثبت ازدواج بین جد و نواده وجود ندارد.');
                }
                break;

            case 'father':
            case 'mother':
                $gender = $type === 'father' ? Person::MALE : Person::FEMALE;
                $field = $type.'_id';
                if ($target->gender !== $gender) {
                    throw new DomainException($type === 'father' ? 'پدر باید مرد باشد.' : 'مادر باید زن باشد.');
                }
                if ($subject->{$field} && $subject->{$field} !== $target->id) {
                    throw new DomainException($type === 'father' ? 'این شخص از قبل پدر دارد.' : 'این شخص از قبل مادر دارد.');
                }
                if ($this->kinship->isAncestorOf($subject->id, $target)) {
                    throw new DomainException('این اتصال باعث ایجاد دور در درخت می‌شود (شخص نمی‌تواند نواده خودش باشد).');
                }
                break;

            case 'child':
                $field = $subject->isMale() ? 'father_id' : 'mother_id';
                if ($target->{$field} && $target->{$field} !== $subject->id) {
                    throw new DomainException($subject->isMale() ? 'این فرزند از قبل پدر دارد.' : 'این فرزند از قبل مادر دارد.');
                }
                if ($this->kinship->isAncestorOf($target->id, $subject)) {
                    throw new DomainException('این اتصال باعث ایجاد دور در درخت می‌شود.');
                }
                break;

            default:
                throw new DomainException('نوع اتصال نامعتبر است.');
        }
    }

    private function perform(Person $subject, string $type, Person $target, User $actor, array $payload): void
    {
        DB::transaction(function () use ($subject, $type, $target, $actor, $payload) {
            switch ($type) {
                case 'spouse':
                    $this->relatives->ensureMarriage($subject, $target, $actor, $payload);
                    break;

                case 'father':
                case 'mother':
                    $field = $type.'_id';
                    $subject->{$field} = $target->id;
                    $subject->save();
                    $otherField = $type === 'father' ? 'mother_id' : 'father_id';
                    if ($subject->{$otherField} && ($other = Person::find($subject->{$otherField}))) {
                        $this->relatives->ensureMarriage($target, $other, $actor);
                    }
                    break;

                case 'child':
                    $field = $subject->isMale() ? 'father_id' : 'mother_id';
                    $target->{$field} = $subject->id;
                    $target->save();
                    break;
            }

            $this->audit->log('link.'.$type, $subject, ['target' => $target->id, 'name' => $target->fullName()], $actor);
        });
    }
}
