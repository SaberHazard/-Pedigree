<?php

use App\Http\Controllers\DonateCallbackController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\SpaController;
use App\Http\Controllers\VoiceFileController;
use Illuminate\Support\Facades\Route;

// وب‌اپ (همه صفحات داخل همین یک صفحه با مسیرهای # هستند)
Route::get('/', SpaController::class)->name('app');

// فایل‌های رسانه با لینک امضاشده و زمان‌دار
Route::get('/m/{media}/{variant}', [MediaFileController::class, 'show'])
    ->where('variant', 'original|thumb|medium|poster')
    ->middleware('signed:relative')
    ->name('media.file');

// پیام‌های صوتی با لینک امضاشده و زمان‌دار
Route::get('/v/{voice}', [VoiceFileController::class, 'show'])
    ->whereUuid('voice')
    ->middleware('signed:relative')
    ->name('voice.file');

// بازگشت از درگاه پرداخت (زرین‌پال/زیبال/Pay.ir)؛ تأیید سرور-به-سرور
Route::match(['get', 'post'], '/donate/callback/{token}', DonateCallbackController::class)
    ->middleware('throttle:30,1,donate-callback')
    ->name('donate.callback');
