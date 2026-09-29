<?php

namespace App\Support;

use App\Models\User;

/**
 * «ایجادکننده / نویسنده / آپلودکننده» هر چیزی که در سایت ثبت می‌شود، به یک شکل یکسان برای رابط کاربری:
 * نام کامل، نام کاربری عمومی (مثل تلگرام: @sabertiger) و شناسه پروفایل برای لینک مستقیم.
 */
final class Author
{
    /** @return array{id:int, name:string, username:?string, person_id:?string}|null */
    public static function of(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->displayName(),
            'username' => $user->username,
            'person_id' => $user->person_id,
        ];
    }
}
