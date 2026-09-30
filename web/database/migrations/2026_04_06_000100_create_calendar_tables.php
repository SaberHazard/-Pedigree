<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تقویم: روزهای تقویم رسمی (همگام از time.ir با holidayapi.ir) و آغاز رسمی ماه‌های قمری که از همان داده به دست می‌آید.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_days', function (Blueprint $table) {
            // YYYY-MM-DD خورشیدی
            $table->string('jalali', 10)->primary();
            $table->date('gregorian')->index();
            $table->boolean('is_holiday')->default(false);
            $table->json('events')->nullable();
            $table->timestamp('fetched_at')->nullable();
        });

        Schema::create('hijri_months', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('starts_on');
            $table->string('source', 12)->default('official');
            $table->unique(['year', 'month']);
            $table->index('starts_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hijri_months');
        Schema::dropIfExists('calendar_days');
    }
};
