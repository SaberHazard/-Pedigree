<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تأیید عضویت: کسی که خودش ثبت‌نام می‌کند (شماره‌اش در شجره‌نامه نبوده) تا تأیید مدیر «در انتظار» می‌ماند
 * و هیچ بخشی از شجره‌نامه را نمی‌بیند. «معرفی» او (مثلاً «پسر حسن احمدی») برای مدیر نمایش داده می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('join_note', 300)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['join_note', 'approved_at']);
        });
    }
};
