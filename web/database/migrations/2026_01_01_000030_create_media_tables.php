<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * عکس‌ها و ویدیوهای هر شخص + آرای تأیید آن‌ها.
 *
 * هر رسانه ابتدا pending است و بعد از رأی‌گیری بستگان approved یا rejected می‌شود.
 * فایل‌ها روی دیسک خصوصی ذخیره می‌شوند (نه پوشه public) و فقط با لینک امضاشده قابل دسترسی‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id')->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // image | video
            $table->string('type', 10);
            // pending | approved | rejected
            $table->string('status', 20)->default('pending')->index();
            // auto | owner | vote | admin  (چه کسی درباره تأیید تصمیم می‌گیرد)
            $table->string('approval_mode', 20)->nullable();
            // queued | processing | ready | failed  (وضعیت فشرده‌سازی)
            $table->string('processing', 20)->default('ready');

            $table->string('disk', 30);
            $table->string('path');
            // مسیر نسخه‌های دیگر: thumb, medium, poster
            $table->json('variants')->nullable();
            $table->string('mime', 100);
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('checksum', 64)->nullable()->index();

            $table->string('caption', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('taken_at', 10)->nullable();
            // بعد از تأیید، عکس پروفایل شخص شود
            $table->boolean('set_as_avatar')->default(false);

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });

        Schema::table('persons', function (Blueprint $table) {
            $table->foreign('avatar_media_id')->references('id')->on('media')->nullOnDelete();
        });

        Schema::create('media_votes', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // approve | reject | null (هنوز رأی نداده)
            $table->string('decision', 10)->nullable();
            $table->string('comment', 500)->nullable();
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();

            $table->unique(['media_id', 'user_id']);
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_votes');
        Schema::table('persons', function (Blueprint $table) {
            $table->dropForeign(['avatar_media_id']);
        });
        Schema::dropIfExists('media');
    }
};
