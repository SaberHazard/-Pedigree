<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\User;
use App\Support\NationalCode;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * راه‌اندازی اولیه: ساخت مدیر کل (که خودش هم یک شخص در شجره‌نامه است)
 *
 *   php artisan pedigree:install
 */
class InstallCommand extends Command
{
    protected $signature = 'pedigree:install
        {--first-name= : نام}
        {--last-name= : نام خانوادگی}
        {--gender=m : جنسیت m یا f}
        {--phone= : موبایل}
        {--national-code= : کد ملی}
        {--password= : رمز عبور}';

    protected $description = 'Create the first super admin (who is also a person in the family tree)';

    public function handle(): int
    {
        $this->info('راه‌اندازی شجره‌نامه - ساخت مدیر کل');

        $first = $this->option('first-name') ?: $this->ask('نام (First name)');
        $last = $this->option('last-name') ?: $this->ask('نام خانوادگی (Last name)');
        $gender = $this->option('gender') ?: $this->choice('جنسیت', ['m', 'f'], 0);
        $phone = Phone::normalize($this->option('phone') ?: $this->ask('موبایل (برای ورود با پیامک)'));
        $nationalInput = $this->option('national-code') ?? $this->ask('کد ملی (اختیاری، برای ورود با رمز)', '');
        $national = $nationalInput ? NationalCode::normalize($nationalInput) : null;
        $password = $this->option('password') ?: $this->secret('رمز عبور (حداقل ۸ کاراکتر، اختیاری)');

        if (! $phone) {
            $this->error('شماره موبایل معتبر نیست.');

            return self::FAILURE;
        }
        if ($national && ! NationalCode::isValid($national)) {
            $this->error('کد ملی معتبر نیست.');

            return self::FAILURE;
        }
        if (Person::findByPhone($phone)) {
            $this->error('این شماره قبلاً ثبت شده است.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($first, $last, $gender, $phone, $national, $password) {
            $user = User::create(['role' => User::ROLE_SUPER_ADMIN]);
            $person = new Person(['first_name' => $first, 'last_name' => $last, 'gender' => $gender]);
            $person->phone = $phone;
            $person->national_code = $national;
            $person->created_by = $user->id;
            $person->save();

            $user->person_id = $person->id;
            if ($password) {
                $user->password = $password;
                $user->password_changed_at = now();
            }
            $user->save();
        });

        $this->info('مدیر کل ساخته شد. اکنون می‌توانید با موبایل '.$phone.' وارد شوید.');

        return self::SUCCESS;
    }
}
