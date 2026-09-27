<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * متن‌های بلند پروفایل (چکیده، توضیحات بستگان، زندگی‌نامه، رزومه) با نویسنده هر کلمه.
 *
 * segments: [[user_id, "متن"], ...] ؛ هر تکه متن با شناسه کسی که آن را نوشته.
 * با هر ذخیره، تفاوت کلمه‌به‌کلمه با نسخه قبل حساب می‌شود: کلمه‌های دست‌نخورده
 * نویسنده قبلی را نگه می‌دارند و کلمه‌های تازه (حتی اصلاح یک غلط املایی) به نام ویرایشگر ثبت می‌شوند.
 * همه نسخه‌ها در person_text_revisions می‌مانند و قابل بازگردانی‌اند.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_texts', function (Blueprint $table) {
            $table->id();
            $table->uuid('person_id');
            $table->string('field', 20);
            $table->json('segments');
            // متن ساده (برای جستجو و خروجی)
            $table->longText('plain');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['person_id', 'field']);
            $table->foreign('person_id')->references('id')->on('persons')->cascadeOnDelete();
        });

        Schema::create('person_text_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_text_id')->constrained('person_texts')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->json('segments');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // تعداد کلمه‌های اضافه/حذف‌شده نسبت به نسخه قبل
            $table->unsignedInteger('added')->default(0);
            $table->unsignedInteger('removed')->default(0);
            // اگر این نسخه بازگردانی یک نسخه قدیمی است
            $table->unsignedInteger('restored_from')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['person_text_id', 'revision']);
        });

        // انتقال زندگی‌نامه‌های قبلی به ساختار جدید (به نام آخرین ویرایشگر یا سازنده)
        DB::table('persons')->whereNotNull('biography')->where('biography', '!=', '')
            ->orderBy('id')
            ->each(function ($row) {
                $author = $row->updated_by ?? $row->created_by;
                $segments = json_encode([[$author, $row->biography]], JSON_UNESCAPED_UNICODE);
                $now = now();
                $textId = DB::table('person_texts')->insertGetId([
                    'person_id' => $row->id, 'field' => 'biography', 'segments' => $segments,
                    'plain' => $row->biography, 'revision' => 1, 'updated_by' => $author,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('person_text_revisions')->insert([
                    'person_text_id' => $textId, 'revision' => 1, 'segments' => $segments,
                    'user_id' => $author, 'added' => 0, 'removed' => 0, 'created_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_text_revisions');
        Schema::dropIfExists('person_texts');
    }
};
