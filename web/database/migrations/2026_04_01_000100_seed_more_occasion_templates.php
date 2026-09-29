<?php

use App\Services\Sms\SmsTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * متن پیش‌فرض مناسبت‌های تازه (سپندارمذگان، روز مادر، روز پدر، نیمه شعبان، عید فطر، قربان و غدیر).
 * فقط قالب‌هایی که هنوز نیستند اضافه می‌شوند؛ متن‌هایی که مدیر کل ویرایش کرده دست نمی‌خورند.
 */
return new class extends Migration
{
    public function up(): void
    {
        $existing = DB::table('sms_templates')->whereNotNull('slug')->pluck('slug')->flip();
        foreach (SmsTemplates::DEFAULTS as $i => [$occasion, $slug, $title, $body]) {
            if (isset($existing[$slug])) {
                continue;
            }
            DB::table('sms_templates')->insert([
                'occasion' => $occasion,
                'slug' => $slug,
                'title' => $title,
                'body' => $body,
                'active' => true,
                'sort_order' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('sms_templates')->whereIn('slug', ['sepandarmazgan', 'mother_day', 'father_day', 'nimeh_shaban', 'eid_fitr', 'eid_adha', 'eid_ghadir'])->delete();
    }
};
