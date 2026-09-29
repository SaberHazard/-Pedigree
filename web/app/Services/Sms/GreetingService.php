<?php

namespace App\Services\Sms;

use App\Exceptions\DomainException;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KinshipDegrees;
use App\Services\Occasions\BirthdayService;
use App\Services\Occasions\OccasionCalendar;
use App\Services\People\ProfileService;
use App\Services\Tree\RelationshipCalculator;
use App\Support\PersianText;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * پیامک تبریک (تولد، سالگرد ازدواج، نوروز، یلدا) از طرف اعضا با پنل پیامکی سایت.
 *
 * قوانین:
 *  - فقط اعضایی که پروفایلشان حداقل ۹۵٪ کامل است (با فهرست دقیق بخش‌های خالی در پیام خطا)
 *  - فقط در بازه هر مناسبت (مثلاً روز تولد یا فردای آن)، برای زندگانِ دارای موبایل
 *  - متن ثابت است: یکی از قالب‌هایی که فقط مدیر کل تعیین می‌کند؛ متغیرها (نام کامل با عنوان
 *    «دکتر/مهندس»، نسبت فامیلی محاسبه‌شده از شجره‌نامه و ...) خودکار پر می‌شوند.
 *    فرستنده فقط یک یادداشت خیلی کوتاه (مثل لقب خودش) اضافه می‌کند؛ بدون عدد و لینک.
 *  - هر نفر به هر نفر برای هر مناسبت سالی یک بار؛ سقف روزانه/ماهانه هر عضو، هر گیرنده و کل سایت
 *  - شماره گیرنده هرگز به فرستنده نشان داده نمی‌شود
 */
class GreetingService
{
    /** بیشترین طول یادداشت کوتاه فرستنده */
    public const NOTE_MAX = 30;

    public function __construct(
        private readonly SmsManager $sms,
        private readonly ProfileService $profiles,
        private readonly BirthdayService $birthdays,
        private readonly KinshipDegrees $degrees,
        private readonly AuditLogger $audit,
        private readonly RelationshipCalculator $relations,
        private readonly OccasionCalendar $calendar,
    ) {}

    /**
     * آیا این عضو می‌تواند از پنل پیامکی سایت پیامک بفرستد؟
     *
     * @return array{eligible: bool, reason: ?string, message: ?string, percent: ?int, required: int, missing: array, limits: array}
     */
    public function eligibility(User $user): array
    {
        $required = (int) config('pedigree.member_sms.min_completeness', 95);
        $result = ['eligible' => false, 'reason' => null, 'message' => null, 'percent' => null, 'required' => $required, 'missing' => [], 'limits' => $this->limits($user)];

        if (! $this->sms->messagesReady()) {
            return ['reason' => 'provider', 'message' => 'پنل پیامکی سایت هنوز تنظیم نشده است.'] + $result;
        }
        $person = $user->person;
        if ($person === null) {
            return ['reason' => 'no_profile', 'message' => 'حساب شما به پروفایلی در شجره‌نامه وصل نیست.'] + $result;
        }

        // درصد فقط از روی بخش‌هایی که مدیر کل برای پیامک الزامی کرده است
        $done = $this->profiles->smsCompleteness($person);
        $missing = array_map(fn ($k) => ['key' => $k, 'label' => ProfileService::SMS_CHECK_LABELS[$k] ?? $k], $done['missing']);
        $result = ['percent' => $done['percent'], 'missing' => $missing, 'checks' => array_map(fn ($k) => ProfileService::SMS_CHECK_LABELS[$k], $done['checks'])] + $result;
        if ($done['percent'] < $required) {
            $labels = implode('، ', array_column($missing, 'label'));

            return ['reason' => 'incomplete', 'message' => PersianText::toPersianDigits("برای ارسال پیامک تبریک از پنل سایت، پروفایل شما باید حداقل {$required}٪ کامل باشد (الان {$done['percent']}٪). این بخش‌ها را تکمیل کنید: {$labels}")] + $result;
        }

        return ['eligible' => true] + $result;
    }

