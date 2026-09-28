<?php

use App\Services\Sms\SmsTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قالب‌های ثابت پیامک تبریک (فقط مدیر کل تغییر می‌دهد) + قالب‌های پیش‌فرض
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('occasion', 20)->index();
            // نام ثابت قالب‌های پیش‌فرض (برای تنظیم‌های قدیمی کاربران)
            $table->string('slug', 30)->nullable()->unique();
            $table->string('title', 60);
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        foreach (SmsTemplates::DEFAULTS as $i => [$occasion, $slug, $title, $body]) {
            DB::table('sms_templates')->insert([
                'occasion' => $occasion,
                'slug' => $slug,
                'title' => $title,
                'body' => $body,
                'active' => true,
                'sort_order' => $i,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
