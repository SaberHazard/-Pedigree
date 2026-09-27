<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ازدواج‌ها.
 *
 * یک شخص می‌تواند چند ازدواج داشته باشد (مثلاً پدربزرگی با دو همسر).
 * فرزندان هر ازدواج از روی father_id + mother_id شناخته می‌شوند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marriages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('husband_id')->index();
            $table->uuid('wife_id')->index();
            // married = متأهل | divorced = جدا شده | widowed = فوت همسر
            $table->string('status', 20)->default('married');
            $table->string('marriage_date', 10)->nullable();
            $table->string('end_date', 10)->nullable();
            // ترتیب نمایش همسران یک شخص
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['husband_id', 'wife_id']);
            $table->foreign('husband_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->foreign('wife_id')->references('id')->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marriages');
    }
};
