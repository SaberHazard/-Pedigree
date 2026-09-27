<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * ثبت لاگ ممیزی.
 *
 * هر تغییر مهمی (ساخت/ویرایش/حذف شخص، اتصال، آپلود، رأی، ورود) اینجا ثبت می‌شود.
 * مقادیر حساس (کد ملی، موبایل ...) هرگز به صورت خام در لاگ نوشته نمی‌شوند.
 */
class AuditLogger
{
    /** فیلدهایی که مقدارشان در لاگ ماسک می‌شود */
    private const SENSITIVE = [
        'national_code', 'national_code_hash', 'phone', 'phone_hash', 'birth_cert_no',
        'password', 'remember_token', 'search_text', 'updated_at', 'created_at',
    ];

    public function log(string $action, ?Model $subject = null, array $properties = [], ?User $user = null): ActivityLog
    {
        $request = request();
        $user ??= Auth::user();

        return ActivityLog::create([
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'properties' => $properties ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    /**
     * تغییرات یک مدل (قبل از save) به صورت {field: [old, new]}
     * فیلدهای حساس فقط با علامت «تغییر کرد» ثبت می‌شوند.
     */
    public function diff(Model $model): array
    {
        $changes = [];
        foreach ($model->getDirty() as $field => $new) {
            if (in_array($field, ['national_code_hash', 'phone_hash', 'search_text', 'updated_at', 'created_at'], true)) {
                continue;
            }
            if (in_array($field, self::SENSITIVE, true)) {
                $changes[$field] = ['***', '***'];

                continue;
            }
            $changes[$field] = [$model->getOriginal($field), $new];
        }

        return $changes;
    }
}
