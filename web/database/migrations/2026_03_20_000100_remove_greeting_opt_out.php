<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیامک تبریک و اعلان تولد برای هیچ‌کس قابل خاموش کردن نیست (به خواست مالک سایت).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('persons', 'accept_greeting_sms')) {
            Schema::table('persons', function (Blueprint $table) {
                $table->dropColumn('accept_greeting_sms');
            });
        }
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->boolean('accept_greeting_sms')->default(true);
        });
    }
};
