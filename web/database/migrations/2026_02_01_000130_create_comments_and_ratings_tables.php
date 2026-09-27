<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نظرسنجی خاندان درباره هر شخص:
 *  - person_comments: نظر متنی با نام نویسنده
 *  - person_ratings: امتیاز ۱ تا ۵ هر عضو به هر ویژگی (شوخ‌طبعی، کاریزما ...)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('person_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            // مخفی‌شده توسط خود شخص، ویرایشگرانش یا مدیر (متن برای نویسنده و مدیر باقی می‌ماند)
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['person_id', 'created_at']);
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });

        Schema::create('person_ratings', function (Blueprint $table) {
            $table->id();
            $table->uuid('person_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('trait', 30);
            $table->unsignedTinyInteger('score');
            $table->timestamps();

            $table->unique(['person_id', 'user_id', 'trait']);
            $table->index(['person_id', 'trait']);
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_ratings');
        Schema::dropIfExists('person_comments');
    }
};
