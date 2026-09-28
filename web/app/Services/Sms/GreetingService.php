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
use App\Support\PersianText;
use App\Support\Phone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * پیامک تبریک تولد از طرف اعضا با پنل پیامکی سایت.
 *
 * قوانین (همه از پنل مدیریت قابل تغییر):
 *  - فقط اعضایی که پروفایلشان حداقل ۹۵٪ کامل است (با فهرست دقیق بخش‌های خالی در پیام خطا)
 *  - فقط برای کسی که امروز (یا دیروز) تولدش است، زنده است، موبایل دارد و پیامک تبریک را رد نکرده
 *  - هر نفر به هر نفر سالی یک بار؛ سقف روزانه/ماهانه هر عضو، سقف روزانه هر گیرنده و کل سایت
 *  - لینک در متن مجاز نیست (جلوگیری از فیشینگ) و نام فرستنده و نام سایت همیشه پایین پیامک می‌آید
 *  - شماره گیرنده هرگز به فرستنده نشان داده نمی‌شود
 */
class GreetingService
{
    public const DEFAULT_TEMPLATE = '{name} عزیز، زادروزت خجسته باد! سالی پر از سلامتی و شادی برایت آرزو می‌کنم.';

    public function __construct(
        private readonly SmsManager $sms,
        private readonly ProfileService $profiles,
        private readonly BirthdayService $birthdays,
        private readonly KinshipDegrees $degrees,
        private readonly AuditLogger $audit,
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

        if (! config('pedigree.member_sms.enabled', true)) {
            return ['reason' => 'disabled', 'message' => 'ارسال پیامک تبریک از طرف مدیر سایت غیرفعال است.'] + $result;
        }
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
            'max_length' => (int) config('pedigree.member_sms.max_length', 250),
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
        if (! $recipient->accept_greeting_sms) {
            return 'این شخص دریافت پیامک تبریک از اعضا را خاموش کرده است.';
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
    public function send(User $sender, Person $recipient, string $message, bool $auto = false): SmsMessage
    {
        $lock = Cache::lock('greeting-sms:'.$sender->id, 15);
        if (! $lock->get()) {
            throw new DomainException('پیامک قبلی شما در حال ارسال است؛ چند ثانیه صبر کنید.', 429);
        }
        try {
            return $this->sendLocked($sender, $recipient, $message, $auto);
        } finally {
            $lock->release();
        }
    }

    private function sendLocked(User $sender, Person $recipient, string $message, bool $auto): SmsMessage
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

        $text = $this->compose($sender, $recipient, $message);
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

    /** متن نهایی: متن کاربر + نام فرستنده + نام سایت */
    public function compose(User $sender, Person $recipient, string $message): string
    {
        $message = $this->cleanMessage($message, $recipient);
        $signature = '— '.($sender->person?->fullName() ?? $sender->displayName())."\n".config('pedigree.site_name');

        return $message."\n".$signature;
    }

    /**
     * یکدست‌سازی و بررسی متن (جایگزینی {name} با نام گیرنده)
     *
     * @throws DomainException
     */
    public function cleanMessage(string $message, ?Person $recipient = null): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", $message);
        $message = preg_replace('/[\x00-\x08\x0B-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $message) ?? '';
        $message = trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $message) ?? '') ?? '');
        if ($recipient) {
            $message = str_replace('{name}', $recipient->first_name, $message);
        }
        if ($message === '') {
            throw new DomainException('متن پیامک خالی است.');
        }
        $max = (int) config('pedigree.member_sms.max_length', 250);
        if (mb_strlen($message) > $max) {
            throw new DomainException(PersianText::toPersianDigits("متن پیامک حداکثر {$max} نویسه باشد."));
        }
        if (preg_match('~(https?://|www\.|[a-z0-9-]+\.(ir|com|net|org|me|io|co|app|xyz|info)\b|t\.me/)~iu', $message)) {
            throw new DomainException('لینک و آدرس سایت در پیامک تبریک مجاز نیست.');
        }

        return $message;
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

        return [
            'auto' => (bool) ($prefs['auto'] ?? false),
            'scope' => in_array($prefs['scope'] ?? null, ['all', 'd4', 'd3', 'd2', 'd1'], true) ? $prefs['scope'] : 'd1',
            'template' => is_string($prefs['template'] ?? null) && $prefs['template'] !== '' ? $prefs['template'] : self::DEFAULT_TEMPLATE,
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
        if (! config('pedigree.member_sms.enabled', true) || ! config('pedigree.member_sms.auto_enabled', true)) {
            return $stats;
        }
        $todays = $this->birthdays->todays()->filter(fn ($row) => $row['person']->phone_hash && $row['person']->accept_greeting_sms);
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
                    $this->send($sender, $person, $prefs['template'], true);
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
