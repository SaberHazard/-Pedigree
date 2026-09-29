<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\ActivityController;
use App\Http\Controllers\Api\Admin\DonationAdminController;
use App\Http\Controllers\Api\Admin\EditRequestController;
use App\Http\Controllers\Api\Admin\ErrorReportController;
use App\Http\Controllers\Api\Admin\OverviewController;
use App\Http\Controllers\Api\Admin\PersonAdminController;
use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Admin\SmsReportController;
use App\Http\Controllers\Api\Admin\SmsTemplateController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AssistantController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DonateController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FamilyController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\GreetingController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\KinController;
use App\Http\Controllers\Api\LinkRequestController;
use App\Http\Controllers\Api\MapController;
use App\Http\Controllers\Api\MarriageController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OpinionController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\ProfileTextController;
use App\Http\Controllers\Api\RelativeController;
use App\Http\Controllers\Api\SocialController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\TreeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API شجره‌نامه (مشترک بین وب‌اپ، اپ اندروید و اپ iOS)
|--------------------------------------------------------------------------
| همه مسیرها با پیشوند /api هستند. مستندات کامل: docs/API.md
| احراز هویت: کوکی سشن (وب) یا هدر Authorization: Bearer <token> (موبایل)
*/

Route::get('bootstrap', [MetaController::class, 'bootstrap'])->middleware('throttle:120,1,bootstrap');

// ------------------------------------------------------------------ ورود
Route::prefix('auth')->group(function () {
    Route::get('captcha', [AuthController::class, 'captcha'])->middleware('throttle:30,1,captcha');
    Route::post('otp', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1,register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me'])->middleware('active:pending');
    });
});