    /** سقف‌ها و مقدار باقی‌مانده */
    public function limits(User $user): array
    {
        $today = $this->todayDate();
        $sentToday = SmsMessage::where('sender_user_id', $user->id)->where('status', SmsMessage::STATUS_SENT)->whereDate('sent_on', $today)->count();
        $sentMonth = SmsMessage::where('sender_user_id', $user->id)->where('status', SmsMessage::STATUS_SENT)
            ->whereDate('sent_on', '>=', now('Asia/Tehran')->subDays(29)->toDateString())->count();
        $daily = (int) config('pedigree.member_sms.daily_per_user', 10);
        $monthly = (int) config('pedigree.member_sms.monthly_per_user', 60);

        return [
            'daily' => $daily,
            'daily_left' => max(0, $daily - $sentToday),
            'monthly' => $monthly,
            'monthly_left' => max(0, $monthly - $sentMonth),
        ];
    }

    /** چرا نمی‌شود برای این مناسبت به این شخص پیامک تبریک داد؟ (null = می‌شود) */
    public function recipientProblem(User $sender, Person $recipient, string $occasion = 'birthday', ?int $inDays = null): ?string
    {
        if (! isset(SmsTemplates::OCCASIONS[$occasion])) {
            return 'مناسبت معتبر نیست.';
        }
        if (! OccasionCalendar::enabled($occasion)) {
            return 'تبریک این مناسبت را مدیر سایت خاموش کرده است.';
        }
        if ($sender->person_id === $recipient->id) {
            return 'به خودتان نمی‌توانید پیامک تبریک بفرستید.';
        }
        if ($recipient->is_deceased) {
            return 'این شخص در قید حیات نیست.';
        }
        if (! $recipient->phone_hash) {
            return 'شماره موبایل این شخص در شجره‌نامه ثبت نشده است.';
        }
        $label = SmsTemplates::OCCASIONS[$occasion]['label'];
        if ($occasion === 'birthday') {
            $inDays ??= $this->birthdays->around(1, 0)->firstWhere('person.id', $recipient->id)['in_days'] ?? null;
            if ($inDays === null || $inDays > 0 || $inDays < -1) {
                return 'پیامک تبریک فقط روز تولد (یا فردای آن) قابل ارسال است.';
            }
        } elseif ($occasion === 'anniversary') {
            if ($this->calendar->anniversaryFor($recipient) === null) {
                return 'پیامک تبریک سالگرد ازدواج فقط روز سالگرد (یا فردای آن) قابل ارسال است.';
            }
        } elseif (! $this->calendar->isOpen($occasion)) {
            return "پیامک تبریک {$label} فقط ".OccasionCalendar::WINDOWS[$occasion].' قابل ارسال است.';
        }
        $audience = OccasionCalendar::DEFINITIONS[$occasion]['audience'] ?? null;
        if ($audience !== null && ! self::fitsAudience($recipient, $audience)) {
            return $audience === 'mothers' ? 'تبریک روز مادر فقط برای مادران است.' : 'تبریک روز پدر فقط برای پدران است.';
        }
        if (SmsTemplates::active($occasion)->isEmpty()) {
            return "مدیر سایت برای {$label} متنی تعیین نکرده است.";
        }
        if ($this->alreadyGreeted($sender, $recipient, $occasion)) {
            return "امسال {$label} را با پیامک به این شخص تبریک گفته‌اید.";
        }

        return null;
    }

