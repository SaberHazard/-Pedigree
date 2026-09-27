<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - users.username: ورود با نام کاربری و رمز برای کسانی که موبایل یا کد ملی ندارند (مثلاً سالمندان)
 * - media.category: gallery (گالری) یا story (استوری‌های ضبط‌شده؛ برای همیشه می‌مانند)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 30)->nullable()->unique();
        });

        Schema::table('media', function (Blueprint $table) {
            $table->string('category', 20)->default('gallery');
            $table->index(['person_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['person_id', 'category']);
            $table->dropColumn('category');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
