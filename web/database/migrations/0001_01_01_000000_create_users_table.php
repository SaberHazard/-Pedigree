<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول حساب‌های کاربری.
 *
 * نکته طراحی: اطلاعات هویتی (نام، موبایل، کد ملی ...) در جدول persons است و
 * جدول users فقط «حساب ورود» یک شخص است. هر کاربر دقیقاً به یک شخص وصل است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // شخصِ صاحب این حساب (کلید خارجی در مایگریشن persons اضافه می‌شود)
            $table->uuid('person_id')->nullable()->unique();
            // رمز عبور اختیاری برای ورود با کد ملی
            $table->string('password')->nullable();
            // نقش: super_admin | admin | member
            $table->string('role', 20)->default('member')->index();
            // وضعیت: active | blocked
            $table->string('status', 20)->default('active')->index();
            // تنظیمات نمایشی کاربر (تم، متن دور دایره‌ها و ...)
            $table->json('preferences')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            // آخرین زمانی که کاربر با کد پیامکی هویتش را تأیید کرده
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            // چه کسی رمز را تعیین کرده (اگر کس دیگری بوده، با اولین ورود پیامکی صاحب حساب پاک می‌شود)
            $table->unsignedBigInteger('password_set_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
