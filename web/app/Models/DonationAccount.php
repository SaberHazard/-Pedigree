<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * شماره کارت، شبا، شماره حساب یا لینک پرداخت (بلو، زرین‌لینک، PayPal و ...) برای حمایت از سازنده
 */
class DonationAccount extends Model
{
    public const KINDS = ['card' => 'شماره کارت', 'sheba' => 'شماره شبا', 'account' => 'شماره حساب', 'link' => 'لینک پرداخت'];

    protected $fillable = ['kind', 'title', 'holder', 'value', 'note', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
