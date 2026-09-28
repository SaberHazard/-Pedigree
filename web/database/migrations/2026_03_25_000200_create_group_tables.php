<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «گروه خاندان»: گفتگوی همه اعضا برای زنده کردن خاطرات، با عکس و فیلم قدیمی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_messages', function (Blueprint $table) {
            $table->id();
            // null = پیام سیستم (مثل «سؤال روز»)
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            // text | media | prompt
            $table->string('kind', 10)->default('text');
            $table->text('body');
            $table->uuid('media_id')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('group_messages')->nullOnDelete();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->nullOnDelete();
            $table->index(['user_id', 'created_at']);
            $table->index('updated_at');
            $table->index('pinned_at');
        });

        Schema::create('group_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
        });

        Schema::create('group_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('group_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['message_id', 'user_id']);
            $table->index('resolved_at');
        });

        // چه کسانی در عکس هستند (برچسب اشخاص روی خاطره‌ها)
        Schema::create('media_tags', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id');
            $table->uuid('person_id');
            $table->foreignId('tagged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->unique(['media_id', 'person_id']);
            $table->index('person_id');
        });

        Schema::table('users', function (Blueprint $table) {
            // آخرین پیام خوانده‌شده گروه و محدودیت ارسال (سکوت) توسط مدیر
            $table->unsignedBigInteger('group_read_id')->default(0);
            $table->timestamp('group_muted_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['group_read_id', 'group_muted_until']);
        });
        Schema::dropIfExists('media_tags');
        Schema::dropIfExists('group_reports');
        Schema::dropIfExists('group_reactions');
        Schema::dropIfExists('group_messages');
    }
};
