<?php

namespace App\Services\Access;

use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Services\Kinship;
use App\Services\KinshipDegrees;
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
 *  ۶. سازنده یک پروفایل تا وقتی صاحبش وارد سیستم نشده (و حداکثر چند ساعت پس از ساخت)، حق ویرایش دارد.
 *  ۷. اطلاعات حساس (موبایل، کد ملی، رمز) شخصی که حساب فعال دارد فقط دست خودش است.
 *  ۸. موبایل و راه‌های ارتباطی: پیش‌فرض برای همه اعضای خاندان؛ خود شخص می‌تواند به بستگان تا درجه ۴/۳/۲/۱ یا فقط خودش محدود کند.
 *  ۹. نشانی و موقعیت خانه: پیش‌فرض بستگان درجه یک؛ خود شخص می‌تواند گسترده‌تر یا محدودتر کند.
 *     (درجه‌ها در KinshipDegrees تعریف شده‌اند؛ مدیر و خود شخص همیشه می‌بینند.)
 */
class PersonAccess
{
    /** @var array<string, bool> کش نتایج در طول یک درخواست */
    private array $cache = [];

    public function __construct(
        private readonly Kinship $kinship,
        private readonly KinshipDegrees $degrees,
    ) {}

    /** دیدن اطلاعات عمومی شخص (نام، تاریخ‌ها، عکس‌های تأییدشده) */
    public function canView(?User $user, Person $target): bool
    {
        if ($user === null) {
            return false;
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

        return $this->creatorMayEdit($user->id, $target);
    }

    /**
     * ویرایش مستقیم یک ازدواج (وضعیت، تاریخ ازدواج و طلاق):
     *  - هر کس که یکی از دو همسر را ویرایش می‌کند (خود شخص، بستگان درجه یک، مدیر ...)
     *  - اگر هیچ‌کدام از دو همسر و بستگان درجه یکشان حساب فعال ندارند، بستگان درجه دو هم
     */
    public function canEditMarriage(?User $user, Marriage $marriage): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }
        $spouses = array_values(array_filter([$marriage->husband, $marriage->wife]));
        foreach ($spouses as $spouse) {
            if ($this->canEdit($user, $spouse)) {
                return true;
            }
        }
        if ($user->person === null) {
            return false;
        }
        foreach ($spouses as $spouse) {
            if ($this->hasFirstDegreeMember($spouse)) {
                return false;
            }
        }
        foreach ($spouses as $spouse) {
            $degree = $this->degrees->relativeDegree($spouse, $user->person);
            if ($degree !== null && $degree <= 2) {
                return true;
            }
        }

