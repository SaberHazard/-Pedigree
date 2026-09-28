<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - settings: تنظیمات و کلیدهای API که مدیر کل از پنل مدیریت وارد می‌کند (مقدارها رمزنگاری‌شده)
 * - sms_messages: پیامک‌هایی که اعضا از پنل پیامکی سایت فرستاده‌اند (تبریک تولد) — بدون شماره کامل گیرنده
 * - occasion_runs: جلوگیری از ارسال دوباره اعلان/پیامک یک مناسبت (مثلاً تولد فلانی در ۱۴۰۵)
 * - persons.accept_greeting_sms: آیا شخص پیامک تبریک از طرف اعضا را می‌پذیرد
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 150)->primary();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('recipient_person_id')->nullable();
            $table->string('kind', 20)->default('birthday');
            $table->string('provider', 20);
            $table->string('status', 10);
            $table->boolean('auto')->default(false);
            $table->text('body');
            $table->string('phone_hint', 24)->nullable();
            $table->string('error', 255)->nullable();
            // روز ارسال به وقت تهران (برای سقف روزانه و جلوگیری از تکرار)
            $table->date('sent_on');
            $table->timestamps();

            $table->foreign('recipient_person_id')->references('id')->on('persons')->nullOnDelete();
            $table->index(['sender_user_id', 'sent_on']);
            $table->index(['recipient_person_id', 'sent_on']);
            $table->index('sent_on');
        });

        Schema::create('occasion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('persons', function (Blueprint $table) {
            $table->boolean('accept_greeting_sms')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('accept_greeting_sms');
        });
        Schema::dropIfExists('occasion_runs');
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('settings');
    }
};
