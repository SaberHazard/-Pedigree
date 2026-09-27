<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\ActivityController;
use App\Http\Controllers\Api\Admin\PersonAdminController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\LinkRequestController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MarriageController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OpinionController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\ProfileTextController;
use App\Http\Controllers\Api\RelativeController;
use App\Http\Controllers\Api\TreeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API شجره‌نامه (مشترک بین وب‌اپ، اپ اندروید و اپ iOS)
|--------------------------------------------------------------------------
| همه مسیرها با پیشوند /api هستند. مستندات کامل: docs/API.md
| احراز هویت: کوکی سشن (وب) یا هدر Authorization: Bearer <token> (موبایل)
*/

Route::get('bootstrap', [MetaController::class, 'bootstrap']);

// ------------------------------------------------------------------ ورود
Route::prefix('auth')->group(function () {
    Route::get('captcha', [AuthController::class, 'captcha'])->middleware('throttle:30,1');
    Route::post('otp', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me'])->middleware('active');
    });
});

// ------------------------------------------------------------------ مشاهده (با امکان حالت مهمان)
Route::middleware(['viewer', 'active', 'throttle:api'])->group(function () {
    Route::get('persons/{person}', [PersonController::class, 'show']);
    Route::get('persons/{person}/relatives', [PersonController::class, 'relatives']);
    Route::get('persons/{person}/media', [MediaController::class, 'index']);
    Route::get('tree/lineage', [TreeController::class, 'lineage']);
    Route::get('tree/{person}/descendants', [TreeController::class, 'descendants']);
    Route::get('tree/{person}/ancestors', [TreeController::class, 'ancestors']);
    Route::get('tree/{person}/hourglass', [TreeController::class, 'hourglass']);
    Route::get('families', [FamilyController::class, 'index']);
});

// ------------------------------------------------------------------ اعضای واردشده
Route::middleware(['auth:sanctum', 'active', 'throttle:api'])->group(function () {
    // حساب کاربری
    Route::prefix('account')->group(function () {
        Route::put('preferences', [AccountController::class, 'preferences']);
        Route::put('password', [AccountController::class, 'password']);
        Route::put('username', [AccountController::class, 'username']);
        Route::post('phone/otp', [AccountController::class, 'requestPhoneChange'])->middleware('throttle:otp');
        Route::put('phone', [AccountController::class, 'confirmPhoneChange'])->middleware('throttle:otp-verify');
        Route::get('sessions', [AccountController::class, 'sessions']);
        Route::delete('sessions/{id}', [AccountController::class, 'revokeSession']);
        Route::post('devices', [AccountController::class, 'registerDevice']);
        Route::delete('devices', [AccountController::class, 'removeDevice']);
    });

    // اشخاص
    Route::get('persons', [PersonController::class, 'index']);
    Route::post('persons', [PersonController::class, 'store']);
    Route::match(['put', 'patch'], 'persons/{person}', [PersonController::class, 'update']);
    Route::delete('persons/{person}', [PersonController::class, 'destroy']);
    Route::get('persons/{person}/history', [PersonController::class, 'history']);
    Route::get('persons/{person}/relationship/{other}', [PersonController::class, 'relationship']);

    // متن‌های رنگی پروفایل (چکیده، توضیحات، زندگی‌نامه، رزومه) و نسخه‌های آن‌ها
    Route::put('persons/{person}/texts/{field}', [ProfileTextController::class, 'update'])->middleware('throttle:writes');
    Route::get('persons/{person}/texts/{field}/revisions', [ProfileTextController::class, 'revisions']);
    Route::get('persons/{person}/texts/{field}/revisions/{revision}', [ProfileTextController::class, 'showRevision'])->whereNumber('revision');
    Route::post('persons/{person}/texts/{field}/revisions/{revision}/restore', [ProfileTextController::class, 'restore'])->whereNumber('revision');

    // سوابق رزومه
    Route::post('persons/{person}/resume', [ProfileTextController::class, 'storeResume'])->middleware('throttle:writes');
    Route::match(['put', 'patch'], 'resume/{item}', [ProfileTextController::class, 'updateResume']);
    Route::delete('resume/{item}', [ProfileTextController::class, 'destroyResume']);

    // نظرها و امتیاز ویژگی‌ها
    Route::get('persons/{person}/comments', [OpinionController::class, 'comments']);
    Route::post('persons/{person}/comments', [OpinionController::class, 'storeComment'])->middleware('throttle:writes');
    Route::match(['put', 'patch'], 'comments/{comment}', [OpinionController::class, 'updateComment'])->middleware('throttle:writes');
    Route::delete('comments/{comment}', [OpinionController::class, 'destroyComment']);
    Route::post('comments/{comment}/hide', [OpinionController::class, 'hideComment']);
    Route::get('persons/{person}/ratings', [OpinionController::class, 'ratings']);
    Route::put('persons/{person}/ratings', [OpinionController::class, 'rate'])->middleware('throttle:writes');

    // نقشه خاندان
    Route::get('map', [MapController::class, 'index']);

    // بستگان و اتصال درخت‌ها
    Route::post('persons/{person}/relatives', [RelativeController::class, 'store']);
    Route::post('persons/{person}/link', [RelativeController::class, 'link']);
    Route::delete('persons/{person}/parents/{role}', [RelativeController::class, 'unlinkParent']);
    Route::match(['put', 'patch'], 'marriages/{marriage}', [MarriageController::class, 'update']);
    Route::delete('marriages/{marriage}', [MarriageController::class, 'destroy']);
    Route::get('link-requests', [LinkRequestController::class, 'index']);
    Route::post('link-requests/{linkRequest}/accept', [LinkRequestController::class, 'accept']);
    Route::post('link-requests/{linkRequest}/reject', [LinkRequestController::class, 'reject']);
    Route::post('link-requests/{linkRequest}/cancel', [LinkRequestController::class, 'cancel']);

    // عکس و ویدیو
    Route::post('persons/{person}/media', [MediaController::class, 'store'])->middleware('throttle:uploads');
    Route::post('persons/{person}/avatar', [MediaController::class, 'uploadAvatar'])->middleware('throttle:uploads');
    Route::put('persons/{person}/avatar', [MediaController::class, 'setAvatar']);
    Route::get('media/{media}', [MediaController::class, 'show']);
    Route::match(['put', 'patch'], 'media/{media}', [MediaController::class, 'update']);
    Route::delete('media/{media}', [MediaController::class, 'destroy']);
    Route::post('media/{media}/vote', [MediaController::class, 'vote']);
    Route::post('media/{media}/decide', [MediaController::class, 'decide'])->middleware('admin');
    Route::get('approvals', [MediaController::class, 'approvals']);

    // خاندان‌ها
    Route::post('families', [FamilyController::class, 'store']);
    Route::match(['put', 'patch'], 'families/{family}', [FamilyController::class, 'update']);
    Route::delete('families/{family}', [FamilyController::class, 'destroy']);

    // داشبورد و اعلان‌ها
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('dashboard/events', [DashboardController::class, 'events']);
    Route::get('dashboard/activity', [DashboardController::class, 'activity']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

    // خروجی
    Route::get('export/gedcom', [ExportController::class, 'gedcom'])->middleware('throttle:10,1');

    // مدیریت
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::get('activity', [ActivityController::class, 'index']);
        Route::get('trash', [PersonAdminController::class, 'trash']);
        Route::post('trash/{id}/restore', [PersonAdminController::class, 'restore']);
        Route::post('persons/{person}/merge', [PersonAdminController::class, 'merge']);
    });
});
