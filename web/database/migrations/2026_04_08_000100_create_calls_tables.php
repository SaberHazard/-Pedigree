<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تماس صوتی/تصویری مستقیم (WebRTC): صدا و تصویر مستقیم بین گوشی‌ها می‌رود؛ سرور فقط پیام‌های کوچک «راه‌اندازی»
 * (signal) را جابه‌جا می‌کند. دعوت به تماس با لینک (واتس‌اپ، گوگل‌میت، اسکای‌روم ...) هم اینجا ثبت می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->string('id', 26)->primary();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 8); // audio|video
            $table->string('status', 10)->default('ringing'); // ringing|active|ended
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->string('call_id', 26);
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state', 10)->default('invited'); // invited|joined|left|declined|missed
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
            $table->unique(['call_id', 'user_id']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('call_signals', function (Blueprint $table) {
            $table->id();
            $table->string('call_id', 26);
            $table->foreign('call_id')->references('id')->on('calls')->cascadeOnDelete();
            $table->unsignedBigInteger('from_user_id');
            $table->unsignedBigInteger('to_user_id');
            $table->string('type', 12); // offer|answer|candidates|bye
            $table->text('payload');
            $table->timestamp('created_at')->nullable();
            $table->index(['call_id', 'to_user_id', 'id']);
        });

        Schema::create('call_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('url', 300);
            $table->string('title', 120);
            $table->timestamp('starts_at')->nullable();
            $table->unsignedSmallInteger('recipients')->default(0);
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('call_invite_user', function (Blueprint $table) {
            $table->foreignId('call_invite_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['call_invite_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_invite_user');
        Schema::dropIfExists('call_invites');
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('call_participants');
        Schema::dropIfExists('calls');
    }
};
