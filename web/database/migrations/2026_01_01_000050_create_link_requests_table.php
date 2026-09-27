<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * درخواست‌های اتصال دو شخص موجود (مثلاً وصل کردن همسر از یک درخت دیگر).
 *
 * وقتی درخواست‌دهنده به طرف مقابل دسترسی ویرایش ندارد، اتصال مستقیم انجام نمی‌شود
 * و یکی از افرادی که حق ویرایش طرف مقابل را دارد باید آن را بپذیرد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // spouse | father | mother | child
            $table->string('type', 20);
            // شخصی که درخواست‌دهنده حق ویرایشش را دارد
            $table->uuid('subject_id')->index();
            // شخص موجود در درخت دیگر
            $table->uuid('target_id')->index();
            $table->json('payload')->nullable();
            // pending | accepted | rejected | cancelled
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamps();

            $table->foreign('subject_id')->references('id')->on('persons')->cascadeOnDelete();
            $table->foreign('target_id')->references('id')->on('persons')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_requests');
    }
};
