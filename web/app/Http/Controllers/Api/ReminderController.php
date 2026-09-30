<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Reminder;
use App\Models\ReminderSetting;
use App\Services\Reminders\ReminderService;
use App\Support\Jalali;
use App\Support\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * هشدارها و یادآورهای شخصی و تنظیم یادآوری مناسبت‌ها (هر کاربر فقط مال خودش).
 */
class ReminderController extends Controller
{
    public function __construct(private readonly ReminderService $reminders) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = Reminder::query()->where('user_id', $user->id)
            ->orderByDesc('active')->orderByRaw('next_at IS NULL, next_at')->orderByDesc('id')
            ->limit(Reminder::MAX_PER_USER)->get();
        $setting = ReminderSetting::query()->find($user->id);

        return response()->json([
            'data' => $list->map(fn (Reminder $r) => $this->reminders->present($r)),
            'settings' => [
                'enabled' => (bool) $setting?->enabled,
                'time' => $setting->time ?? '08:00',
                'categories' => $setting->categories ?? ['birthday', 'anniversary', 'official'],
                'days_before' => $setting->days_before ?? [0, 1],
                'degree' => $setting->degree ?? 2,
            ],
            'options' => [
                'repeats' => Reminder::REPEATS,
                'before' => Reminder::BEFORE,
                'categories' => ReminderSetting::CATEGORIES,
                'days_before' => ReminderSetting::DAYS_BEFORE,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (Reminder::query()->where('user_id', $user->id)->count() >= Reminder::MAX_PER_USER) {
            throw new DomainException('حداکثر '.PersianText::toPersianDigits((string) Reminder::MAX_PER_USER).' هشدار می‌توانید داشته باشید؛ قدیمی‌ها را پاک کنید.');
        }
        $reminder = new Reminder($this->validated($request));
        $reminder->user_id = $user->id;
        $this->reminders->refresh($reminder);

        return response()->json(['data' => $this->reminders->present($reminder), 'message' => $this->savedMessage($reminder)], 201);
    }

    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
        $reminder->fill($this->validated($request, $reminder));
        $reminder->last_sent_at = null;
        $this->reminders->refresh($reminder);

        return response()->json(['data' => $this->reminders->present($reminder), 'message' => $this->savedMessage($reminder)]);
    }

    public function destroy(Request $request, Reminder $reminder): JsonResponse
    {
        abort_unless($reminder->user_id === $request->user()->id, 404);
        $reminder->delete();

        return response()->json(['message' => 'هشدار حذف شد.']);
    }

    /** زنگ‌های پیش رو برای هشدار محلی گوشی (native=1) یا زنگ صفحه باز */
    public function upcoming(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:60'], 'native' => ['nullable', 'boolean']]);
        $user = $request->user();
        $days = (int) ($data['days'] ?? 30);
        $max = $request->boolean('native') ? 60 : 40;
        $items = $this->reminders->upcoming($user, $days, $max);
        if ($request->boolean('native')) {
            // اپ این کاربر همین زنگ‌ها را خودش سر ثانیه می‌زند؛ برای آن‌ها پوش تکراری فرستاده نمی‌شود.
            // اگر فهرست به سقف رسیده باشد، فقط تا آخرین زنگ فهرست «پوشش داده شده» است.
            $prefs = (array) ($user->preferences ?? []);
            $prefs['local_alarms_at'] = now()->getTimestamp();
            $prefs['local_alarms_until'] = count($items) >= $max
                ? intdiv((int) end($items)['at'], 1000) - 1
                : now()->addDays($days)->getTimestamp();
            $user->forceFill(['preferences' => $prefs])->save();
        }

        return response()->json([
            'data' => $items,
            'server_ms' => (int) floor(now()->getPreciseTimestamp(3)),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'categories' => ['present', 'array', 'max:4'],
            'categories.*' => ['string', Rule::in(array_keys(ReminderSetting::CATEGORIES))],
            'days_before' => ['present', 'array', 'max:6'],
            'days_before.*' => ['integer', Rule::in(ReminderSetting::DAYS_BEFORE)],
            'degree' => ['required', 'integer', 'min:1', 'max:4'],
        ]);
        $setting = ReminderSetting::query()->firstOrNew(['user_id' => $request->user()->id]);
        $setting->user_id = $request->user()->id;
        $setting->fill([
            'enabled' => $data['enabled'],
            'time' => $data['time'],
            'categories' => array_values(array_unique($data['categories'])),
            'days_before' => array_values(array_unique(array_map('intval', $data['days_before']))),
            'degree' => $data['degree'],
        ])->save();

        return response()->json(['message' => $data['enabled'] ? 'یادآوری مناسبت‌ها هر روز ساعت '.PersianText::toPersianDigits($data['time']).' به وقت تهران.' : 'یادآوری مناسبت‌ها خاموش شد.']);
    }

    private function validated(Request $request, ?Reminder $current = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'starts_on' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'time' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'repeat' => ['required', Rule::in(array_keys(Reminder::REPEATS))],
            'until_on' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/'],
            'remind_before' => ['nullable', 'integer', Rule::in(Reminder::BEFORE)],
            'active' => ['nullable', 'boolean'],
            'person_id' => ['nullable', 'string', 'max:36'],
        ]);
        foreach (['starts_on', 'until_on'] as $field) {
            if (! empty($data[$field])) {
                [$y, $m, $d] = array_map('intval', explode('-', $data[$field]));
                if ($y < 1300 || $y > 1600 || ! Jalali::isValid($y, $m, $d)) {
                    throw new DomainException('تاریخ خورشیدی معتبر نیست.');
                }
            }
        }
        if (! empty($data['until_on']) && $data['until_on'] < $data['starts_on']) {
            throw new DomainException('تاریخ پایان تکرار نمی‌تواند پیش از تاریخ شروع باشد.');
        }
        if (! empty($data['person_id']) && ! Person::query()->whereKey($data['person_id'])->exists()) {
            $data['person_id'] = null;
        }
        $data['title'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $data['title']) ?? '');
        $data['note'] = isset($data['note']) ? trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $data['note']) ?? '') ?: null : null;
        $data['remind_before'] = (int) ($data['remind_before'] ?? 0);
        $data['active'] = $data['active'] ?? true;
        if ($data['repeat'] === 'none') {
            $data['until_on'] = null;
        }

        return $data;
    }

    private function savedMessage(Reminder $r): string
    {
        if (! $r->active) {
            return 'هشدار ذخیره شد (غیرفعال).';
        }
        if ($r->next_at === null) {
            return 'ذخیره شد، ولی زمان این هشدار گذشته است و دیگر زنگ نمی‌خورد.';
        }
        $at = $r->next_at->copy()->setTimezone('Asia/Tehran');
        [$y, $m, $d] = Jalali::fromGregorian((int) $at->format('Y'), (int) $at->format('n'), (int) $at->format('j'));

        return PersianText::toPersianDigits("ذخیره شد. زنگ بعدی: {$d} ".Jalali::MONTHS[$m]." {$y} ساعت ".$at->format('H:i').' (وقت تهران)');
    }
}
