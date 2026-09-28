<?php

namespace App\Services\Sms;

use App\Exceptions\DomainException;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KinshipDegrees;
use App\Services\Occasions\BirthdayService;
use App\Services\People\ProfileService;
use App\Services\Tree\RelationshipCalculator;
use App\Support\PersianText;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * پیامک تبریک تولد از طرف اعضا با پنل پیامکی سایت.
 *
 * قوانین:
 *  - فقط اعضایی که پروفایلشان حداقل ۹۵٪ کامل است (با فهرست دقیق بخش‌های خالی در پیام خطا)
 *  - فقط برای کسی که امروز (یا دیروز) تولدش است، زنده است و موبایل دارد
 *  - متن ثابت است (یکی از چند قالب آماده): نام کامل گیرنده و فرستنده با عنوان «دکتر/مهندس» و
 *    نسبت فامیلی فرستنده با گیرنده که از روی شجره‌نامه حساب می‌شود («از طرف پسرخاله عزیزت، مهندس علی احمدی»).
 *    فرستنده فقط یک یادداشت خیلی کوتاه (مثل لقب خودش) اضافه می‌کند؛ بدون عدد و لینک.
 *  - هر نفر به هر نفر سالی یک بار؛ سقف روزانه/ماهانه هر عضو، سقف روزانه هر گیرنده و کل سایت
 *  - شماره گیرنده هرگز به فرستنده نشان داده نمی‌شود
 */
class GreetingService
{
    /** قالب‌های ثابت: {to} = نام کامل گیرنده با عنوان؛ formal = خطاب «شما» */
    public const TEMPLATES = [
        'warm' => ['text' => '🎂 {to} عزیز، زادروزت خجسته باد! سالی سرشار از سلامتی و شادی برایت آرزومندم.', 'formal' => false],
        'short' => ['text' => '🌹 {to} عزیز، تولدت مبارک! همیشه سلامت و شاد باشی.', 'formal' => false],
        'respect' => ['text' => '🎉 {to} گرامی، زادروزتان مبارک! سایه‌تان همیشه بر سر خانواده باشد.', 'formal' => true],
        'success' => ['text' => '🎈 {to} عزیز، تولدت مبارک! به امید سالی پر از موفقیت و خبرهای خوب.', 'formal' => false],
    ];

    public const DEFAULT_TEMPLATE = 'warm';

    /** بیشترین طول یادداشت کوتاه فرستنده */
    public const NOTE_MAX = 30;

    public function __construct(
        private readonly SmsManager $sms,
        private readonly ProfileService $profiles,
        private readonly BirthdayService $birthdays,
        private readonly KinshipDegrees $degrees,
        private readonly AuditLogger $audit,
        private readonly RelationshipCalculator $relations,
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

        $done = $this->profiles->completenessOf($person);
        $missing = array_map(fn ($k) => ['key' => $k, 'label' => ProfileService::COMPLETENESS_LABELS[$k] ?? $k], $done['missing']);
        $result = ['percent' => $done['percent'], 'missing' => $missing] + $result;
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

    /** چرا نمی‌شود به این شخص پیامک تبریک داد؟ (null = می‌شود) */
    public function recipientProblem(User $sender, Person $recipient, ?int $inDays = null): ?string
    {
        if ($sender->person_id === $recipient->id) {
            return 'به خودتان نمی‌توانید پیامک تبریک بفرستید.';
        }
        if ($recipient->is_deceased) {
            return 'این شخص در قید حیات نیست.';
        }
        if (! $recipient->phone_hash) {
            return 'شماره موبایل این شخص در شجره‌نامه ثبت نشده است.';
        }
        $inDays ??= $this->birthdays->around(1, 0)->firstWhere('person.id', $recipient->id)['in_days'] ?? null;
        if ($inDays === null || $inDays > 0 || $inDays < -1) {
            return 'پیامک تبریک فقط روز تولد (یا فردای آن) قابل ارسال است.';
        }
        if ($this->alreadyGreeted($sender, $recipient)) {
            return 'امسال تولد این شخص را با پیامک تبریک گفته‌اید.';
        }

        return null;
    }

    /**
     * ارسال پیامک تبریک
     *
     * @throws DomainException
     */
    public function send(User $sender, Person $recipient, string $template = self::DEFAULT_TEMPLATE, ?string $note = null, bool $auto = false): SmsMessage
    {
        $lock = Cache::lock('greeting-sms:'.$sender->id, 15);
        if (! $lock->get()) {
            throw new DomainException('پیامک قبلی شما در حال ارسال است؛ چند ثانیه صبر کنید.', 429);
        }
        try {
            return $this->sendLocked($sender, $recipient, $template, $note, $auto);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(User $sender, Person $recipient, string $template, ?string $note, bool $auto): SmsMessage
    {
        $eligibility = $this->eligibility($sender);
        if (! $eligibility['eligible']) {
            throw new DomainException($eligibility['message'], 403, 'sms_'.$eligibility['reason']);
        }
        if ($problem = $this->recipientProblem($sender, $recipient)) {
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

        $text = $this->compose($sender, $recipient, $template, $note);
        $provider = $this->sms->messageDriverName();
        $record = new SmsMessage([
            'sender_user_id' => $sender->id,
            'recipient_person_id' => $recipient->id,
            'kind' => 'birthday',
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
     * متن نهایی پیامک (ثابت):
     *   🎂 مهندس مریم احمدی عزیز، زادروزت خجسته باد! ...
     *   از طرف پسرخاله عزیزت، دکتر علی احمدی
     *   (یادداشت کوتاه فرستنده)
     *   نام سایت
     *
     * @throws DomainException
     */
    public function compose(User $sender, Person $recipient, string $template = self::DEFAULT_TEMPLATE, ?string $note = null): string
    {
        $def = self::TEMPLATES[$template] ?? throw new DomainException('قالب پیامک معتبر نیست.');
        $note = $this->cleanNote($note);
        $from = $sender->person ? self::displayName($sender->person) : $sender->displayName();
        $relation = $sender->person ? $this->relations->plainLabel($recipient, $sender->person) : null;

        $lines = [str_replace('{to}', self::displayName($recipient), $def['text'])];
        $lines[] = $relation
            ? 'از طرف '.$relation.($def['formal'] ? ' شما' : ' عزیزت').'، '.$from
            : 'از طرف '.$from;
        if ($note !== null) {
            $lines[] = $note;
        }
        $lines[] = (string) config('pedigree.site_name');

        return implode("\n", $lines);
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

    /** @return array<int, array{id:string, text:string}> قالب‌ها برای نمایش */
    public static function templates(): array
    {
        $out = [];
        foreach (self::TEMPLATES as $id => $def) {
            $out[] = ['id' => $id, 'text' => $def['text'], 'formal' => $def['formal']];
        }

        return $out;
    }

    public function alreadyGreeted(User $sender, Person $recipient): bool
    {
        return SmsMessage::where('sender_user_id', $sender->id)->where('recipient_person_id', $recipient->id)
            ->where('kind', 'birthday')->where('status', SmsMessage::STATUS_SENT)
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
            'template' => isset(self::TEMPLATES[$prefs['template'] ?? '']) ? $prefs['template'] : self::DEFAULT_TEMPLATE,
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
                if ($this->recipientProblem($sender, $person, 0) !== null) {
                    continue;
                }
                try {
                    $this->send($sender, $person, $prefs['template'], $prefs['note'], true);
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
