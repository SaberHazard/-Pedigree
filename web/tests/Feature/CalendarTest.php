<?php

namespace Tests\Feature;

use App\Models\CalendarDay;
use App\Models\HijriMonth;
use App\Models\Marriage;
use App\Models\User;
use App\Services\Calendar\HijriCalendar;
use App\Services\Calendar\OfficialCalendarSync;
use App\Services\Social\SafeHttp;
use App\Support\Hijri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تقویم فارسی: سال‌های کبیسه و طول ماه‌ها، روز هفته، تاریخ قمری مطابق تقویم رسمی، همگام‌سازی امن با holidayapi.ir،
 * مناسبت‌های خانوادگی، تبدیل تاریخ و ساعت سرور.
 */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 3, 25)->setTime(8, 0));
        config(['pedigree.calendar.official_sync' => true, 'pedigree.calendar.proxy' => null, 'pedigree.network.location' => 'iran']);
        SafeHttp::fakeResolver(fn () => ['185.143.233.120']);
        $this->me = User::factory()->withPerson(['gender' => 'm', 'first_name' => 'علی', 'last_name' => 'کریمی'])->create()->refresh();
    }

    private function month(int $y, int $m): array
    {
        return $this->actingAs($this->me, 'sanctum')->getJson("/api/calendar?y={$y}&m={$m}")->assertOk()->json('data');
    }

    public function test_leap_years_and_month_lengths(): void
    {
        foreach ([1399 => 30, 1403 => 30, 1404 => 29, 1405 => 29, 1408 => 30] as $year => $esfand) {
            $data = $this->month($year, 12);
            $this->assertSame($esfand, $data['length'], "Esfand {$year}");
            $this->assertSame($esfand === 30, $data['leap']);
            $this->assertCount($esfand, $data['days']);
        }
        $this->assertCount(31, $this->month(1405, 1)['days']);
        $this->assertCount(31, $this->month(1405, 6)['days']);
        $this->assertCount(30, $this->month(1405, 7)['days']);
        $this->assertCount(30, $this->month(1405, 11)['days']);

        // سال‌های خیلی دور گذشته و آینده هم کار می‌کنند؛ بیرون از بازه خطای کنترل‌شده
        $this->assertCount(31, $this->month(1, 1)['days']);
        $this->assertCount(31, $this->month(3177, 1)['days']);
        $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar?y=3178&m=1')->assertStatus(422);
        $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar?y=1405&m=13')->assertStatus(422);
    }

    public function test_weekdays_three_calendars_and_fridays(): void
    {
        $data = $this->month(1405, 1);
        $first = $data['days'][0];
        $this->assertSame('1405-01-01', $first['date']);
        $this->assertSame(0, $first['weekday']); // شنبه
        $this->assertSame([2026, 3, 21], $first['g']);
        $this->assertTrue($first['holiday']);
        $this->assertTrue($data['days'][4]['today']); // ۵ فروردین = ۲۵ مارس
        foreach ($data['days'] as $d) {
            if ($d['weekday'] === 6) {
                $this->assertTrue($d['holiday'], 'Fridays are holidays');
            }
        }
        $titles = array_column($data['days'][0]['events'], 'title');
        $this->assertContains('جشن نوروز / جشن سال نو', $titles);
        $this->assertContains('روز جهانی نوروز', $titles);
        $this->assertStringContainsString('مارس', $data['gregorian_label']);
    }

    public function test_hijri_dates_follow_official_months_when_known(): void
    {
        // بدون تقویم رسمی: محاسبه حسابی (عید فطر ۱۴۴۷ روز ۲۹ اسفند ۱۴۰۴)
        $esfand = $this->month(1404, 12);
        $this->assertSame([1447, 10, 1], $esfand['days'][28]['h']);
        $this->assertContains('عید سعید فطر', array_column($esfand['days'][28]['events'], 'title'));

        // آغاز رسمی رمضان و شوال ۱۴۴۷ (یک روز دیرتر از محاسبه)
        HijriMonth::query()->insert([
            ['year' => 1447, 'month' => 9, 'starts_on' => '2026-02-19', 'source' => 'official'],
            ['year' => 1447, 'month' => 10, 'starts_on' => '2026-03-21', 'source' => 'official'],
        ]);
        app(HijriCalendar::class)->forget();

        $this->assertSame([1447, 9, 30], $this->month(1404, 12)['days'][28]['h']);
        $farvardin = $this->month(1405, 1);
        $this->assertSame([1447, 10, 1], $farvardin['days'][0]['h']);
        $this->assertContains('عید سعید فطر', array_column($farvardin['days'][0]['events'], 'title'));

        // پس از آخرین ماه رسمی: بی‌پرش و بی‌تکرار روز، و رفت‌وبرگشت دقیق
        $hijri = app(HijriCalendar::class);
        $jdn = Hijri::gregorianToJdn(2026, 1, 1);
        $prev = null;
        for ($i = 0; $i < 400; $i++) {
            $g = Hijri::jdnToGregorian($jdn + $i);
            $h = $hijri->fromGregorian(...$g);
            $this->assertSame($g, $hijri->toGregorian(...$h));
            if ($prev) {
                $sameMonth = $h[0] === $prev[0] && $h[1] === $prev[1] && $h[2] === $prev[2] + 1;
                $newMonth = $h[2] === 1 && in_array($prev[2], [29, 30], true);
                $this->assertTrue($sameMonth || $newMonth, 'continuous at '.implode('-', $g));
            }
            $prev = $h;
        }
    }

    public function test_official_sync_is_validated_and_calibrates_hijri_months(): void
    {
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request->url();
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/jalali/1405/01/01' => Http::response(['is_holiday' => true, 'events' => [
                    ['description' => 'جشن نوروز/جشن سال نو', 'additional_description' => '۱ فروردین', 'is_holiday' => true, 'is_religious' => false],
                    ['description' => 'عید سعید فطر', 'additional_description' => '۱ شوال', 'is_holiday' => true, 'is_religious' => true],
                    ['description' => '<script>alert(1)</script>روز جهانی شعر', 'additional_description' => 'March 21 1900', 'is_holiday' => false, 'is_religious' => false],
                ]]),
                '/jalali/1405/01/02' => Http::response(['is_holiday' => true, 'events' => [
                    ['description' => 'جمعه', 'additional_description' => '', 'is_holiday' => true, 'is_religious' => false],
                    ['description' => 'تعطیل به مناسبت عید سعید فطر', 'additional_description' => '۲ شوال', 'is_holiday' => true, 'is_religious' => true],
                ]]),
                '/jalali/1405/01/03' => Http::response('<html>bad gateway</html>', 502),
                '/jalali/1405/01/04' => Http::response(['unexpected' => 'shape']),
                default => Http::response(['is_holiday' => false, 'events' => []]),
            };
        });

        $sync = app(OfficialCalendarSync::class);
        $stats = $sync->sync([1405], 6);
        $this->assertSame(4, $stats['fetched']);
        $this->assertSame(2, $stats['failed']);
        $this->assertCount(6, $requests);
        foreach ($requests as $url) {
            $this->assertStringStartsWith('https://holidayapi.ir/jalali/1405/01/', $url);
        }

        $day = CalendarDay::query()->find('1405-01-01');
        $this->assertTrue($day->is_holiday);
        $this->assertSame('2026-03-21', $day->gregorian->format('Y-m-d'));
        $this->assertStringNotContainsString('<', json_encode($day->events, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('جمعه', array_column(CalendarDay::query()->find('1405-01-02')->events, 'title'));
        $this->assertNull(CalendarDay::query()->find('1405-01-03'));
        $this->assertNull(CalendarDay::query()->find('1405-01-04'));

        // آغاز رسمی شوال ۱۴۴۷ از یادداشت «۱ شوال» و «۲ شوال»
        $month = HijriMonth::query()->where('year', 1447)->where('month', 10)->first();
        $this->assertSame('2026-03-21', substr((string) $month->starts_on, 0, 10));
        $this->assertSame([1447, 10, 1], app(HijriCalendar::class)->fromGregorian(2026, 3, 21));

        // روزهای گذشته دوباره گرفته نمی‌شوند
        $requests = [];
        $this->travelTo(now()->setDate(2026, 4, 30));
        $sync->sync([1405], 2);
        $this->assertNotContains('https://holidayapi.ir/jalali/1405/01/01', $requests);

        // در تقویم: داده رسمی با برچسب مناسبت
        $data = $this->month(1405, 1);
        $this->assertTrue($data['days'][0]['official']);
        $religious = collect($data['days'][0]['events'])->firstWhere('title', 'عید سعید فطر');
        $this->assertSame('religious', $religious['kind']);
        $this->assertSame('international', collect($data['days'][0]['events'])->firstWhere('note', 'March 21 1900')['kind']);

        // ماه دستی مدیر بازنویسی نمی‌شود
        $month->forceFill(['starts_on' => '2026-03-20', 'source' => 'manual'])->save();
        $sync->calibrateHijri();
        $this->assertSame('2026-03-20', substr((string) $month->fresh()->starts_on, 0, 10));
    }

    public function test_sync_stops_quickly_when_service_is_down_and_can_be_disabled(): void
    {
        $count = 0;
        Http::fake(function () use (&$count) {
            $count++;

            return Http::response('', 503);
        });
        $stats = app(OfficialCalendarSync::class)->sync([1405, 1406], 500);
        $this->assertSame(0, $stats['fetched']);
        $this->assertSame(5, $count);

        config(['pedigree.calendar.official_sync' => false]);
        $count = 0;
        app(OfficialCalendarSync::class)->sync([1405], 10);
        $this->assertSame(0, $count);
    }

    public function test_parse_hijri_notes(): void
    {
        $this->assertSame([13, 7], OfficialCalendarSync::parseHijriNote('۱۳ رجب'));
        $this->assertSame([1, 10], OfficialCalendarSync::parseHijriNote('1 شوال'));
        $this->assertSame([10, 12], OfficialCalendarSync::parseHijriNote('۱۰ ذوالحجه'));
        $this->assertSame([25, 11], OfficialCalendarSync::parseHijriNote('۲۵ ذی‌القعده'));
        $this->assertSame([3, 6], OfficialCalendarSync::parseHijriNote('۳ جمادی‌الثانی'));
        $this->assertNull(OfficialCalendarSync::parseHijriNote('۱۳ دی 1338'));
        $this->assertNull(OfficialCalendarSync::parseHijriNote('March 21'));
        $this->assertNull(OfficialCalendarSync::parseHijriNote('۳۵ رجب'));
    }

    public function test_family_occasions_only_for_own_relatives(): void
    {
        $father = User::factory()->withPerson(['gender' => 'm', 'first_name' => 'حسن', 'last_name' => 'کریمی', 'birth_date' => '1340-01-05'])->create()->refresh();
        $mother = User::factory()->withPerson(['gender' => 'f', 'first_name' => 'زهرا', 'last_name' => 'احمدی'])->create()->refresh();
        $this->me->person->forceFill(['father_id' => $father->person_id, 'mother_id' => $mother->person_id])->save();
        Marriage::query()->create(['husband_id' => $father->person_id, 'wife_id' => $mother->person_id, 'status' => 'married', 'marriage_date' => '1365-01-20']);
        // غریبه (بی‌نسبت) نباید در تقویم من بیاید
        User::factory()->withPerson(['gender' => 'm', 'first_name' => 'غریبه', 'birth_date' => '1350-01-05'])->create();

        $days = $this->month(1405, 1)['days'];
        $fifth = collect($days[4]['family']);
        $this->assertCount(1, $fifth);
        $this->assertSame('birthday', $fifth[0]['type']);
        $this->assertSame(65, $fifth[0]['years']);
        $this->assertSame('anniversary', $days[19]['family'][0]['type']);
        $this->assertSame(40, $days[19]['family'][0]['years']);
    }

    public function test_convert_and_server_time(): void
    {
        $r = $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar/convert?cal=jalali&y=1405&m=1&d=1')->assertOk()->json('data');
        $this->assertSame([2026, 3, 21], $r['gregorian']);
        $this->assertSame('شنبه', $r['weekday_name']);
        $this->assertSame([1447, 10, 2], $r['hijri']); // بدون تقویم رسمی: حسابی

        $r = $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar/convert?cal=gregorian&y=2024&m=2&d=29')->assertOk()->json('data');
        $this->assertSame([1402, 12, 10], $r['jalali']);

        $r = $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar/convert?cal=hijri&y=1447&m=10&d=1')->assertOk()->json('data');
        $this->assertSame([2026, 3, 20], $r['gregorian']); // بدون تقویم رسمی: حسابی

        $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar/convert?cal=jalali&y=1404&m=12&d=30')->assertStatus(422); // ۱۴۰۴ کبیسه نیست
        $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar/convert?cal=gregorian&y=2023&m=2&d=29')->assertStatus(422);
        $this->actingAs($this->me, 'sanctum')->getJson('/api/calendar/convert?cal=mars&y=1&m=1&d=1')->assertStatus(422);

        $t = $this->actingAs($this->me, 'sanctum')->getJson('/api/time')->assertOk();
        $this->assertStringContainsString('no-store', (string) $t->headers->get('Cache-Control'));
        $this->assertEqualsWithDelta(now()->getTimestamp() * 1000, $t->json('data.epoch_ms'), 2000);
        $this->assertSame([1405, 1, 5], $t->json('data.jalali'));
    }

    public function test_admin_sync_button(): void
    {
        Http::fake(['holidayapi.ir/*' => Http::response(['is_holiday' => false, 'events' => []])]);
        $admin = User::factory()->withPerson()->admin()->create()->refresh();
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'calendar'])->assertOk();
        $this->assertStringContainsString('اتصال برقرار است', $res->json('message'));
        $this->assertSame(13, CalendarDay::query()->count());
        $this->actingAs($this->me, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'calendar'])->assertForbidden();

        $group = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings')->json('data'))->firstWhere('key', 'calendar');
        $this->assertTrue($group['status']['configured']);

        config(['pedigree.calendar.official_sync' => false]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'calendar'])->assertStatus(422);
    }

    public function test_calendar_requires_login(): void
    {
        $this->getJson('/api/calendar')->assertUnauthorized();
        $this->getJson('/api/time')->assertUnauthorized();
    }
}
