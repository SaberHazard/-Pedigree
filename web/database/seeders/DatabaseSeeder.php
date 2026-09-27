<?php

namespace Database\Seeders;

use App\Models\Person;
use Illuminate\Database\Seeder;

/**
 * سیدر پیش‌فرض (php artisan db:seed یا migrate --seed).
 *
 * در محیط تولید هیچ داده‌ای نمی‌سازد؛ مدیر کل با «php artisan pedigree:install» ساخته می‌شود.
 * در محیط توسعه (APP_ENV=local) اگر پایگاه‌داده خالی باشد، داده نمونه (DemoSeeder) اضافه می‌شود.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('local') && Person::query()->doesntExist()) {
            $this->call(DemoSeeder::class);

            return;
        }

        $this->command?->info('داده‌ای اضافه نشد. برای ساخت مدیر کل: php artisan pedigree:install — برای داده نمونه: php artisan db:seed --class=DemoSeeder');
    }
}
