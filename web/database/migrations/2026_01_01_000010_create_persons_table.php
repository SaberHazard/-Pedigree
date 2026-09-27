<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جدول اصلی اشخاص شجره‌نامه.
 *
 * - شناسه هر شخص UUID است (کد یکتای داخلی) و یک کد کوتاه خوانا (code) هم دارد.
 * - رابطه پدر/مادر مستقیماً با father_id و mother_id نگه داشته می‌شود که
 *   پیمایش درخت را بسیار سریع می‌کند.
 * - تاریخ‌ها به صورت «تاریخ جزئی شمسی» ذخیره می‌شوند: 1305 یا 1305-07 یا 1305-07-12
 *   چون در شجره‌نامه اغلب فقط سال تولد معلوم است.
 * - کد ملی، شماره شناسنامه و موبایل رمزنگاری می‌شوند و برای جستجو یک هش
 *   (blind index) کنارشان ذخیره می‌شود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 12)->unique();

            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('nickname', 100)->nullable();
            // عنوان/پیشوند مثل حاج، سید، دکتر، میرزا
            $table->string('title', 50)->nullable();
            // m = مرد، f = زن
            $table->char('gender', 1)->index();

            $table->uuid('father_id')->nullable()->index();
            $table->uuid('mother_id')->nullable()->index();
            // ترتیب تولد بین خواهر و برادرها (وقتی تاریخ دقیق معلوم نیست)
            $table->unsignedSmallInteger('birth_order')->nullable();

            $table->string('birth_date', 10)->nullable();
            $table->string('birth_place', 150)->nullable();
            $table->boolean('is_deceased')->default(false)->index();
            $table->string('death_date', 10)->nullable();
            $table->string('death_place', 150)->nullable();
            $table->string('burial_place', 200)->nullable();

            // اطلاعات حساس (رمزنگاری‌شده)
            $table->text('national_code')->nullable();
            $table->string('national_code_hash', 64)->nullable()->unique();
            $table->text('birth_cert_no')->nullable();
            $table->string('birth_cert_place', 150)->nullable();
            $table->text('phone')->nullable();
            $table->string('phone_hash', 64)->nullable()->unique();
            $table->string('email', 191)->nullable();

            $table->string('occupation', 150)->nullable();
            $table->string('education', 150)->nullable();
            $table->string('residence', 200)->nullable();
            $table->text('biography')->nullable();

            // عکس پروفایل فعلی (یکی از رسانه‌های تأییدشده)
            $table->uuid('avatar_media_id')->nullable();

            // اگر true باشد فقط خود شخص و مدیر حق ویرایش دارند
            $table->boolean('is_locked')->default(false);

            // متن نرمال‌شده برای جستجوی سریع (نام، نام خانوادگی، شهرت، کد)
            $table->string('search_text', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
            $table->foreign('father_id')->references('id')->on('persons')->nullOnDelete();
            $table->foreign('mother_id')->references('id')->on('persons')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['person_id']);
        });
        Schema::dropIfExists('persons');
    }
};
