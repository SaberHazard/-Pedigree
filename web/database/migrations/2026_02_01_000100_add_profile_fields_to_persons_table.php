<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پروفایل کامل: تحصیلات، شغل، محل سکونت روی نقشه، راه‌های ارتباطی و ویژگی‌های دیگر.
 *
 * - نشانی دقیق، کد پستی، تلفن ثابت و مختصات خانه رمزنگاری می‌شوند و فقط برای
 *   خود شخص و ویرایشگرانش (یا همه اعضا با اجازه خود شخص) نمایش داده می‌شوند.
 * - مختصات آرامگاه عمومی است (برای پیدا کردن مزار درگذشتگان).
 * - field_meta: آخرین ویرایشگر هر فیلد {field: {u: user_id, t: unix}} برای رنگ‌بندی نویسندگان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            // تحصیلات و شغل
            $table->string('education_level', 30)->nullable()->index();
            $table->string('education_field', 150)->nullable();
            $table->string('education_institution', 150)->nullable();
            $table->string('academic_rank', 30)->nullable();
            $table->string('workplace', 150)->nullable();

            // محل سکونت (کشور با کد دو حرفی ISO مثل IR, DE, CA)
            $table->char('country', 2)->nullable()->index();
            $table->string('province', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('address')->nullable();
            $table->text('postal_code')->nullable();
            $table->text('home_lat')->nullable();
            $table->text('home_lng')->nullable();
            // نشانی و موقعیت خانه برای همه اعضای خاندان نمایش داده شود
            $table->boolean('share_location')->default(false);

            // راه‌های ارتباطی
            $table->text('landline')->nullable();
            $table->string('website', 191)->nullable();
            $table->json('social')->nullable();
            // موبایل، ایمیل و تلفن برای همه اعضای خاندان نمایش داده شود
            $table->boolean('share_contact')->default(false);

            // ویژگی‌های دیگر
            $table->string('blood_type', 3)->nullable();
            $table->string('languages', 200)->nullable();
            $table->string('interests', 500)->nullable();
            // ویژگی‌های دلخواه: [{label, value}]
            $table->json('custom_fields')->nullable();

            // مزار
            $table->decimal('burial_lat', 10, 7)->nullable();
            $table->decimal('burial_lng', 10, 7)->nullable();

            $table->json('field_meta')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropIndex(['education_level']);
            $table->dropIndex(['country']);
            $table->dropColumn([
                'education_level', 'education_field', 'education_institution', 'academic_rank', 'workplace',
                'country', 'province', 'city', 'address', 'postal_code', 'home_lat', 'home_lng', 'share_location',
                'landline', 'website', 'social', 'share_contact',
                'blood_type', 'languages', 'interests', 'custom_fields', 'burial_lat', 'burial_lng', 'field_meta',
            ]);
        });
    }
};
