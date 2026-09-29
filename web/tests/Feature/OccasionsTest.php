<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\Occasions\OccasionCalendar;
use App\Support\Hijri;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مناسبت‌های تازه (قمری و شمسی)، روشن/خاموش کردن از پنل و «بخش‌های الزامی پروفایل» برای پیامک
 */
class OccasionsTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $sender;

    private User $mother;

    private User $sister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Asia/Tehran'));
        config([
            'pedigree.member_sms.min_completeness' => 0,
            'pedigree.sms.message_driver' => 'kavenegar',
            'pedigree.sms.drivers.kavenegar' => ['api_key' => 'KEY', 'template' => 'verify', 'sender' => '10008663'],
        ]);
        Http::fake(fn () => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]));

        $this->super = User::factory()->withPerson(['first_name' => 'مدیر'])->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
        $this->mother = User::factory()->withPerson(['first_name' => 'زهرا', 'last_name' => 'احمدی', 'gender' => 'f', 'phone' => '09121110000'])->create()->refresh();
        $this->sender = User::factory()->withPerson(['first_name' => 'علی', 'last_name' => 'احمدی', 'gender' => 'm'])->create()->refresh();
        $this->sister = User::factory()->withPerson(['first_name' => 'مریم', 'last_name' => 'احمدی', 'gender' => 'f', 'phone' => '09121112222'])->create()->refresh();
        foreach ([$this->sender, $this->sister] as $child) {
            $child->person->forceFill(['mother_id' => $this->mother->person_id])->save();
        }
    }

    /** روز میلادیِ یک تاریخ قمری در سال ۱۴۴۸ */
    private function travelToHijri(int $month, int $day, int $year = 1448): void
    {
        [$y, $m, $d] = Hijri::toGregorian($year, $month, $day);
        $this->travelTo(Carbon::parse(sprintf('%04d-%02d-%02d 10:00:00', $y, $m, $d), 'Asia/Tehran'));
    }

    public function test_lunar_occasions_follow_the_hijri_calendar_and_the_admin_offset(): void
    {
        $calendar = app(OccasionCalendar::class);
        // ۱ شوال ۱۴۴۷ به محاسبه حسابی = ۲۰ مارس ۲۰۲۶
        $this->assertSame([2026, 3, 20], Hijri::toGregorian(1447, 10, 1));
        $this->assertTrue($calendar->openOn('eid_fitr', [2026, 3, 20]));
        $this->assertFalse($calendar->openOn('eid_fitr', [2026, 3, 19]));

        // اگر ماه در ایران یک روز دیرتر دیده شد: اختلاف ‎-1
        config(['pedigree.occasions.hijri_offset' => -1]);
        $this->assertFalse($calendar->openOn('eid_fitr', [2026, 3, 20]));
        $this->assertTrue($calendar->openOn('eid_fitr', [2026, 3, 21]));

        // شمسی: سپندارمذگان ۲۹ بهمن
        $this->assertTrue($calendar->openOn('sepandarmazgan', [2027, 2, 18]));
        $this->assertNotNull($calendar->nextText('eid_ghadir'));
    }

    public function test_mothers_day_is_only_for_mothers(): void
    {
        $this->travelToHijri(6, 20);
        $this->actingAs($this->sender, 'sanctum');
        $res = $this->getJson('/api/greetings')->assertOk();
        $this->assertTrue(collect($res->json('occasions'))->firstWhere('key', 'mother_day')['open']);

        $rows = collect($res->json('relatives'));
        $mom = $rows->firstWhere('person.id', $this->mother->person_id);
        $this->assertContains('mother_day', $mom['occasions']);
        $this->assertNull($rows->firstWhere('person.id', $this->sister->person_id), 'خواهرِ بی‌فرزند در فهرست روز مادر نیست');

        $this->postJson('/api/greetings/sms', ['person_id' => $this->sister->person_id, 'occasion' => 'mother_day'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'تبریک روز مادر فقط برای مادران است.']);
        $text = $this->postJson('/api/greetings/preview', ['person_id' => $this->mother->person_id, 'occasion' => 'mother_day'])->json('text');
        $this->assertStringContainsString('روز مادر مبارک', $text);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->mother->person_id, 'occasion' => 'mother_day'])->assertCreated();

        // روز پدر برای مادر نیست
        $this->travelToHijri(7, 13);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->mother->person_id, 'occasion' => 'father_day'])->assertStatus(422);
    }

    public function test_admin_can_switch_occasions_off_but_never_birthdays(): void
    {
        $this->travelToHijri(10, 2); // عید فطر
        $this->actingAs($this->super, 'sanctum')->putJson('/api/admin/settings', ['values' => ['pedigree.occasions.enabled' => ['nowruz', 'yalda']]])->assertOk();

        $this->actingAs($this->sender, 'sanctum');
        $keys = array_column($this->getJson('/api/greetings')->json('occasions'), 'key');
        $this->assertSame(['birthday', 'nowruz', 'yalda'], $keys);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->sister->person_id, 'occasion' => 'eid_fitr'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'تبریک این مناسبت را مدیر سایت خاموش کرده است.']);

        // گزینه ناشناخته یا تولد در فهرست گزینه‌ها نیست
        $this->actingAs($this->super, 'sanctum')->putJson('/api/admin/settings', ['values' => ['pedigree.occasions.enabled' => ['birthday']]])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.occasions.enabled' => 'nowruz']])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.occasions.hijri_offset' => 9]])->assertStatus(422);
    }

    public function test_admin_decides_which_profile_sections_are_required_for_sms(): void
    {
        config(['pedigree.member_sms.min_completeness' => 100]);
        $this->actingAs($this->super, 'sanctum')->putJson('/api/admin/settings', ['values' => [
            'pedigree.member_sms.required_fields' => ['birth_date', 'occupation', 'nope'],
        ]])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.member_sms.required_fields' => []]])->assertStatus(422);
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.member_sms.required_fields' => ['occupation', 'birth_date']]])->assertOk();
        $field = collect($this->getJson('/api/admin/settings')->json('data'))->firstWhere('key', 'member_sms')['fields'];
        $this->assertSame(['birth_date', 'occupation'], collect($field)->firstWhere('key', 'pedigree.member_sms.required_fields')['value']);

        $this->sender->person->forceFill(['birth_date' => null, 'occupation' => null, 'workplace' => null])->save();
        $this->actingAs($this->sender, 'sanctum');
        $e = $this->getJson('/api/greetings')->json('eligibility');
        $this->assertFalse($e['eligible']);
        $this->assertSame(0, $e['percent']);
        $this->assertSame(['birth_date', 'occupation'], array_column($e['missing'], 'key'));

        $this->sender->person->forceFill(['birth_date' => '1365-01-01', 'occupation' => 'معلم'])->save();
        $e = $this->getJson('/api/greetings')->json('eligibility');
        $this->assertTrue($e['eligible']);
        $this->assertSame(100, $e['percent']);
        // فقط مدیر کل
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.member_sms.required_fields' => ['avatar']]])->assertStatus(403);
        $this->assertInstanceOf(Person::class, $this->sender->person);
    }
}
