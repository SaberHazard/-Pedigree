<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حمایت از سازنده: حساب‌ها و لینک‌های پرداخت (مدیر کل) و پرداخت‌های درگاه
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('donation_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12); // card | sheba | account | link
            $table->string('title', 80);
            $table->string('holder', 80)->nullable();
            $table->string('value', 300);
            $table->string('note', 200)->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('gateway', 20);
            $table->unsignedBigInteger('amount'); // تومان
            $table->string('status', 12)->default('pending')->index(); // pending | paid | failed
            $table->string('authority', 120)->nullable();
            $table->string('callback_token', 64)->unique();
            $table->string('ref_id', 120)->nullable();
            $table->string('card', 30)->nullable();
            $table->string('message', 200)->nullable();
            $table->boolean('anonymous')->default(false);
            $table->string('ip', 45)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'authority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('donations');
        Schema::dropIfExists('donation_accounts');
    }
};
