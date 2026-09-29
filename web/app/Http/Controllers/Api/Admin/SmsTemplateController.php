<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\SmsTemplate;
use App\Services\AuditLogger;
use App\Services\Occasions\OccasionCalendar;
use App\Services\Sms\GreetingService;
use App\Services\Sms\SmsTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * «قالب‌های پیامک تبریک» در پنل مدیریت — فقط مدیر کل.
 *
 * متن ثابتی که همه اعضا با آن تبریک می‌فرستند، با متغیرهایی مثل {to_first_name} و {relation}.
 * آخرین قالب فعال تولد قابل حذف یا غیرفعال کردن نیست (تبریک تولد خاموش‌شدنی نیست).
 */
class SmsTemplateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(OccasionCalendar $calendar): JsonResponse
    {
        $occasions = [];
        foreach (SmsTemplates::OCCASIONS as $key => $o) {
            $occasions[] = $o + ['key' => $key, 'window' => OccasionCalendar::WINDOWS[$key], 'enabled' => OccasionCalendar::enabled($key), 'next' => $calendar->nextText($key)];
        }
        $variables = [];
        foreach (SmsTemplates::variables() as $key => $v) {
            $variables[] = ['key' => $key, 'token' => SmsTemplates::faName($key)] + $v;
        }

        return response()->json([
            'data' => SmsTemplate::query()->orderBy('occasion')->orderBy('sort_order')->orderBy('id')->get()->map(fn (SmsTemplate $t) => $this->present($t)),
            'occasions' => $occasions,
            'variables' => $variables,
            'limits' => ['body_max' => SmsTemplates::BODY_MAX, 'title_max' => SmsTemplates::TITLE_MAX, 'per_occasion' => SmsTemplates::PER_OCCASION_MAX],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if (SmsTemplate::query()->where('occasion', $data['occasion'])->count() >= SmsTemplates::PER_OCCASION_MAX) {
            throw new DomainException('برای هر مناسبت حداکثر '.SmsTemplates::PER_OCCASION_MAX.' قالب می‌شود ساخت.');
        }
        $template = SmsTemplate::create($data + [
            'sort_order' => (int) SmsTemplate::query()->where('occasion', $data['occasion'])->max('sort_order') + 1,
            'updated_by' => $request->user()->id,
        ]);
        $this->audit->log('sms_template.created', null, ['id' => $template->id, 'occasion' => $template->occasion], $request->user());

        return response()->json(['data' => $this->present($template), 'message' => 'قالب ساخته شد.'], 201);
    }

    public function update(Request $request, int $template): JsonResponse
    {
        $tpl = SmsTemplate::query()->findOrFail($template);
        $data = $this->validated($request);
        $leavingBirthday = $tpl->occasion === 'birthday' && $tpl->active && (! $data['active'] || $data['occasion'] !== 'birthday');
        if ($leavingBirthday && $this->lastActiveBirthday($tpl)) {
            throw new DomainException('تبریک تولد قابل خاموش کردن نیست؛ دست‌کم یک قالب فعال تولد باید بماند.');
        }
        $tpl->fill($data + ['updated_by' => $request->user()->id])->save();
        $this->audit->log('sms_template.updated', null, ['id' => $tpl->id, 'occasion' => $tpl->occasion], $request->user());

        return response()->json(['data' => $this->present($tpl), 'message' => 'قالب ذخیره شد.']);
    }

    public function destroy(Request $request, int $template): JsonResponse
    {
        $tpl = SmsTemplate::query()->findOrFail($template);
        if ($tpl->occasion === 'birthday' && $tpl->active && $this->lastActiveBirthday($tpl)) {
            throw new DomainException('آخرین قالب فعال تولد قابل حذف نیست.');
        }
        $tpl->delete();
        $this->audit->log('sms_template.deleted', null, ['id' => $tpl->id, 'occasion' => $tpl->occasion], $request->user());

        return response()->json(['message' => 'قالب حذف شد.']);
    }

    /** ترتیب نمایش قالب‌های یک مناسبت */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'occasion' => ['required', 'string', Rule::in(array_keys(SmsTemplates::OCCASIONS))],
            'ids' => ['required', 'array', 'max:'.SmsTemplates::PER_OCCASION_MAX],
            'ids.*' => ['integer'],
        ]);
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                SmsTemplate::query()->where('occasion', $data['occasion'])->whereKey($id)->update(['sort_order' => $i]);
            }
        });

        return response()->json(['message' => 'ترتیب ذخیره شد.']);
    }

    /**
     * پیش‌نمایش متن (قبل از ذخیره): با مقادیر نمونه، یا با مشخصات واقعی یک گیرنده و خودِ مدیر به عنوان فرستنده
     */
    public function preview(Request $request, GreetingService $greetings): JsonResponse
    {
        $data = $request->validate([
            'occasion' => ['required', 'string', Rule::in(array_keys(SmsTemplates::OCCASIONS))],
            'body' => ['required', 'string', 'max:'.(SmsTemplates::BODY_MAX * 2)],
            'person_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $body = SmsTemplates::validateBody($data['occasion'], $data['body']);
        if (! empty($data['person_id'])) {
            $person = Person::query()->findOrFail($data['person_id']);
            $values = $greetings->values($request->user(), $person, $data['occasion'], $greetings->cleanNote($data['note'] ?? null));
        } else {
            $values = SmsTemplates::sampleValues();
            if (array_key_exists('note', $data)) {
                $values['note'] = $greetings->cleanNote($data['note']);
            }
        }
        $text = SmsTemplates::render($body, $values);

        return response()->json(['text' => $text, 'length' => mb_strlen($text), 'parts' => SmsTemplates::parts($text)]);
    }

    /** بازگردانی قالب‌های پیش‌فرض (قالب‌های ساخته‌شده توسط مدیر دست نمی‌خورند) */
    public function restoreDefaults(Request $request): JsonResponse
    {
        SmsTemplates::seedDefaults();
        $this->audit->log('sms_template.defaults', null, [], $request->user());

        return response()->json(['message' => 'قالب‌های پیش‌فرض بازگردانی شدند.']);
    }

    // ------------------------------------------------------------------ کمکی‌ها

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'occasion' => ['required', 'string', Rule::in(array_keys(SmsTemplates::OCCASIONS))],
            'title' => ['required', 'string', 'max:'.SmsTemplates::TITLE_MAX],
            'body' => ['required', 'string', 'max:'.(SmsTemplates::BODY_MAX * 2)],
            'active' => ['required', 'boolean'],
        ]);
        $data['title'] = trim(preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $data['title']) ?? '');
        $data['body'] = SmsTemplates::validateBody($data['occasion'], $data['body']);

        return $data;
    }

    private function lastActiveBirthday(SmsTemplate $tpl): bool
    {
        return ! SmsTemplate::query()->where('occasion', 'birthday')->where('active', true)->whereKeyNot($tpl->id)->exists();
    }

    private function present(SmsTemplate $t): array
    {
        return [
            'id' => $t->id,
            'occasion' => $t->occasion,
            'title' => $t->title,
            'body' => $t->body,
            'active' => $t->active,
            'default' => $t->slug !== null,
            'sort_order' => $t->sort_order,
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }
}
