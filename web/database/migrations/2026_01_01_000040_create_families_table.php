<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خاندان‌ها: نقطه‌های ورود به درخت (مثلاً «خاندان احمدی» با جد اعلای مشخص).
 * خودِ اشخاص در یک گراف واحد هستند؛ خاندان فقط یک «ریشه» و عنوان است.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->uuid('root_person_id')->nullable()->index();
            // رنگ شاخص خاندان در رابط کاربری
            $table->string('color', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('root_person_id')->references('id')->on('persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('families');
    }
};
