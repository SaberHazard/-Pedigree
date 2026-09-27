<?php

use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

// وب‌اپ (همه صفحات داخل همین یک صفحه با مسیرهای # هستند)
Route::get('/', SpaController::class)->name('app');

// فایل‌های رسانه با لینک امضاشده و زمان‌دار
Route::get('/m/{media}/{variant}', [MediaFileController::class, 'show'])
    ->where('variant', 'original|thumb|medium|poster')
    ->middleware('signed:relative')
    ->name('media.file');
