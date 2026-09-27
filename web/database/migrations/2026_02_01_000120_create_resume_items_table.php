<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سوابق رزومه (تحصیل، کار، سربازی، جایزه، مهارت ...) به صورت ساختاریافته و قابل مرتب‌سازی.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resume_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('person_id');
            $table->string('type', 20);
            $table->string('title', 200);
            $table->string('organization', 200)->nullable();
            $table->string('location', 200)->nullable();
            // تاریخ جزئی شمسی مثل 1370 یا 1370-06
            $table->string('start_date', 10)->nullable();
            $table->string('end_date', 10)->nullable();
            $table->boolean('is_current')->default(false);
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['person_id', 'type']);
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resume_items');
    }
};
