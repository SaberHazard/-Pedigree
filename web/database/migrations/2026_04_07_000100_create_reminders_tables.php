<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * هشدار و یادآور شخصی (هر تاریخ و ساعت به وقت تهران، با تکرار) و تنظیم یادآوری مناسبت‌های خانوادگی و رسمی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('note')->nullable();
            // تاریخ خورشیدی نخستین رخداد و ساعت (وقت تهران)
            $table->string('starts_on', 10);
            $table->string('time', 5);
            // none | daily | weekly | monthly | yearly | monthly_hijri | yearly_hijri
            $table->string('repeat', 16)->default('none');
            $table->string('until_on', 10)->nullable();
            // چند دقیقه پیش از موعد
            $table->unsignedInteger('remind_before')->default(0);
            // زمان زنگ بعدی (UTC)
            $table->timestamp('next_at')->nullable()->index();
            $table->timestamp('last_sent_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('person_id', 36)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'active']);
        });

        Schema::create('reminder_settings', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            // ساعت یادآوری روزانه مناسبت‌ها (وقت تهران)
            $table->string('time', 5)->default('08:00')->index();
            // birthday, anniversary, death, official
            $table->json('categories')->nullable();
            // [0, 1, 7] = همان روز، یک روز قبل، یک هفته قبل
            $table->json('days_before')->nullable();
            // بیشترین درجه خویشاوندی (۱ تا ۴)
            $table->unsignedTinyInteger('degree')->default(2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_settings');
        Schema::dropIfExists('reminders');
    }
};
