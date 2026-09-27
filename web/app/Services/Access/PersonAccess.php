<?php

namespace App\Services\Access;

use App\Models\Person;
use App\Models\User;
use App\Services\Kinship;
use Illuminate\Support\Collection;

/**
 * قوانین دسترسی به اشخاص.
 *
 * خلاصه قوانین (قابل تنظیم در config/pedigree.php):
 *  ۱. مدیر کل و مدیر به همه چیز دسترسی دارند.
 *  ۲. هر کس پروفایل خودش را ویرایش می‌کند.
 *  ۳. اگر پروفایل «قفل» باشد، فقط خود شخص و مدیر.
 *  ۴. بستگان درجه یک (پدر/مادر، فرزند، خواهر/برادر، همسر) حق ویرایش دارند.
 *  ۵. اگر شخص درگذشته باشد، نوادگانش (نوه، نتیجه ...) هم حق ویرایش دارند.
 *  ۶. سازنده یک پروفایل تا وقتی صاحبش وارد سیستم نشده، حق ویرایش دارد.
 *  ۷. اطلاعات حساس (موبایل، کد ملی، رمز) شخصی که حساب فعال دارد فقط دست خودش است.
 */
class PersonAccess
{
    /** @var array<string, bool> کش نتایج در طول یک درخواست */
    private array $cache = [];

    public function __construct(private readonly Kinship $kinship) {}

    /** دیدن اطلاعات عمومی شخص (نام، تاریخ‌ها، عکس‌های تأییدشده) */
    public function canView(?User $user, Person $target): bool
    {
        if ($user === null) {
            return (bool) config('pedigree.guest_view');
        }

        return $user->isActive();
    }

    public function canEdit(?User $user, Person $target): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        $key = 'edit:'.$user->id.':'.$target->id;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        return $this->cache[$key] = $this->resolveEdit($user, $target);
    }

    private function resolveEdit(User $user, Person $target): bool
    {
        $viewer = $user->person;
        if ($viewer === null) {
            return false;
        }
        if ($viewer->id === $target->id) {
            return true;
        }
        if ($target->is_locked) {
            return false;
        }

        $relation = $this->kinship->directRelation($viewer, $target);
        if ($relation !== null && in_array($relation, config('pedigree.permissions.editor_relations', []), true)) {
            return true;
        }

        if ($target->is_deceased) {
            $depth = (int) config('pedigree.permissions.deceased_descendant_depth', 6);
            if ($depth > 0 && $this->kinship->isAncestorOf($target->id, $viewer, $depth)) {
                return true;
            }
        }

        return config('pedigree.permissions.creator_can_edit_unclaimed')
            && $target->created_by === $user->id
            && ! $target->hasActiveAccount();
    }

    /**
     * مدیریت اطلاعات حساس و حساب کاربری (موبایل، کد ملی، رمز، قفل پروفایل)
     */
    public function canManageSensitive(?User $user, Person $target): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }
        if ($user->isAdmin() || $user->person_id === $target->id) {
            return true;
        }
        if ($target->hasActiveAccount()) {
            return false;
        }

        return $this->canEdit($user, $target);
    }

    /** دیدن اطلاعات حساس (کد ملی، موبایل، شناسنامه) */
    public function canViewSensitive(?User $user, Person $target): bool
    {
        return $this->canManageSensitive($user, $target);
    }

    /** آپلود عکس و ویدیو برای یک شخص */
    public function canUploadMedia(?User $user, Person $target): bool
    {
        return $this->canEdit($user, $target);
    }

    /**
     * حذف شخص: فقط مدیر، یا سازنده‌ای که هنوز صاحب پروفایل وارد نشده و
     * شخص فرزندی در سیستم ندارد (تا شاخه‌ای یتیم نشود).
     */
    public function canDelete(?User $user, Person $target): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if ($user->person_id === $target->id) {
            return false;
        }

        return $target->created_by === $user->id
            && ! $target->hasActiveAccount()
            && ! $target->childrenQuery()->exists();
    }

    /**
     * کاربرانی که حق ویرایش این شخص را دارند (بدون احتساب مدیران).
     * برای ارسال اعلان و تأیید درخواست‌های اتصال استفاده می‌شود.
     *
     * @return Collection<int, User>
     */
    public function editorsOf(Person $target): Collection
    {
        $personIds = [$target->id];

        if (! $target->is_locked) {
            $relations = config('pedigree.permissions.editor_relations', []);
            $map = ['parent' => 'parents', 'child' => 'children', 'sibling' => 'siblings', 'spouse' => 'spouses'];
            foreach ($map as $relation => $group) {
                if (in_array($relation, $relations, true)) {
                    array_push($personIds, ...$this->kinship->group($target, $group)->pluck('id')->all());
                }
            }
            if ($target->is_deceased) {
                $depth = (int) config('pedigree.permissions.deceased_descendant_depth', 6);
                array_push($personIds, ...$this->kinship->descendantIds($target, $depth));
            }
        }

        $users = User::query()
            ->with('person')
            ->whereIn('person_id', array_unique($personIds))
            ->where('status', User::STATUS_ACTIVE)
            ->get();

        if (! $target->is_locked && $target->created_by && config('pedigree.permissions.creator_can_edit_unclaimed') && ! $target->hasActiveAccount()) {
            $creator = User::query()->with('person')->find($target->created_by);
            if ($creator && $creator->isActive()) {
                $users->push($creator);
            }
        }

        return $users->unique('id')->values();
    }

    /** مجموعه دسترسی‌ها برای ارسال به رابط کاربری */
    public function summary(?User $user, Person $target): array
    {
        return [
            'edit' => $this->canEdit($user, $target),
            'sensitive' => $this->canManageSensitive($user, $target),
            'upload' => $this->canUploadMedia($user, $target),
            'delete' => $this->canDelete($user, $target),
            'admin' => (bool) $user?->isAdmin(),
        ];
    }
}