    /**
     * ارسال پیامک تبریک
     *
     * @throws DomainException
     */
    public function send(User $sender, Person $recipient, string $occasion = 'birthday', int|string|null $template = null, ?string $note = null, bool $auto = false): SmsMessage
    {
        $lock = Cache::lock('greeting-sms:'.$sender->id, 15);
        if (! $lock->get()) {
            throw new DomainException('پیامک قبلی شما در حال ارسال است؛ چند ثانیه صبر کنید.', 429);
        }
        try {
            return $this->sendLocked($sender, $recipient, $occasion, $template, $note, $auto);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(User $sender, Person $recipient, string $occasion, int|string|null $template, ?string $note, bool $auto): SmsMessage
    {
        $eligibility = $this->eligibility($sender);
        if (! $eligibility['eligible']) {
            throw new DomainException($eligibility['message'], 403, 'sms_'.$eligibility['reason']);
        }
        if ($problem = $this->recipientProblem($sender, $recipient, $occasion)) {
            throw new DomainException($problem, 422, 'sms_recipient');
        }
        $limits = $eligibility['limits'];
        if ($limits['daily_left'] <= 0 || $limits['monthly_left'] <= 0) {
            throw new DomainException(PersianText::toPersianDigits('به سقف پیامک‌های مجاز خودتان رسیده‌اید (روزانه '.$limits['daily'].' و ماهانه '.$limits['monthly'].').'), 429, 'sms_limit');
        }
        $today = $this->todayDate();
        $recipientToday = SmsMessage::where('recipient_person_id', $recipient->id)->where('status', SmsMessage::STATUS_SENT)->whereDate('sent_on', $today)->count();
        if ($recipientToday >= (int) config('pedigree.member_sms.daily_per_recipient', 5)) {
            throw new DomainException('امروز به اندازه کافی برای این شخص پیامک تبریک فرستاده شده است؛ با تماس یا پیام شخصی تبریک بگویید.', 429, 'sms_recipient_limit');
        }
        $globalToday = SmsMessage::where('status', SmsMessage::STATUS_SENT)->whereDate('sent_on', $today)->count();
        if ($globalToday >= (int) config('pedigree.member_sms.global_daily', 300)) {
            throw new DomainException('سقف روزانه پیامک‌های تبریک سایت پر شده است؛ فردا دوباره امتحان کنید.', 429, 'sms_global_limit');
        }

        $text = $this->compose($sender, $recipient, $occasion, $template, $note);
        $provider = $this->sms->messageDriverName();
        $record = new SmsMessage([
            'sender_user_id' => $sender->id,
            'recipient_person_id' => $recipient->id,
            'kind' => $occasion,
            'provider' => $provider,
            'auto' => $auto,
            'body' => $text,
            'phone_hint' => Phone::mask($recipient->phone),
            'sent_on' => $today,
        ]);

        try {
            $this->sms->messageDriver()->send((string) $recipient->phone, $text);
            $record->status = SmsMessage::STATUS_SENT;
        } catch (SmsException $e) {
            Log::warning('Greeting SMS failed: '.$e->getMessage());
            $record->status = SmsMessage::STATUS_FAILED;
            $record->error = mb_substr($e->getMessage(), 0, 250);
        }
        $record->save();
        $this->audit->log($auto ? 'sms.greeting_auto' : 'sms.greeting_sent', $recipient, ['status' => $record->status, 'message' => $record->id], $sender);

        if ($record->status !== SmsMessage::STATUS_SENT) {
            throw new DomainException('ارسال پیامک ممکن نشد (مشکل پنل پیامکی). لطفاً بعداً دوباره امتحان کنید.', 503, 'sms_failed');
        }

        return $record;
    }

    /**
     * متن نهایی پیامک از روی قالب مدیر کل، مثلاً:
     *   🎂 دکتر مریم احمدی عزیز، زادروزت خجسته باد! ...
     *   از طرف پسرخاله عزیزت، مهندس علی احمدی
     *   (یادداشت کوتاه فرستنده)
     *   نام سایت
     *
     * @throws DomainException
     */
    public function compose(User $sender, Person $recipient, string $occasion, int|string|null $template, ?string $note = null): string
    {
        $tpl = SmsTemplates::resolve($occasion, $template) ?? throw new DomainException('برای این مناسبت متنی تعیین نشده است.');
        $text = SmsTemplates::render($tpl->body, $this->values($sender, $recipient, $occasion, $this->cleanNote($note)));

        return mb_substr($text, 0, 800);
    }

    /**
     * مقدار متغیرهای قالب برای این فرستنده و گیرنده
     *
     * @return array<string, ?string>
     */
    public function values(User $sender, Person $recipient, string $occasion, ?string $note): array
    {
        $from = $sender->person;
        $relation = $from ? $this->relations->plainLabel($recipient, $from) : null;
        $fromName = self::safe($from ? self::displayName($from) : $sender->displayName());
        $digits = fn ($v) => $v === null || $v === '' ? null : PersianText::toPersianDigits((string) $v);
        [$year] = $this->birthdays->today();
        $birthYear = (int) substr((string) $recipient->birth_date, 0, 4);
        $mrMrs = fn (?Person $p) => match ($p?->gender) {
            'f' => 'خانم',
            'm' => 'آقای',
            default => null,
        };
        $education = $recipient->education_level ? config('pedigree.profile.education_levels.'.$recipient->education_level) : null;
        $anniversary = $occasion === 'anniversary' ? $this->calendar->anniversaryFor($recipient) : null;

        $values = [
            'to_full_name' => self::displayName($recipient),
            'to_first_name' => $recipient->first_name,
            'to_last_name' => $recipient->last_name,
            'to_title' => $recipient->displayTitle(),
            'to_mr_mrs' => $mrMrs($recipient),
            'to_nickname' => $recipient->nickname,
            'to_age' => $occasion === 'birthday' && $birthYear > 0 && $birthYear < $year ? $digits($year - $birthYear) : null,
            'to_birth_year' => $birthYear > 0 ? $digits($birthYear) : null,
            'to_birth_place' => $recipient->birth_place,
            'to_city' => $recipient->city,
            'to_occupation' => $recipient->occupation,
            'to_education' => is_string($education) ? trim(preg_replace('/\s*\(.*\)\s*/u', ' ', $education) ?? $education) : null,
            'to_father_name' => $recipient->father?->first_name,
            'to_mother_name' => $recipient->mother?->first_name,

            'from_full_name' => $fromName,
            'from_first_name' => $from?->first_name ?? $sender->displayName(),
            'from_last_name' => $from?->last_name,
            'from_title' => $from?->displayTitle(),
            'from_mr_mrs' => $mrMrs($from),
            'from_nickname' => $from?->nickname,

            'relation' => $relation,
            'from_line' => 'از طرف '.($relation ? $relation.' عزیزت، ' : '').$fromName,
            'from_line_formal' => 'از طرف '.($relation ? $relation.' شما، ' : '').$fromName,

            'years_married' => $anniversary ? $digits($anniversary['years']) : null,
            'spouse_name' => $anniversary ? self::displayName($anniversary['spouse']) : null,
            'new_year' => $digits($this->calendar->newYear()),
            'note' => $note,
            'site_name' => (string) config('pedigree.site_name'),
            'occasion' => SmsTemplates::OCCASIONS[$occasion]['label'] ?? null,
            'today' => $digits($this->calendar->todayText()),
            'year' => $digits($year),
        ];

        // هر مقداری که از پروفایل‌ها می‌آید پاک‌سازی می‌شود تا کسی با گذاشتن لینک یا شماره در نام/لقب خود،
        // از خط رسمی سایت پیامک فریبنده نفرستد
        foreach ($values as $key => $value) {
            if (! in_array($key, ['from_line', 'from_line_formal', 'site_name', 'note', 'occasion'], true)) {
                $values[$key] = self::safe($value);
            }
        }

        return $values;
    }

    /**
     * پاک‌سازی مقدار متغیر پیامک: بدون لینک، دامنه، شناسه @، شماره تلفن یا کد (۵ رقم پشت سر هم)، نویسه‌های کنترلی؛ حداکثر ۶۰ نویسه
     */
    public static function safe(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $value) ?? '';
        // هر «چیز.دامنه» با هر پسوند لاتین (evil.ru، bit.ly)، حتی به شکل‌های پنهان‌شده «evil[.]ru»، «evil (.) ru» یا «evil نقطه ru»
        $value = preg_replace('~(\b[a-z][a-z0-9+.-]{1,15}:/+\S*|www\.\S*|\bt\.me/\S*|@[\w.]{2,}|\b[\w-]{2,}\s*(?:\.|\[\.\]|\(\.\)|．|。|٫|\s(?:dot|نقطه)\s)\s*[a-z]{2,24}\b\S*)~iu', '', $value) ?? '';
        $value = preg_replace('/[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}][0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}\s\-]{3,}[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', '', $value) ?? '';
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 60);
    }

