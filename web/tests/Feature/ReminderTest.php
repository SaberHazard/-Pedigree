<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\ReminderSetting;
use App\Models\User;
use App\Notifications\Channels\PushChannel;
use App\Notifications\OccasionDigest;
use App\Notifications\ReminderDue;
use App\Services\Reminders\ReminderSchedule;
use App\Services\Reminders\ReminderService;
use App\Support\Hijri;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * هشدارها: زمان دقیق به وقت تهران برای همه انواع تکرار، زنگ یک‌باره با زمان‌بند (بدون تکرار و بدون زنگ خیلی دیر)،
 * یادآوری روزانه مناسبت‌ها، فهرست زنگ‌های گوشی و مالکیت.
 */
class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        // ۵ فروردین ۱۴۰۵، ساعت ۱۱:۳۰ تهران
        $this->travelTo(CarbonImmutable::parse('2026-03-25 08:00:00', 'UTC'));
        config(['services.fcm.enabled' => false]);
        $this->me = User::factory()->withPerson(['gender' => 'm', 'first_name' => 'علی', 'last_name' => 'کریمی'])->create()->refresh();
    }

    private function reminder(array $attrs): Reminder
    {
        $r = new Reminder($attrs + ['title' => 'آزمایش', 'time' => '09:00', 'repeat' => 'none', 'remind_before' => 0, 'active' => true]);
        $r->user_id = $this->me->id;

        return app(ReminderService::class)->refresh($r);
    }

    private function tehran(?\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone('Asia/Tehran')->format('Y-m-d H:i');
    }

    public function test_one_off_and_daily_fire_at_exact_tehran_time(): void
    {
        $once = $this->reminder(['starts_on' => '1405-01-10', 'time' => '09:30']);
        $this->assertSame('2026-03-30 06:00:00', $once->next_at->utc()->format('Y-m-d H:i:s')); // تهران UTC+3:30 بدون ساعت تابستانی

        $past = $this->reminder(['starts_on' => '1405-01-05', 'time' => '09:00']);
        $this->assertNull($past->next_at);

        $daily = $this->reminder(['starts_on' => '1405-01-01', 'time' => '07:00', 'repeat' => 'daily']);
        $this->assertSame('2026-03-26 07:00', $this->tehran($daily->next_at));
        $later = $this->reminder(['starts_on' => '1405-01-01', 'time' => '23:59', 'repeat' => 'daily']);
        $this->assertSame('2026-03-25 23:59', $this->tehran($later->next_at));

        $weekly = $this->reminder(['starts_on' => '1405-01-02', 'time' => '10:00', 'repeat' => 'weekly']); // یکشنبه‌ها
        $this->assertSame('2026-03-29 10:00', $this->tehran($weekly->next_at));

        // «یک روز قبل» یادآوری
        $before = $this->reminder(['starts_on' => '1405-01-10', 'time' => '09:30', 'remind_before' => 1440]);
        $this->assertSame('2026-03-29 09:30', $this->tehran($before->next_at));

        // تا تاریخ پایان
        $until = $this->reminder(['starts_on' => '1405-01-01', 'time' => '07:00', 'repeat' => 'daily', 'until_on' => '1405-01-05']);
        $this->assertNull($until->next_at);
    }

    public function test_month_ends_leap_years_and_hijri_repeats(): void
    {
        $schedule = app(ReminderSchedule::class);
        $days = fn (Reminder $r, string $from, string $to) => array_map(fn ($d) => $d->format('Y-m-d'), $schedule->between($r, CarbonImmutable::parse($from, 'Asia/Tehran'), CarbonImmutable::parse($to, 'Asia/Tehran')));

        // ۳۱ شهریور ماهانه: مهر ۳۰ روزه ← ۳۰ مهر
        $monthly = $this->reminder(['starts_on' => '1405-06-31', 'time' => '08:00', 'repeat' => 'monthly']);
        $this->assertSame(['2026-09-22', '2026-10-22', '2026-11-21'], $days($monthly, '2026-09-01', '2026-11-30'));

        // ۳۰ اسفند ۱۴۰۳ (کبیسه) سالانه: ۱۴۰۴ تا ۱۴۰۷ ← ۲۹ اسفند، ۱۴۰۸ (کبیسه) ← ۳۰ اسفند
        $yearly = $this->reminder(['starts_on' => '1403-12-30', 'time' => '08:00', 'repeat' => 'yearly']);
        $list = $days($yearly, '2025-01-01', '2030-12-31');
        $this->assertSame(['2025-03-20', '2026-03-20', '2027-03-20', '2028-03-19', '2029-03-19', '2030-03-20'], $list);

        // سالانه قمری (مثلاً ۱ شوال): مطابق تقویم قمری
        $hijri = $this->reminder(['starts_on' => '1404-12-29', 'time' => '06:00', 'repeat' => 'yearly_hijri']);
        [$gy, $gm, $gd] = Hijri::toGregorian(1448, 10, 1);
        $this->assertSame(sprintf('%04d-%02d-%02d 06:00', $gy, $gm, $gd), $this->tehran($hijri->next_at));

        $monthlyHijri = $this->reminder(['starts_on' => '1404-12-29', 'time' => '06:00', 'repeat' => 'monthly_hijri']);
        [$gy, $gm, $gd] = Hijri::toGregorian(1447, 11, 1);
        $this->assertSame(sprintf('%04d-%02d-%02d 06:00', $gy, $gm, $gd), $this->tehran($monthlyHijri->next_at));
    }

    public function test_scheduler_rings_once_advances_and_skips_stale_alarms(): void
    {
        $daily = $this->reminder(['starts_on' => '1405-01-01', 'time' => '11:29', 'repeat' => 'daily']);
        $once = $this->reminder(['starts_on' => '1405-01-05', 'time' => '11:40', 'note' => 'قرص فشار']);
        $this->travelTo(CarbonImmutable::parse('2026-03-25 08:10:30', 'UTC')); // ۱۱:۴۰:۳۰ تهران

        $stats = app(ReminderService::class)->dispatchDue();
        $this->assertSame(1, $stats['sent']); // فقط یک‌باره؛ روزانه فردا
        $this->assertFalse($once->fresh()->active);
        $this->assertNull($once->fresh()->next_at);
        $n = $this->me->notifications()->first();
        $this->assertSame('reminder', $n->data['kind']);
        $this->assertStringContainsString('قرص فشار', $n->data['body']);
        $this->assertStringContainsString('۱۱:۴۰', $n->data['body']);
        $this->assertSame('#/calendar?date=1405-01-05', $n->data['link']);

        // اجرای دوباره در همان دقیقه: زنگ تکراری نمی‌رود
        $this->assertSame(0, app(ReminderService::class)->dispatchDue()['sent']);
        $this->assertSame(1, $this->me->notifications()->count());

        // سرور ۸ ساعت خاموش بوده: زنگ روزانه خیلی دیرشده فرستاده نمی‌شود ولی زمان بعدی درست جلو می‌رود
        $this->travelTo(CarbonImmutable::parse('2026-03-26 16:00:00', 'UTC'));
        $this->assertSame(0, app(ReminderService::class)->dispatchDue()['sent']);
        $this->assertSame('2026-03-27 11:29', $this->tehran($daily->fresh()->next_at));

        // زنگ کمی دیرتر (۱۰ دقیقه) با برچسب «با تأخیر» می‌رود
        $this->travelTo(CarbonImmutable::parse('2026-03-27 08:09:00', 'UTC')); // ۱۱:۳۹ تهران
        $this->assertSame(1, app(ReminderService::class)->dispatchDue()['sent']);
        $this->assertStringContainsString('با تأخیر', $this->me->notifications()->latest()->first()->data['title']);

        // عضو غیرفعال زنگ نمی‌گیرد
        $this->me->forceFill(['status' => User::STATUS_BLOCKED])->save();
        $this->travelTo(CarbonImmutable::parse('2026-03-28 08:00:00', 'UTC'));
        $this->assertSame(0, app(ReminderService::class)->dispatchDue()['sent']);
    }

    public function test_daily_occasion_digest_at_chosen_time(): void
    {
        $father = User::factory()->withPerson(['gender' => 'm', 'first_name' => 'حسن', 'last_name' => 'کریمی', 'birth_date' => '1340-01-06'])->create()->refresh();
        $this->me->person->forceFill(['father_id' => $father->person_id])->save();
        $setting = new ReminderSetting(['enabled' => true, 'time' => '11:25', 'categories' => ['birthday', 'official'], 'days_before' => [0, 1], 'degree' => 2]);
        $setting->user_id = $this->me->id;
        $setting->save();

        // زمان‌بند ساعت ۱۱:۲۵ یک دقیقه جا انداخته؛ ۱۱:۳۰ هنوز فرستاده می‌شود و فقط یک بار
        $stats = app(ReminderService::class)->dispatchDue();
        $this->assertSame(1, $stats['digests']);
        $this->assertSame(0, app(ReminderService::class)->dispatchDue()['digests']);
        $n = $this->me->notifications()->first();
        $this->assertSame('occasion_digest', $n->data['kind']);
        $this->assertStringContainsString('فردا', $n->data['body']);
        $this->assertStringContainsString('تولد حسن کریمی', $n->data['body']);
        $this->assertSame('#/calendar?date=1405-01-06', $n->data['link']);

        // تولد غیر بستگان و دسته‌های خاموش نمی‌آیند
        $setting->forceFill(['categories' => ['anniversary']])->save();
        Cache::flush();
        $this->assertSame(0, app(ReminderService::class)->dispatchDue()['digests']);
    }

    public function test_api_validation_and_ownership(): void
    {
        $api = $this->actingAs($this->me, 'sanctum');
        $res = $api->postJson('/api/reminders', ['title' => "دارو\x07", 'note' => 'بعد از صبحانه', 'starts_on' => '1405-01-06', 'time' => '08:15', 'repeat' => 'daily', 'remind_before' => 10])->assertCreated();
        $this->assertSame('دارو', $res->json('data.title'));
        $this->assertStringContainsString('۶ فروردین ۱۴۰۵ ساعت ۰۸:۰۵', $res->json('message'));
        $id = $res->json('data.id');

        foreach ([
            ['starts_on' => '1404-12-30'], // ۱۴۰۴ کبیسه نیست
            ['starts_on' => '1405-13-01'],
            ['starts_on' => '1200-01-01'],
            ['time' => '24:00'],
            ['time' => '8:15'],
            ['repeat' => 'hourly'],
            ['remind_before' => 7],
            ['until_on' => '1405-01-01'], // پیش از شروع
            ['title' => ''],
            ['title' => str_repeat('ا', 121)],
        ] as $bad) {
            $api->postJson('/api/reminders', $bad + ['title' => 'x', 'starts_on' => '1405-01-06', 'time' => '08:15', 'repeat' => 'daily'])->assertStatus(422);
        }

        $other = User::factory()->withPerson()->create();
        $this->actingAs($other, 'sanctum')->putJson("/api/reminders/{$id}", ['title' => 'hack', 'starts_on' => '1405-01-06', 'time' => '08:15', 'repeat' => 'none'])->assertNotFound();
        $this->actingAs($other, 'sanctum')->deleteJson("/api/reminders/{$id}")->assertNotFound();
        $this->assertSame([], $this->actingAs($other, 'sanctum')->getJson('/api/reminders')->json('data'));
        $this->assertSame('دارو', Reminder::query()->find($id)->title);

        $this->actingAs($this->me, 'sanctum')->putJson("/api/reminders/{$id}", ['title' => 'دارو شب', 'starts_on' => '1405-01-06', 'time' => '21:00', 'repeat' => 'daily', 'active' => false])->assertOk();
        $this->assertNull(Reminder::query()->find($id)->next_at);
        $this->actingAs($this->me, 'sanctum')->getJson('/api/reminders')->assertJsonPath('data.0.title', 'دارو شب');
        $this->actingAs($this->me, 'sanctum')->deleteJson("/api/reminders/{$id}")->assertOk();
        $this->assertNull(Reminder::query()->find($id));

        $this->actingAs($this->me, 'sanctum')->putJson('/api/reminders/settings', ['enabled' => true, 'time' => '25:00', 'categories' => [], 'days_before' => [], 'degree' => 2])->assertStatus(422);
        $this->actingAs($this->me, 'sanctum')->putJson('/api/reminders/settings', ['enabled' => true, 'time' => '07:00', 'categories' => ['hack'], 'days_before' => [0], 'degree' => 2])->assertStatus(422);
        $this->actingAs($this->me, 'sanctum')->putJson('/api/reminders/settings', ['enabled' => true, 'time' => '07:00', 'categories' => ['birthday'], 'days_before' => [0, 1], 'degree' => 3])->assertOk();
        $this->assertSame(3, ReminderSetting::query()->find($this->me->id)->degree);
    }

    public function test_guests_cannot_use_reminders(): void
    {
        $this->getJson('/api/reminders')->assertUnauthorized();
        $this->postJson('/api/reminders', [])->assertUnauthorized();
        $this->getJson('/api/reminders/upcoming')->assertUnauthorized();
    }

    public function test_calendar_shows_my_reminders_on_their_days(): void
    {
        $this->reminder(['title' => 'قسط وام', 'starts_on' => '1405-01-15', 'time' => '10:00', 'repeat' => 'monthly']);
        $days = $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar?y=1405&m=2')->assertOk()->json('data.days');
        $this->assertSame('قسط وام', $days[14]['reminders'][0]['title']);
        $this->assertSame([], $days[13]['reminders']);

        $other = User::factory()->withPerson()->create();
        $days = $this->actingAs($other, 'sanctum')->getJson('/api/calendar?y=1405&m=2')->json('data.days');
        $this->assertSame([], $days[14]['reminders']);
    }

    public function test_phone_alarm_list_and_push_only_when_phone_does_not_have_it(): void
    {
        config(['services.fcm.enabled' => true]);
        $r = $this->reminder(['title' => 'جلسه', 'starts_on' => '1405-01-06', 'time' => '10:00', 'repeat' => 'daily']);
        $this->travel(5)->seconds();

        $items = $this->actingAs($this->me, 'sanctum')->getJson('/api/reminders/upcoming?days=3&native=1')->assertOk()->json('data');
        $this->assertCount(3, $items);
        $this->assertSame(CarbonImmutable::parse('2026-03-26 10:00', 'Asia/Tehran')->getTimestamp() * 1000, $items[0]['at']);
        $this->assertSame('⏰ جلسه', $items[0]['title']);
        $prefs = $this->me->fresh()->preferences;
        $this->assertIsInt($prefs['local_alarms_at']);

        // گوشی این هشدار را دارد: پوش تکراری نمی‌رود
        $this->assertNotContains(PushChannel::class, (new ReminderDue($r->fresh(), 'x', '1405-01-06'))->via($this->me->fresh()));

        // هشدار بعد از آخرین دریافت گوشی ویرایش شده: پوش پرصدا می‌رود
        $this->travel(5)->seconds();
        $r->forceFill(['title' => 'جلسه مهم'])->save();
        $via = (new ReminderDue($r->fresh(), 'x', '1405-01-06'))->via($this->me->fresh());
        $this->assertContains(PushChannel::class, $via);
        $this->assertTrue((new ReminderDue($r->fresh(), 'x', '1405-01-06'))->toPush($this->me)['alarm']);

        // بیرون از بازه فهرست گوشی هم پوش می‌رود
        $this->travel(4)->days();
        $this->assertContains(PushChannel::class, (new OccasionDigest('t', 'b', '#/', now()->subYear()->getTimestamp()))->via($this->me->fresh()));

        // سایت (بدون native) چیزی در ترجیحات عوض نمی‌کند و حداکثر ۶۰ روز
        $this->actingAs($this->me, 'sanctum')->getJson('/api/reminders/upcoming?days=61')->assertStatus(422);
    }
}
