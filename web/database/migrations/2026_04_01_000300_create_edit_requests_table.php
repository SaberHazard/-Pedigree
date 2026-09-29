<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * پیشنهاد ویرایش بستگان درجه دو و سه (و ویرایش ازدواج) که پس از تأیید مدیر اعمال می‌شود
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edit_requests', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // person | marriage
            $table->uuid('person_id')->index();
            $table->uuid('marriage_id')->nullable()->index();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('degree')->nullable();
            // مقدار پیشنهادی و مقدار قبلی هر فیلد (رمزنگاری‌شده؛ ممکن است اطلاعات شخصی داشته باشد)
            $table->text('changes');
            $table->text('original');
            $table->string('reason', 300)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 300)->nullable();
            $table->timestamps();
            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_requests');
    }
};