    /** نام کامل با عنوان علمی: «مهندس مریم احمدی» */
    public static function displayName(Person $person): string
    {
        return trim(implode(' ', array_filter([$person->honorific(), $person->first_name, $person->last_name])));
    }

    /**
     * یادداشت خیلی کوتاه فرستنده (مثل لقب یا «از طرف خانواده رضایی»):
     * یک خط، حداکثر ۳۰ نویسه، بدون لینک و بدون عدد (تا شماره تلفن یا کد در پیامک نرود)
     *
     * @throws DomainException
     */
    public function cleanNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        $note = preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $note) ?? '';
        $note = trim(preg_replace('/\s+/u', ' ', $note) ?? '');
        if ($note === '') {
            return null;
        }
        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new DomainException(PersianText::toPersianDigits('یادداشت کوتاه حداکثر '.self::NOTE_MAX.' نویسه باشد.'));
        }
        if (preg_match('/[0-9\x{06F0}-\x{06F9}\x{0660}-\x{0669}]/u', $note)) {
            throw new DomainException('در یادداشت کوتاه عدد نگذارید (برای جلوگیری از ارسال شماره یا کد).');
        }
        if (preg_match('~(https?:|www\.|\.[a-z]{2,}\b|@|/)~iu', $note)) {
            throw new DomainException('لینک و آدرس در پیامک تبریک مجاز نیست.');
        }

        return $note;
    }

    /** @return array<int, array{id:int, title:string, display:string}> قالب‌های فعال یک مناسبت برای نمایش به اعضا */
    public static function templates(string $occasion): array
    {
        return SmsTemplates::active($occasion)->map(fn ($t) => [
            'id' => $t->id,
            'title' => $t->title,
            'display' => SmsTemplates::display($t->body),
        ])->values()->all();
    }

    /** روز مادر فقط برای زنانی که در درخت فرزند دارند و روز پدر فقط برای مردانِ دارای فرزند */
    public static function fitsAudience(Person $person, string $audience): bool
    {
        return match ($audience) {
            'mothers' => $person->gender === Person::FEMALE && Person::query()->where('mother_id', $person->id)->exists(),
            'fathers' => $person->gender === Person::MALE && Person::query()->where('father_id', $person->id)->exists(),
            default => true,
        };
    }

    public function alreadyGreeted(User $sender, Person $recipient, string $occasion = 'birthday'): bool
    {
        return SmsMessage::where('sender_user_id', $sender->id)->where('recipient_person_id', $recipient->id)
            ->where('kind', $occasion)->where('status', SmsMessage::STATUS_SENT)
            ->whereDate('sent_on', '>=', now('Asia/Tehran')->subDays(300)->toDateString())
            ->exists();
    }

    /** تنظیم تبریک خودکار کاربر */
    public function autoPreferences(User $user): array
    {
        $prefs = (array) (($user->preferences ?? [])['birthday_sms'] ?? []);
        $note = is_string($prefs['note'] ?? null) ? $prefs['note'] : null;

        return [
            'auto' => (bool) ($prefs['auto'] ?? false),
            'scope' => in_array($prefs['scope'] ?? null, ['all', 'd4', 'd3', 'd2', 'd1'], true) ? $prefs['scope'] : 'd1',
            'template' => SmsTemplates::resolve('birthday', $prefs['template'] ?? null)?->id,
            'note' => $note,
        ];
    }

    /** دامنه مؤثر: کوچک‌ترِ انتخاب کاربر و سقف مدیر */
    public function effectiveScope(string $scope): string
    {
        $max = (string) config('pedigree.member_sms.auto_max_scope', 'd2');
        $rank = fn (string $s) => KinshipDegrees::levelMax($s) ?? 99;

        return $rank($scope) <= $rank($max) ? $scope : $max;
    }

    /**
     * تبریک خودکار امروز از طرف اعضایی که آن را روشن کرده‌اند
     *
     * @return array{sent: int, skipped: int, failed: int}
     */
    public function autoSendToday(): array
    {
        $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];
        $todays = $this->birthdays->todays()->filter(fn ($row) => (bool) $row['person']->phone_hash);
        if ($todays->isEmpty()) {
            return $stats;
        }

        $senders = User::query()->with('person')->where('status', User::STATUS_ACTIVE)->whereNotNull('person_id')->whereNotNull('preferences')->get()
            ->filter(fn (User $u) => $this->autoPreferences($u)['auto']);

        foreach ($senders as $sender) {
            $prefs = $this->autoPreferences($sender);
            $max = KinshipDegrees::levelMax($this->effectiveScope($prefs['scope']));
            if (! $this->eligibility($sender)['eligible']) {
                $stats['skipped']++;

                continue;
            }
            foreach ($todays as ['person' => $person]) {
                if ($max !== null) {
                    $degree = $this->degrees->degree($sender->person, $person);
                    if ($degree === null || $degree > $max) {
                        continue;
                    }
                }
                if ($this->recipientProblem($sender, $person, 'birthday', 0) !== null) {
                    continue;
                }
                try {
                    $this->send($sender, $person, 'birthday', $prefs['template'], $prefs['note'], true);
                    $stats['sent']++;
                } catch (DomainException) {
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }

    private function todayDate(): string
    {
        return now('Asia/Tehran')->toDateString();
    }
}