// ------------------------------------------------------------------ مشاهده (فقط اعضای واردشده؛ مهمان پاسخ 401 می‌گیرد)
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
    Route::get('u/{username}', [PersonController::class, 'byUsername'])->where('username', '[A-Za-z0-9._-]{1,30}')->middleware('throttle:60,1,by-username');
    Route::post('persons', [PersonController::class, 'store'])->middleware('throttle:writes');
    Route::match(['put', 'patch'], 'persons/{person}', [PersonController::class, 'update'])->middleware('throttle:writes');
    Route::delete('persons/{person}', [PersonController::class, 'destroy']);
    Route::get('persons/{person}/history', [PersonController::class, 'history']);
    Route::get('persons/{person}/relationship/{other}', [PersonController::class, 'relationship']);
    Route::get('persons/{person}/kin', [KinController::class, 'index'])->middleware('throttle:30,1,kin');

    // متن‌های رنگی پروفایل (بیوگرافی، توضیحات، زندگی‌نامه، رزومه) و نسخه‌های آن‌ها
    Route::put('persons/{person}/texts/{field}', [ProfileTextController::class, 'update'])->middleware('throttle:writes');
    Route::get('persons/{person}/texts/{field}/revisions', [ProfileTextController::class, 'revisions']);
    Route::get('persons/{person}/texts/{field}/revisions/{revision}', [ProfileTextController::class, 'showRevision'])->whereNumber('revision');
    Route::post('persons/{person}/texts/{field}/revisions/{revision}/restore', [ProfileTextController::class, 'restore'])->whereNumber('revision');

    // سوابق رزومه
    Route::post('persons/{person}/resume', [ProfileTextController::class, 'storeResume'])->middleware('throttle:writes');
    Route::match(['put', 'patch'], 'resume/{item}', [ProfileTextController::class, 'updateResume'])->middleware('throttle:writes');
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
    Route::post('persons/{person}/relatives', [RelativeController::class, 'store'])->middleware('throttle:writes');
    Route::post('persons/{person}/link', [RelativeController::class, 'link'])->middleware('throttle:writes');
    Route::delete('persons/{person}/parents/{role}', [RelativeController::class, 'unlinkParent']);
    Route::get('marriages/{marriage}', [MarriageController::class, 'show']);
    Route::match(['put', 'patch'], 'marriages/{marriage}', [MarriageController::class, 'update'])->middleware('throttle:writes');
    Route::delete('marriages/{marriage}', [MarriageController::class, 'destroy']);
    Route::get('link-requests', [LinkRequestController::class, 'index']);
    Route::post('link-requests/{linkRequest}/accept', [LinkRequestController::class, 'accept']);
    Route::post('link-requests/{linkRequest}/reject', [LinkRequestController::class, 'reject']);
    Route::post('link-requests/{linkRequest}/cancel', [LinkRequestController::class, 'cancel']);

    // عکس و ویدیو
    Route::post('persons/{person}/media', [MediaController::class, 'store'])->middleware('throttle:uploads');
    Route::post('persons/{person}/avatar', [MediaController::class, 'uploadAvatar'])->middleware('throttle:uploads');
    Route::put('persons/{person}/avatar', [MediaController::class, 'setAvatar']);
    Route::post('persons/{person}/social-avatar', [SocialController::class, 'storeAvatar'])->middleware('throttle:uploads');
    Route::get('social/preview', [SocialController::class, 'preview'])->middleware('throttle:20,1,social-preview');
    Route::get('media/{media}', [MediaController::class, 'show']);
    Route::match(['put', 'patch'], 'media/{media}', [MediaController::class, 'update']);
    Route::delete('media/{media}', [MediaController::class, 'destroy']);
    Route::post('media/{media}/vote', [MediaController::class, 'vote'])->middleware('throttle:writes');
    Route::post('media/{media}/decide', [MediaController::class, 'decide'])->middleware('admin');
    Route::get('approvals', [MediaController::class, 'approvals']);

    // خاندان‌ها
    Route::post('families', [FamilyController::class, 'store'])->middleware('throttle:writes');
    Route::match(['put', 'patch'], 'families/{family}', [FamilyController::class, 'update']);
    Route::delete('families/{family}', [FamilyController::class, 'destroy']);

    // داشبورد و اعلان‌ها
    Route::get('dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('dashboard/events', [DashboardController::class, 'events']);
    Route::get('dashboard/activity', [DashboardController::class, 'activity']);
    // پیام‌رسان داخلی (فقط متن و ایموجی)
    Route::get('messages', [MessageController::class, 'index']);
    Route::get('messages/unread', [MessageController::class, 'unread']);
    Route::post('messages/start', [MessageController::class, 'start'])->middleware('throttle:30,1,message-start');
    Route::get('messages/{conversation}', [MessageController::class, 'show'])->whereNumber('conversation');
    Route::post('messages/{conversation}', [MessageController::class, 'send'])->whereNumber('conversation')->middleware('throttle:40,1,message-send');
    Route::post('messages/{conversation}/voice', [MessageController::class, 'sendVoice'])->whereNumber('conversation')->middleware(['throttle:uploads', 'throttle:12,1,message-voice']);
    Route::post('messages/{conversation}/block', [MessageController::class, 'block'])->whereNumber('conversation');
    Route::delete('direct-messages/{message}', [MessageController::class, 'destroy'])->whereNumber('message');

    // تبریک تولد و پیامک از پنل سایت
    // گروه خاطرات خاندان
    Route::get('group', [GroupController::class, 'index']);
    Route::get('group/messages', [GroupController::class, 'messages']);
    Route::post('group/messages', [GroupController::class, 'send'])->middleware('throttle:30,1,group-send');
    Route::post('group/media', [GroupController::class, 'sendMedia'])->middleware(['throttle:uploads', 'throttle:10,1,group-media']);
    // حمایت از سازنده
    Route::get('donate', [DonateController::class, 'index']);
    Route::post('donate', [DonateController::class, 'start'])->middleware('throttle:6,10,donate-start');
    Route::get('donate/{donation}', [DonateController::class, 'show'])->whereNumber('donation');

    // گفتگو با پشتیبانی
    Route::get('support', [SupportController::class, 'index']);
    Route::post('support/messages', [SupportController::class, 'send'])->middleware('throttle:20,1,support-send');
    Route::post('support/voice', [SupportController::class, 'sendVoice'])->middleware(['throttle:uploads', 'throttle:10,1,support-voice']);
    Route::delete('support/messages/{message}', [SupportController::class, 'destroy'])->whereNumber('message');

    Route::post('group/voice', [GroupController::class, 'sendVoice'])->middleware(['throttle:uploads', 'throttle:12,1,group-voice']);
    Route::post('group/read', [GroupController::class, 'read'])->middleware('throttle:60,1,group-read');
    Route::post('group/messages/{message}/react', [GroupController::class, 'react'])->whereNumber('message');
    Route::post('group/messages/{message}/report', [GroupController::class, 'report'])->whereNumber('message')->middleware('throttle:10,1,group-report');
    Route::delete('group/messages/{message}', [GroupController::class, 'destroy'])->whereNumber('message');

    // بازی‌های خانوادگی
    Route::get('games/question', [GameController::class, 'question'])->middleware('throttle:60,1,games');

    // دستیار هوش مصنوعی
    Route::get('assistant', [AssistantController::class, 'index']);
    Route::post('assistant/chat', [AssistantController::class, 'chat'])->middleware('throttle:8,1,assistant-chat');
    Route::post('assistant/transcribe', [AssistantController::class, 'transcribe'])->middleware(['throttle:uploads', 'throttle:8,1,assistant-stt']);
    Route::post('assistant/speak', [AssistantController::class, 'speak'])->middleware('throttle:10,1,assistant-tts');
    Route::post('assistant/live', [AssistantController::class, 'live'])->middleware('throttle:3,1,assistant-live');
    Route::post('media/{media}/restore', [AssistantController::class, 'restorePhoto'])->middleware('throttle:4,1,ai-restore');

    // گزارش خطای مرورگر برای پنل مدیریت (با سقف تا سیل درخواست دیسک را پر نکند)
    Route::post('client-errors', [ErrorReportController::class, 'client'])->middleware('throttle:10,1,client-errors');

    // بینش‌های خاندان و ابزارهای هوشمند
    Route::get('insights/stats', [InsightController::class, 'stats'])->middleware('throttle:20,1,insights-stats');
    Route::get('insights/consistency', [InsightController::class, 'consistency'])->middleware('throttle:20,1,insights-check');
    Route::get('insights/ai', [InsightController::class, 'aiTools']);
    Route::get('persons/{person}/timeline', [InsightController::class, 'timeline'])->middleware('throttle:30,1,timeline');
    Route::post('persons/{person}/ai/biography', [InsightController::class, 'biography'])->middleware('throttle:4,1,ai-bio');
    Route::post('media/{media}/ai/read', [InsightController::class, 'readPhoto'])->middleware('throttle:6,1,ai-read');

    Route::get('greetings', [GreetingController::class, 'index']);
    Route::post('greetings/sms', [GreetingController::class, 'send'])->middleware('throttle:10,1,greeting-send');
    Route::post('greetings/preview', [GreetingController::class, 'preview'])->middleware('throttle:90,1,greeting-preview');
    Route::put('greetings/auto', [GreetingController::class, 'updateAuto'])->middleware('throttle:writes');

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);

    // خروجی
    Route::get('export/gedcom', [ExportController::class, 'gedcom'])->middleware('throttle:10,1,gedcom');

    // مدیریت
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::get('activity', [ActivityController::class, 'index']);
        Route::get('trash', [PersonAdminController::class, 'trash']);
        Route::post('trash/{id}/restore', [PersonAdminController::class, 'restore']);
        Route::post('persons/{person}/merge', [PersonAdminController::class, 'merge']);
        Route::get('sms-messages', [SmsReportController::class, 'index']);
        // تنظیمات و کلیدهای API (فقط مدیر کل)
        Route::get('settings', [SettingsController::class, 'index']);
        Route::put('settings', [SettingsController::class, 'update'])->middleware('throttle:30,1,settings-update');
        Route::post('settings/test', [SettingsController::class, 'test'])->middleware('throttle:10,1,settings-test');

        // نمای کلی، سلامت سرور و ابزارهای کنترلی
        Route::get('overview', [OverviewController::class, 'index'])->middleware('throttle:30,1,admin-overview');
        Route::post('users/{user}/logout-all', [OverviewController::class, 'logoutEverywhere'])->middleware('throttle:20,1,admin-logout');
        Route::post('users/{user}/approve', [UserController::class, 'approve'])->middleware('throttle:60,1,admin-approve');
        Route::get('edit-requests', [EditRequestController::class, 'index']);
        Route::get('support', [SupportController::class, 'threads']);
        Route::get('support/{thread}', [SupportController::class, 'thread'])->whereNumber('thread');
        Route::post('support/{thread}/messages', [SupportController::class, 'reply'])->whereNumber('thread')->middleware('throttle:60,1,support-reply');
        Route::post('support/{thread}/voice', [SupportController::class, 'replyVoice'])->whereNumber('thread')->middleware(['throttle:uploads', 'throttle:20,1,support-reply-voice']);
        Route::post('edit-requests/{editRequest}/approve', [EditRequestController::class, 'approve'])->middleware('throttle:120,1,edit-approve');
        Route::post('edit-requests/{editRequest}/reject', [EditRequestController::class, 'reject'])->middleware('throttle:120,1,edit-reject');
        Route::post('users/{user}/reject', [UserController::class, 'reject'])->middleware('throttle:60,1,admin-reject');
        Route::post('broadcast', [OverviewController::class, 'broadcast'])->middleware(['super-admin', 'throttle:3,60,admin-broadcast']);

        // گروه خاندان: سنجاق، گزارش‌ها، سکوت
        Route::post('group/messages/{message}/pin', [GroupController::class, 'pin'])->whereNumber('message');
        Route::get('group/reports', [GroupController::class, 'reports']);
        Route::post('group/reports/{message}/resolve', [GroupController::class, 'resolve'])->whereNumber('message');
        Route::post('users/{user}/group-mute', [GroupController::class, 'mute']);

        // قالب‌های ثابت پیامک تبریک (فقط مدیر کل)
        Route::middleware('super-admin')->group(function () {
            // خطاهای ثبت‌شده سرور و مرورگر
            Route::get('errors', [ErrorReportController::class, 'index']);
            Route::post('errors/resolve-all', [ErrorReportController::class, 'resolveAll']);
            Route::delete('errors/resolved', [ErrorReportController::class, 'clearResolved']);
            Route::post('errors/{report}/resolve', [ErrorReportController::class, 'resolve'])->whereNumber('report');
            Route::get('donations', [DonationAdminController::class, 'index']);
            Route::post('donation-accounts', [DonationAdminController::class, 'store'])->middleware('throttle:30,1,donate-acc');
            Route::post('donation-accounts/reorder', [DonationAdminController::class, 'reorder']);
            Route::put('donation-accounts/{account}', [DonationAdminController::class, 'update'])->whereNumber('account');
            Route::delete('donation-accounts/{account}', [DonationAdminController::class, 'destroy'])->whereNumber('account');

            Route::get('sms-templates', [SmsTemplateController::class, 'index']);
            Route::post('sms-templates', [SmsTemplateController::class, 'store'])->middleware('throttle:30,1,tpl-store');
            Route::post('sms-templates/preview', [SmsTemplateController::class, 'preview'])->middleware('throttle:120,1,tpl-preview');
            Route::post('sms-templates/reorder', [SmsTemplateController::class, 'reorder'])->middleware('throttle:30,1,tpl-reorder');
            Route::post('sms-templates/defaults', [SmsTemplateController::class, 'restoreDefaults'])->middleware('throttle:5,1,tpl-defaults');
            Route::put('sms-templates/{template}', [SmsTemplateController::class, 'update'])->whereNumber('template')->middleware('throttle:60,1,tpl-update');
            Route::delete('sms-templates/{template}', [SmsTemplateController::class, 'destroy'])->whereNumber('template');
        });
    });
});
