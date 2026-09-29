<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * گزارش خطاهای سرور و مرورگر برای پنل مدیریت (بدون جزئیات حساس؛ هر خطای یکسان یک ردیف با شمارنده).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 40)->unique();
            $table->string('ref', 12)->unique();
            $table->string('source', 10)->default('server');
            $table->string('type', 120);
            $table->string('message', 600);
            $table->string('location', 255)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
