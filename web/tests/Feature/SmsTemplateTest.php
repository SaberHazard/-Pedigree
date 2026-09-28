<?php

namespace Tests\Feature;

use App\Models\Marriage;
use App\Models\SmsMessage;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * قالب‌های ثابت پیامک تبریک (فقط مدیر کل) و مناسبت‌ها: تولد، سالگرد ازدواج، نوروز، یلدا.
 */
class SmsTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $sender;

    private User $recipient;

    protected function setUp(): void
    {
        parent::setUp();
        // ۶ مهر ۱۴۰۵
        $this->travelTo(Carbon::parse('2026-09-28 10:00:00', 'Asia/Tehran'));
        config([
            'pedigree.member_sms.min_completeness' => 0,
            'pedigree.sms.message_driver' => 'kavenegar',
            'pedigree.sms.drivers.kavenegar' => ['api_key' => 'KEY', 'template' => 'verify', 'sender' => '10008663'],
            'pedigree.site_name' => 'شجره احمدی',
        ]);
        Http::fake(fn () => Http::response(['return' => ['status' => 200], 'entries' => [['messageid' => 1]]]));

        $this->super = User::factory()->withPerson(['first_name' => 'مدیر'])->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
        $father = User::factory()->withPerson(['first_name' => 'حسن', 'last_name' => 'احمدی', 'gender' => 'm'])->create()->refresh();
        $this->sender = User::factory()->withPerson(['first_name' => 'علی', 'last_name' => 'احمدی', 'gender' => 'm', 'nickname' => 'علی‌آقا'])->create()->refresh();
        [, $m, $d] = Jalali::today();
        $this->recipient = User::factory()->withPerson([
            'first_name' => 'مریم', 'last_name' => 'احمدی', 'gender' => 'f', 'phone' => '09121112222',
            'birth_date' => sprintf('1370-%02d-%02d', $m, $d), 'city' => 'شیراز',
        ])->create()->refresh();
        foreach ([$this->sender, $this->recipient] as $child) {
            $child->person->forceFill(['father_id' => $father->person_id])->save();
        }
    }

    private function body(string $text): array
    {
        return ['occasion' => 'birthday', 'title' => 'آزمایشی', 'body' => $text, 'active' => true];
    }

    public function test_only_the_super_admin_manages_templates(): void
    {
        $admin = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN]);
        foreach ([$admin, $this->sender] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/sms-templates')->assertForbidden();
            $this->postJson('/api/admin/sms-templates', $this->body('{from_line} سلام'))->assertForbidden();
            $this->putJson('/api/admin/sms-templates/1', $this->body('{from_line} سلام'))->assertForbidden();
            $this->deleteJson('/api/admin/sms-templates/1')->assertForbidden();
        }

        $res = $this->actingAs($this->super, 'sanctum')->getJson('/api/admin/sms-templates')->assertOk();
        $this->assertCount(8, $res->json('data'));
        $this->assertContains('to_first_name', array_column($res->json('variables'), 'key'));
        $this->assertSame(['birthday', 'anniversary', 'nowruz', 'yalda'], array_column($res->json('occasions'), 'key'));
    }

    public function test_template_validation(): void
    {
        $this->actingAs($this->super, 'sanctum');
        $cases = [
            '{to_first_name} عزیز تولدت مبارک' => 'فرستنده',            // بدون نام فرستنده
            '{from_line} {unknown_var}' => 'وجود ندارند',
            '{from_line} {years_married}' => 'برای این مناسبت',           // متغیر سالگرد در قالب تولد
            '{from_line} ببین https://evil.example' => 'لینک',
            '{from_line} {to_first_name' => 'جفت',
            str_repeat('ا', 501).'{from_line}' => 'حداکثر',
        ];
        foreach ($cases as $text => $expected) {
            $res = $this->postJson('/api/admin/sms-templates', $this->body($text))->assertStatus(422);
            $this->assertStringContainsString($expected, $res->json('errors.body.0'), $text);
        }
        $this->assertSame(8, SmsTemplate::count());
    }

    public function test_admin_edits_text_with_variables_and_members_send_it(): void
    {
        $this->actingAs($this->super, 'sanctum');
        $text = "🎂 {to_mr_mrs} {to_full_name} ({to_nickname})\n{to_first_name} جان، {to_age} سالگی‌ات مبارک! سلام به {to_city}\n{from_line}\n{note}\n{site_name}";

        // پیش‌نمایش با مقادیر نمونه
        $this->postJson('/api/admin/sms-templates/preview', ['occasion' => 'birthday', 'body' => $text])->assertOk()
            ->assertJsonPath('text', "🎂 خانم دکتر مریم احمدی (مریم‌جان)\nمریم جان، ۳۵ سالگی‌ات مبارک! سلام به تهران\nاز طرف پسرخاله عزیزت، مهندس علی احمدی\nبا عشق، خانواده رضایی\nشجره احمدی");

        $warm = SmsTemplate::where('slug', 'warm')->first();
        $this->putJson("/api/admin/sms-templates/{$warm->id}", ['occasion' => 'birthday', 'title' => 'گرم', 'body' => $text, 'active' => true])->assertOk();

        // عضو همان متن را می‌فرستد؛ سطرِ لقبِ خالی و یادداشتِ خالی حذف می‌شوند
        $this->actingAs($this->sender, 'sanctum');
        $expected = "🎂 خانم مریم احمدی\nمریم جان، ۳۵ سالگی‌ات مبارک! سلام به شیراز\nاز طرف برادر عزیزت، علی احمدی\nشجره احمدی";
        // لقب خالی است: «()» خالی حذف می‌شود ولی سطر (که نام دارد) می‌ماند
        $preview = $this->postJson('/api/greetings/preview', ['person_id' => $this->recipient->person_id, 'template' => $warm->id])->assertOk();
        $this->assertSame($expected, $preview->json('text'));

        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'template' => $warm->id])->assertCreated();
        Http::assertSent(fn (Request $r) => $r['message'] === $preview->json('text'));

        // عضو متن قالب را نمی‌تواند عوض کند
        $this->putJson("/api/admin/sms-templates/{$warm->id}", $this->body('{from_line} x'))->assertForbidden();
    }

    public function test_persian_variable_names(): void
    {
        $this->actingAs($this->super, 'sanctum');
        // نیم‌فاصله و «ي» عربی هم پذیرفته می‌شود
        $body = "🎂 {نام_گیرنده} جان ({نام\u{200C}خانوادگی_گیرنده})، {سن_گيرنده} سالگی‌ات مبارک!\n{از_طرف}\n{یادداشت}";
        $this->postJson('/api/admin/sms-templates/preview', ['occasion' => 'birthday', 'body' => $body, 'note' => ''])->assertOk()
            ->assertJsonPath('text', "🎂 مریم جان (احمدی)، ۳۵ سالگی‌ات مبارک!\nاز طرف پسرخاله عزیزت، مهندس علی احمدی");
        $this->postJson('/api/admin/sms-templates/preview', ['occasion' => 'birthday', 'body' => '{از_طرف} {نام_ناشناس}'])
            ->assertStatus(422)->assertJsonPath('errors.body.0', 'این متغیرها وجود ندارند: {نام_ناشناس}');
        $this->postJson('/api/admin/sms-templates/preview', ['occasion' => 'birthday', 'body' => '{نام_گیرنده} تولدت مبارک'])
            ->assertStatus(422);
        $this->postJson('/api/admin/sms-templates/preview', ['occasion' => 'birthday', 'body' => '{از_طرف} {سال_ازدواج}'])
            ->assertStatus(422)->assertJsonPath('errors.body.0', fn ($m) => str_contains($m, '{سال_ازدواج}'));

        $tokens = array_column($this->getJson('/api/admin/sms-templates')->json('variables'), 'token');
        $this->assertContains('نام_کامل_گیرنده', $tokens);
        $this->assertContains('نسبت', $tokens);
    }

    public function test_birthday_greetings_cannot_be_disabled_via_templates(): void
    {
        $this->actingAs($this->super, 'sanctum');
        $birthday = SmsTemplate::where('occasion', 'birthday')->orderBy('id')->get();
        foreach ($birthday->slice(1) as $t) {
            $this->deleteJson("/api/admin/sms-templates/{$t->id}")->assertOk();
        }
        $last = $birthday->first();
        $this->putJson("/api/admin/sms-templates/{$last->id}", ['occasion' => 'birthday', 'title' => 'x', 'body' => $last->body, 'active' => false])->assertStatus(422);
        $this->putJson("/api/admin/sms-templates/{$last->id}", ['occasion' => 'nowruz', 'title' => 'x', 'body' => $last->body, 'active' => true])->assertStatus(422);
        $this->deleteJson("/api/admin/sms-templates/{$last->id}")->assertStatus(422);
        $this->assertTrue($last->fresh()->active);

        // بازگردانی پیش‌فرض‌ها
        $this->postJson('/api/admin/sms-templates/defaults')->assertOk();
        $this->assertSame(4, SmsTemplate::where('occasion', 'birthday')->where('active', true)->count());
    }

    public function test_anniversary_greeting(): void
    {
        [, $m, $d] = Jalali::today();
        $husband = User::factory()->withPerson(['first_name' => 'حسین', 'last_name' => 'رضایی', 'gender' => 'm', 'phone' => '09124445555'])->create()->refresh();
        Marriage::create(['husband_id' => $husband->person_id, 'wife_id' => $this->recipient->person_id, 'status' => 'married', 'marriage_date' => sprintf('1380-%02d-%02d', $m, $d)]);

        $this->actingAs($this->sender, 'sanctum');
        $rows = collect($this->getJson('/api/greetings')->assertOk()->json('anniversaries'));
        $row = $rows->firstWhere('person.id', $this->recipient->person_id);
        $this->assertSame(25, $row['years']);
        $this->assertTrue($row['can_sms']);

        $anniv = SmsTemplate::where('occasion', 'anniversary')->value('id');
        $text = $this->postJson('/api/greetings/preview', ['person_id' => $this->recipient->person_id, 'occasion' => 'anniversary', 'template' => $anniv])->json('text');
        $this->assertStringStartsWith('💍 مریم احمدی عزیز، ۲۵مین سالگرد ازدواج شما و حسین رضایی مبارک!', $text);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'anniversary', 'template' => $anniv])->assertCreated();
        $this->assertSame('anniversary', SmsMessage::first()->kind);
        // تبریک تولد همان روز جداست
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id])->assertCreated();
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'anniversary'])->assertStatus(422);
    }

    public function test_nowruz_and_yalda_only_in_their_window(): void
    {
        $this->actingAs($this->sender, 'sanctum');
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'nowruz'])
            ->assertStatus(422)->assertJsonPath('code', 'sms_recipient')
            ->assertJsonFragment(['message' => 'پیامک تبریک نوروز فقط از ۲۵ اسفند تا ۱۳ فروردین قابل ارسال است.']);
        $this->assertSame([], $this->getJson('/api/greetings')->json('relatives'));

        // ۲ فروردین ۱۴۰۶
        $this->travelTo(Carbon::parse('2027-03-22 10:00:00', 'Asia/Tehran'));
        $res = $this->getJson('/api/greetings')->assertOk();
        $this->assertTrue(collect($res->json('occasions'))->firstWhere('key', 'nowruz')['open']);
        $sister = collect($res->json('relatives'))->firstWhere('person.id', $this->recipient->person_id);
        $this->assertSame('خواهر', $sister['relation']);
        $this->assertFalse($sister['greeted']['nowruz']);

        $text = $this->postJson('/api/greetings/preview', ['person_id' => $this->recipient->person_id, 'occasion' => 'nowruz'])->json('text');
        $this->assertStringContainsString('نوروز ۱۴۰۶ مبارک', $text);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'nowruz'])->assertCreated();
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'nowruz'])->assertStatus(422);
        $this->assertTrue(collect($this->getJson('/api/greetings')->json('relatives'))->firstWhere('person.id', $this->recipient->person_id)['greeted']['nowruz']);

        // اگر مدیر همه قالب‌های یلدا را غیرفعال کند، یلدا ارسال نمی‌شود
        $this->travelTo(Carbon::parse('2026-12-21 10:00:00', 'Asia/Tehran')); // ۳۰ آذر
        SmsTemplate::where('occasion', 'yalda')->update(['active' => false]);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'yalda'])->assertStatus(422);
        SmsTemplate::where('occasion', 'yalda')->update(['active' => true]);
        $this->postJson('/api/greetings/sms', ['person_id' => $this->recipient->person_id, 'occasion' => 'yalda'])->assertCreated();
    }
}
