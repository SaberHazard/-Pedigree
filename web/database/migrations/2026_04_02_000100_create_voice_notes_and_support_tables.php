<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیام صوتی (مثل تلگرام) در پیام خصوصی، گروه خاندان و گفتگو با پشتیبانی + جدول‌های گفتگو با پشتیبانی
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('context', 20); // dm | group | support
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->unsignedInteger('duration_ms');
            $table->json('waveform')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('direct_messages', function (Blueprint $table) {
            $table->uuid('voice_id')->nullable()->index();
        });
        Schema::table('group_messages', function (Blueprint $table) {
            $table->uuid('voice_id')->nullable()->index();
        });

        Schema::create('support_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            // آخرین پیامی که عضو / مدیران خوانده‌اند
            $table->unsignedBigInteger('user_read_id')->default(0);
            $table->unsignedBigInteger('admin_read_id')->default(0);
            $table->boolean('closed')->default(false);
            $table->timestamps();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('support_threads')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('from_admin')->default(false);
            $table->text('body');
            $table->uuid('voice_id')->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_threads');
        Schema::table('group_messages', fn (Blueprint $table) => $table->dropColumn('voice_id'));
        Schema::table('direct_messages', fn (Blueprint $table) => $table->dropColumn('voice_id'));
        Schema::dropIfExists('voice_notes');
    }
};
