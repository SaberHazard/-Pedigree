<?php

use App\Support\SocialNetworks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - education_field_group: گروه رشته (فنی و مهندسی، پزشکی، پرستاری ...) برای عنوان خودکار «مهندس»
 * - honorific_mode: auto (عنوان «دکتر/مهندس» خودکار) یا none
 * - contact_visibility / location_visibility: چه کسانی شماره/نشانی را ببینند
 *   all = همه اعضا، d4..d1 = بستگان تا درجه ۴..۱، self = فقط خود شخص (و مدیر)
 * - social_avatars: عکس پروفایل شبکه‌های اجتماعی {network: {media_id, handle, at}}
 * - avatar_source: منبع عکس پروفایل فعلی (null = آپلود در سایت، social:instagram = از شبکه اجتماعی)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('education_field_group', 20)->nullable();
            $table->string('honorific_mode', 10)->default('auto');
            $table->string('contact_visibility', 10)->default('all');
            $table->string('location_visibility', 10)->default('d1');
            $table->json('social_avatars')->nullable();
            $table->string('avatar_source', 30)->nullable();
        });

        // انتقال تنظیم‌های قبلی (روشن/خاموش) به سطح‌های جدید
        DB::table('persons')->where('share_location', true)->update(['location_visibility' => 'all']);

        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn(['share_location', 'share_contact']);
        });

        // شناسه‌های شبکه‌های اجتماعی قبلی به شکل استاندارد (لینک کامل ← شناسه، ۰۹۱۲ ← +۹۸۹۱۲)
        DB::table('persons')->whereNotNull('social')->orderBy('id')->chunk(500, function ($rows) {
            foreach ($rows as $row) {
                $social = json_decode((string) $row->social, true);
                if (! is_array($social)) {
                    continue;
                }
                $clean = [];
                foreach ($social as $network => $value) {
                    $clean[$network] = is_string($network) && is_string($value)
                        ? (SocialNetworks::normalize($network, $value) ?? $value)
                        : $value;
                }
                if ($clean !== $social) {
                    DB::table('persons')->where('id', $row->id)->update(['social' => json_encode($clean, JSON_UNESCAPED_UNICODE)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->boolean('share_location')->default(false);
            $table->boolean('share_contact')->default(false);
        });
        DB::table('persons')->where('location_visibility', 'all')->update(['share_location' => true]);
        DB::table('persons')->where('contact_visibility', 'all')->update(['share_contact' => true]);
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn(['education_field_group', 'honorific_mode', 'contact_visibility', 'location_visibility', 'social_avatars', 'avatar_source']);
        });
    }
};
