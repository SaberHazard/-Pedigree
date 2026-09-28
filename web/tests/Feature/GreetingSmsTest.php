<?php

namespace Tests\Feature;

use App\Models\Marriage;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\Occasions\BirthdayService;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * اعلان تولد به همه اعضا (جز خود شخص) و پیامک تبریک از پنل سایت برای پروفایل‌های کامل.
 */
class GreetingSmsTest extends TestCase
{
    use RefreshDatabase;

    private User $sender;

    private User $birthdayUser;

    private User $other;

    private string $todayMd;

    /** پاسخ ساختگی پنل پیامکی (پیش‌فرض: موفق) */
    private mixed $providerResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        // ۶ مهر ۱۴۰۵، ساعت ۱۰ صبح تهران
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Asia/Tehran'));
        [, $m, $d] = Jalali::today();
        $this->todayMd = sprintf('%02d-%02d', $m, $d);

        config([
            'pedigree.member_sms.min_completeness' => 0,
            'pedigree.sms.message_driver' => 'kavenegar',
            'pedigree.sms.drivers.kavenegar' => ['api_key' => 'KEY', 'template' => 'verify', 'sender' => '10008663'],
            'pedigree.site_name' => 'شجره احمدی',
        ]);
        Http::fake(fn () => $this->providerResponse ?? Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]));

        $father = $this->member(['first_name' => 'حسن', 'gender' => 'm']);
        $this->sender = $this->member(['first_name' => 'علی', 'last_name' => 'احمدی', 'gender' => 'm'], $father);
        $this->birthdayUser = $this->member(['first_name' => 'مریم', 'gender' => 'f', 'birth_date' => '1370-'.$this->todayMd, 'phone' => '09121112222'], $father);
        $this->other = $this->member(['first_name' => 'رضا', 'gender' => 'm']);
    }

    private function member(array $attrs, ?User $father = null): User
    {
        $user = User::factory()->withPerson($attrs + ['last_name' => 'احمدی'])->create(['last_login_at' => now()])->refresh();
        if ($father) {
            $user->person->forceFill(['father_id' => $father->person_id])->save();
        }

        return $user->refresh();
    }

    public function test_birthday_is_announced_to_everyone_except_the_person_once_a_year(): void
    {
        // حسابی که صاحبش هرگز وارد نشده (مثلاً ساخته‌شده برای پدربزرگ) اعلان نمی‌گیرد
        $neverLoggedIn = User::factory()->withPerson()->create();
        $neverLoggedIn->forceFill(['last_login_at' => null])->save();
        $dead = Person::factory()->create(['birth_date' => '1300-'.$this->todayMd, 'is_deceased' => true]);

        $this->artisan('pedigree:birthdays', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, $this->sender->fresh()->notifications->where('data.kind', 'birthday')->count());
        $this->assertSame(1, $this->other->fresh()->notifications->where('data.kind', 'birthday')->count());
        $this->assertSame(0, $this->birthdayUser->fresh()->notifications()->count(), 'خود شخص اعلان تولد خودش را نمی‌گیرد');
        $this->assertSame(0, $neverLoggedIn->fresh()->notifications()->count());
        $note = $this->sender->fresh()->notifications()->first()->data;
        $this->assertStringContainsString('مریم', $note['title']);
        $this->assertSame('#/greetings?person='.$this->birthdayUser->person_id, $note['link']);
        $this->assertStringNotContainsString($dead->first_name.' ', $note['title']);

        // اجرای دوباره (هر ساعت) اعلان تکراری نمی‌فرستد
        $this->artisan('pedigree:birthdays', ['--force' => true])->assertSuccessful();
        $this->assertSame(1, $this->sender->fresh()->notifications()->count());
    }

    public function test_esfand_30_birthdays_are_celebrated_on_esfand_29_in_common_years(): void
    {
        $year = 1404;
        while (Jalali::isLeap($year)) {
            $year++;
        }
        [$gy, $gm, $gd] = Jalali::toGregorian($year, 12, 29);
        $this->travelTo(Carbon::create($gy, $gm, $gd, 10, 0, 0, 'Asia/Tehran'));
        $leapBorn = Person::factory()->create(['birth_date' => '1375-12-30', 'is_deceased' => false]);

        $ids = app(BirthdayService::class)->todays()->pluck('person.id');
        $this->assertContains($leapBorn->id, $ids);
    }

    public function test_greeting_requires_the_senders_profile_to_be_complete(): void
    {
        config(['pedigree.member_sms.min_completeness' => 95]);
        $res = $this->actingAs($this->sender, 'sanctum')->getJson('/api/greetings')->assertOk();
        $this->assertFalse($res->json('eligibility.eligible'));
        $this->assertSame('incomplete', $res->json('eligibility.reason'));
        $this->assertNotEmpty($res->json('eligibility.missing'));
        $this->assertContains('عکس پروفایل', array_column($res->json('eligibility.missing'), 'label'));
        $this->assertFalse(collect($res->json('birthdays'))->firstWhere('person.id', $this->birthdayUser->person_id)['can_sms']);

        $this->postJson('/api/greetings/sms', ['person_id' => $this->birthdayUser->person_id, 'template' => 'warm'])
            ->assertStatus(403)->assertJsonPath('code', 'sms_incomplete')
            ->assertJsonFragment(['message' => $res->json('eligibility.message')]);
        Http::assertNothingSent();
    }

    public function test_greeting_uses_fixed_text_with_titles_and_computed_relation(): void
    {
        $recipient = $this->birthdayUser->person;
        // گیرنده دکترا دارد و فرستنده لیسانس مهندسی
        $recipient->forceFill(['education_level' => 'phd'])->save();
        $this->sender->person->forceFill(['education_level' => 'bachelor', 'education_field_group' => 'engineering'])->save();
        $this->actingAs($this->sender, 'sanctum');

        $preview = $this->postJson('/api/greetings/preview', ['person_id' => $recipient->id, 'template' => 'warm', 'note' => 'علی کوچولو'])->assertOk();
        $expected = "🎂 دکتر مریم احمدی عزیز، زادروزت خجسته باد! سالی سرشار از سلامتی و شادی برایت آرزومندم.\n"
            ."از طرف برادر عزیزت، مهندس علی احمدی\n"
            ."علی کوچولو\n"
            .'شجره احمدی';
        $this->assertSame($expected, $preview->json('text'));

        $res = $this->postJson('/api/greetings/sms', ['person_id' => $recipient->id, 'template' => 'warm', 'note' => 'علی کوچولو'])->assertCreated();
        // شماره گیرنده به فرستنده برنمی‌گردد
        $this->assertStringNotContainsString('09121112222', $res->getContent());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sms/send.json')
            && $r['receptor'] === '09121112222' && $r['message'] === $expected);
        $this->assertSame(1, SmsMessage::where('status', 'sent')->count());
        $this->assertSame('0912***2222', SmsMessage::first()->phone_hint);

        // متن آزاد پذیرفته نمی‌شود؛ فقط قالب‌های ثابت
        $this->postJson('/api/greetings/sms', ['person_id' => $recipient->id, 'template' => 'my own text'])->assertStatus(422);
        // هر نفر سالی یک بار
        $this->postJson('/api/greetings/sms', ['person_id' => $recipient->id, 'template' => 'short'])->assertStatus(422)->assertJsonPath('code', 'sms_recipient');
        // کسی که امروز تولدش نیست
        $this->postJson('/api/greetings/sms', ['person_id' => $this->other->person_id, 'template' => 'warm'])->assertStatus(422);

        // یادداشت: کوتاه، بدون عدد و لینک
        $another = $this->member(['first_name' => 'سارا', 'gender' => 'f', 'birth_date' => '1380-'.$this->todayMd, 'phone' => '09123334444']);
        foreach (['شماره‌ام ۰۹۱۲۳۴۵۶۷۸۹', 'call 0912', 'site.ir', 'https://x.y', '@myid', str_repeat('ب', 31)] as $bad) {
            $this->postJson('/api/greetings/preview', ['person_id' => $another->person_id, 'template' => 'warm', 'note' => $bad])->assertStatus(422);
        }

        // غیرخویشاوند: فقط «از طرف ...»
        $stranger = $this->member(['first_name' => 'نگار', 'last_name' => 'کریمی', 'gender' => 'f', 'birth_date' => '1385-'.$this->todayMd, 'phone' => '09125556666']);
        $text = $this->postJson('/api/greetings/preview', ['person_id' => $stranger->person_id, 'template' => 'respect'])->json('text');
        $this->assertStringContainsString("از طرف مهندس علی احمدی\n", $text);
        $this->assertStringStartsWith('🎉 نگار کریمی گرامی', $text);

        // گیرنده بدون موبایل
        $noPhone = $this->member(['first_name' => 'بی‌شماره', 'gender' => 'm', 'birth_date' => '1360-'.$this->todayMd]);
        $this->postJson('/api/greetings/sms', ['person_id' => $noPhone->person_id, 'template' => 'warm'])->assertStatus(422);

        // سقف روزانه فرستنده
        config(['pedigree.member_sms.daily_per_user' => 1]);
        $this->postJson('/api/greetings/sms', ['person_id' => $stranger->person_id, 'template' => 'warm'])->assertStatus(429)->assertJsonPath('code', 'sms_limit');

        $this->getJson('/api/greetings')->assertOk()->assertJsonPath('history.0.status', 'sent')
            ->assertJsonPath('eligibility.eligible', true)->assertJsonCount(4, 'templates');
    }

    public function test_nobody_can_opt_out_of_birthday_notifications_or_greetings(): void
    {
        $id = $this->birthdayUser->person_id;
        $this->actingAs($this->birthdayUser, 'sanctum')->patchJson("/api/persons/{$id}", ['accept_greeting_sms' => false])->assertOk();
        $this->assertArrayNotHasKey('accept_greeting_sms', $this->birthdayUser->person->fresh()->getAttributes());

        $super = User::factory()->withPerson()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $keys = collect($this->actingAs($super, 'sanctum')->getJson('/api/admin/settings')->json('data'))->flatMap(fn ($g) => array_column($g['fields'], 'key'));
        $this->assertNotContains('pedigree.birthdays.notify', $keys);
        $this->assertNotContains('pedigree.member_sms.enabled', $keys);
        $this->assertNotContains('pedigree.member_sms.auto_enabled', $keys);
        $this->putJson('/api/admin/settings', ['values' => ['pedigree.birthdays.notify' => false]])->assertStatus(422);
    }

    public function test_provider_failure_is_recorded_and_reported(): void
    {
        $this->providerResponse = Http::response(['return' => ['status' => 418, 'message' => 'اعتبار کافی نیست']], 200);
        $this->actingAs($this->sender, 'sanctum')
            ->postJson('/api/greetings/sms', ['person_id' => $this->birthdayUser->person_id, 'template' => 'warm'])
            ->assertStatus(503)->assertJsonPath('code', 'sms_failed');
        $this->assertSame('failed', SmsMessage::first()->status);

        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/sms-messages')->assertOk()
            ->assertJsonPath('data.0.status', 'failed')->assertJsonPath('stats.failed_month', 1);
        $this->actingAs($this->other, 'sanctum')->getJson('/api/admin/sms-messages')->assertForbidden();
    }

    public function test_automatic_greetings_from_members(): void
    {
        $this->actingAs($this->sender, 'sanctum')->putJson('/api/greetings/auto', [
            'auto' => true, 'scope' => 'd1', 'template' => 'short', 'note' => 'داداش علی',
        ])->assertOk()->assertJsonPath('auto.auto', true)->assertJsonPath('auto.template', 'short');
        // متن آزاد پذیرفته نمی‌شود
        $this->putJson('/api/greetings/auto', ['auto' => true, 'scope' => 'd1', 'template' => '{name} تولدت مبارک'])->assertStatus(422);

        // «رضا» (غیرخویشاوند) هم امروز تولد دارد ولی در دامنه d1 نیست
        $this->other->person->forceFill(['birth_date' => '1365-'.$this->todayMd, 'phone' => '09127778888'])->save();

        $this->artisan('pedigree:birthdays', ['--force' => true])->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['receptor'] === '09121112222'
            && str_starts_with($r['message'], '🌹 مریم احمدی عزیز، تولدت مبارک!')
            && str_contains($r['message'], "از طرف برادر عزیزت، علی احمدی\nداداش علی"));
        $this->assertTrue(SmsMessage::first()->auto);

        // اجرای دوباره در همان روز: تکرار نمی‌شود
        $this->artisan('pedigree:birthdays', ['--force' => true]);
        Http::assertSentCount(1);

        // دامنه کاربر از سقف مدیر بیشتر نمی‌شود
        config(['pedigree.member_sms.auto_max_scope' => 'd1']);
        $this->putJson('/api/greetings/auto', ['auto' => true, 'scope' => 'all', 'template' => 'warm'])->assertOk()->assertJsonPath('auto.scope', 'd1');
    }

    public function test_spouse_of_birthday_person_sees_relation_in_list(): void
    {
        $wife = $this->member(['first_name' => 'زهرا', 'gender' => 'f']);
        Marriage::create(['husband_id' => $this->sender->person_id, 'wife_id' => $wife->person_id]);
        $row = collect($this->actingAs($wife, 'sanctum')->getJson('/api/greetings')->json('birthdays'))->firstWhere('person.id', $this->birthdayUser->person_id);
        $this->assertSame(0, $row['in_days']);
        $this->assertSame(2, $row['degree']);
        $this->assertNotEmpty($row['relation']);
    }
}