        return false;
    }

    /** آیا خود شخص یا یکی از بستگان درجه یکش (والدین، فرزندان، خواهر و برادر، همسران) حساب فعال دارد؟ */
    public function hasFirstDegreeMember(Person $person): bool
    {
        $ids = array_merge(
            [$person->id],
            $this->kinship->parentsOf($person)->pluck('id')->all(),
            $this->kinship->childrenOf($person)->pluck('id')->all(),
            $this->kinship->siblingsOf($person)->pluck('id')->all(),
            $this->kinship->spousesOf($person)->pluck('id')->all(),
        );

        return User::query()->whereIn('person_id', $ids)->where('status', User::STATUS_ACTIVE)->whereNotNull('last_login_at')->exists();
    }

    /** سازنده پروفایلِ ادعانشده، در بازه مجاز پس از ساخت */
    private function creatorMayEdit(int $userId, Person $target): bool
    {
        if (! config('pedigree.permissions.creator_can_edit_unclaimed') || $target->created_by !== $userId || $target->hasActiveAccount()) {
            return false;
        }
        $hours = (int) config('pedigree.permissions.creator_edit_hours', 72);

        return $hours <= 0 || ($target->created_at !== null && $target->created_at->gt(now()->subHours($hours)));
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

    /** نشانی دقیق، کد پستی و موقعیت خانه روی نقشه */
    public function canViewLocation(?User $user, Person $target): bool
    {
        return $this->allowedByLevel($user, $target, $target->location_visibility ?: 'd1');
    }

    /** موبایل، ایمیل، تلفن ثابت و شماره واتس‌اپ */
    public function canViewContact(?User $user, Person $target): bool
    {
        return $this->allowedByLevel($user, $target, $target->contact_visibility ?: 'all');
    }

    /** تغییر تنظیم «چه کسانی شماره/نشانی را ببینند» فقط با خود شخص (یا مدیر، یا ویرایشگرِ پروفایل بدون حساب) */
    public function canManagePrivacy(?User $user, Person $target): bool
    {
        return $this->canManageSensitive($user, $target);
    }

    /**
     * اجازه دیدن طبق سطح: all | d4 | d3 | d2 | d1 | self
     * خود شخص، مدیر و کسی که اطلاعات حساس این پروفایل (بدون حساب) را مدیریت می‌کند همیشه می‌بینند.
     */
    private function allowedByLevel(?User $user, Person $target, string $level): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }
        if ($level === 'all' || $user->isAdmin() || $user->person_id === $target->id) {
            return true;
        }
        if ($this->canManageSensitive($user, $target)) {
            return true;
        }
        $max = KinshipDegrees::levelMax($level);
        if ($max === null) {
            return true;
        }
        if ($max === 0 || $user->person === null) {
            return false;
        }
        // دید صاحب شماره ملاک است: «بستگان درجه یکِ من» شامل عروس و داماد من هم می‌شود
        $degree = $this->degrees->relativeDegree($target, $user->person);

        return $degree !== null && $degree <= $max;
    }

    /** تاریخچه تغییرات پروفایل (چه کسی چه چیزی را اضافه/حذف/ویرایش کرد) */
    public function canViewHistory(?User $user, Person $target): bool
    {
        if ($user === null || ! $user->isActive()) {
            return false;
        }

        return config('pedigree.permissions.history_public', true)
            || $user->isAdmin() || $user->person_id === $target->id || $this->canEdit($user, $target);
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

        if (! $target->is_locked && $target->created_by && $this->creatorMayEdit($target->created_by, $target)) {
            $creator = User::query()->with('person')->find($target->created_by);
            if ($creator && $creator->isActive()) {
                $users->push($creator);
            }
        }

        return $users->unique('id')->values();
    }

    /** مجموعه دسترسی‌ها برای ارسال به رابط کاربری */
    /** بستگان تا درجه سه (که ویرایش مستقیم ندارند) می‌توانند ویرایش پیشنهاد دهند */
    public function canSuggest(?User $user, Person $target): bool
    {
        if ($user === null || ! $user->isActive() || $user->person === null || $target->is_locked || $user->person_id === $target->id) {
            return false;
        }
        $degree = $this->degrees->relativeDegree($target, $user->person);

        return $degree !== null && $degree >= 1 && $degree <= 3;
    }

    public function summary(?User $user, Person $target): array
    {
        $edit = $this->canEdit($user, $target);

        return [
            'edit' => $edit,
            // بستگان درجه دو و سه: ویرایش به صورت پیشنهاد (با تأیید مدیر)
            'suggest' => ! $edit && $this->canSuggest($user, $target),
            'sensitive' => $this->canManageSensitive($user, $target),
            'upload' => $this->canUploadMedia($user, $target),
            'delete' => $this->canDelete($user, $target),
            'admin' => (bool) $user?->isAdmin(),
            'history' => $this->canViewHistory($user, $target),
            'privacy' => $this->canManagePrivacy($user, $target),
            'comment' => $user !== null && $user->isActive() && (bool) config('pedigree.comments.enabled', true),
            'rate' => $user !== null && $user->isActive() && $user->person_id !== $target->id && (bool) config('pedigree.ratings.enabled', true),
        ];
    }
}
