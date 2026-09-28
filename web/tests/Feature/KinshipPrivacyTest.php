<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Services\KinshipDegrees;
use App\Support\SocialNetworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * درجه خویشاوندی، نمایش شماره/نشانی بر اساس درجه، شبکه‌های اجتماعی و عنوان خودکار دکتر/مهندس.
 */
class KinshipPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $u = [];

    /** @var array<string, Person> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        //            GGF
        //             |
        //        GF ══ GM
        //   ┌─────┼──────┐
        //   F    Uncle══UW Aunt
        //   ║     |
        //   M   Cousin
        //   |     |
        //  Me, Sib  CousinChild
        //  ║    ║   |
        //  W   SibW Nephew (فرزند Sib)
        //  |
        //  WF (پدرِ W) ، WSis (خواهر W)
        $this->person('GGF', 'm');
        $this->person('GF', 'm', 'GGF');
        $this->person('GM', 'f');
        $this->person('F', 'm', 'GF', 'GM');
        $this->person('M', 'f');
        $this->person('Uncle', 'm', 'GF', 'GM');
        $this->person('UW', 'f');
        $this->person('Aunt', 'f', 'GF', 'GM');
        $this->person('Me', 'm', 'F', 'M');
        $this->person('Sib', 'm', 'F', 'M');
        $this->person('SibW', 'f');
        $this->person('Nephew', 'm', 'Sib', 'SibW');
        $this->person('Cousin', 'm', 'Uncle', 'UW');
        $this->person('CousinChild', 'f', 'Cousin');
        $this->person('WF', 'm');
        $this->person('W', 'f', 'WF');
        $this->person('WSis', 'f', 'WF');
        $this->person('Stranger', 'm');

        $this->marry('GF', 'GM');
        $this->marry('F', 'M');
        $this->marry('Uncle', 'UW');
        $this->marry('Me', 'W');
        $this->marry('Sib', 'SibW');
    }

    private function person(string $key, string $gender, ?string $father = null, ?string $mother = null): void
    {
        $user = User::factory()->withPerson(['gender' => $gender, 'first_name' => $key])->create()->refresh();
        $user->person->forceFill([
            'father_id' => $father ? $this->p[$father]->id : null,
            'mother_id' => $mother ? $this->p[$mother]->id : null,
        ])->save();
        $this->u[$key] = $user->refresh();
        $this->p[$key] = $user->person;
    }

    private function marry(string $husband, string $wife): void
    {
        Marriage::create(['husband_id' => $this->p[$husband]->id, 'wife_id' => $this->p[$wife]->id]);
    }

    public function test_kinship_degrees_follow_common_persian_usage(): void
    {
        $degrees = app(KinshipDegrees::class);
        $me = $this->p['Me']->fresh();
        $expected = [
            'F' => 1, 'M' => 1, 'Sib' => 1, 'W' => 1,
            'GF' => 2, 'GM' => 2, 'Uncle' => 2, 'Aunt' => 2, 'Nephew' => 2,
            'WF' => 2, 'WSis' => 2, 'SibW' => 2,
            'Cousin' => 3, 'GGF' => 3, 'UW' => 3,
            'CousinChild' => 4,
        ];
        foreach ($expected as $key => $degree) {
            $this->assertSame($degree, $degrees->degree($me, $this->p[$key]->fresh()), "degree of {$key}");
        }
        $this->assertNull($degrees->degree($me, $this->p['Stranger']->fresh()));

        // نسبت خونی متقارن است؛ برای پدرزن، داماد درجه یک است
        $fresh = new KinshipDegrees;
        $this->assertSame(3, $fresh->degree($this->p['Cousin']->fresh(), $me));
        $this->assertSame(1, $fresh->degree($this->p['WF']->fresh(), $me));

        // فهرست «چه کسانی» با نام نسبت
        $res = $this->actingAs($this->u['Me'], 'sanctum')->getJson("/api/persons/{$me->id}/kin?max=3")->assertOk();
        $rows = collect($res->json('data'))->flatMap(fn ($g) => collect($g['people'])->map(fn ($r) => $r + ['degree' => $g['degree']]))
            ->keyBy('person.first_name');
        $this->assertSame('عمو', $rows['Uncle']['label']);
        $this->assertSame('پسرعمو', $rows['Cousin']['label']);
        $this->assertSame(3, $rows['Cousin']['degree']);
        $this->assertSame('پدرزن', $rows['WF']['label']);
        $this->assertSame('زن‌عمو', $rows['UW']['label']);
        $this->assertTrue($rows['UW']['inlaw']);
        $this->assertFalse($rows->has('CousinChild'));
        $this->assertFalse($rows->has('Me'));
        $this->assertSame(15, $res->json('total'));
    }

    public function test_son_and_daughter_in_law_are_first_degree_but_parents_in_law_second(): void
    {
        $degrees = app(KinshipDegrees::class);
        $f = $this->p['F']->fresh();
        $w = $this->p['W']->fresh();
        $wf = $this->p['WF']->fresh();
        $me = $this->p['Me']->fresh();

        // W همسرِ پسرِ F است: برای F «عروس» (درجه ۱)؛ F برای W «پدرشوهر» (درجه ۲)
        $this->assertSame(1, $degrees->degree($f, $w));
        $this->assertSame(2, $degrees->degree($w, $f));
        // Me شوهرِ دخترِ WF است: برای WF «داماد» (درجه ۱)؛ WF برای Me «پدرزن» (درجه ۲)
        $this->assertSame(1, $degrees->degree($wf, $me));
        $this->assertSame(2, $degrees->degree($me, $wf));
        $this->assertSame(1, $degrees->relativeDegree($f, $w));
        $this->assertSame(2, $degrees->relativeDegree($w, $f));
        // زن‌برادر همچنان درجه ۲
        $this->assertSame(2, $degrees->degree($me, $this->p['SibW']->fresh()));
        $this->assertSame(2, $degrees->degree($f->fresh(), $this->p['Nephew']->fresh()));

        // «فقط بستگان درجه ۱» پدر: عروسش شماره را می‌بیند
        $f->forceFill(['phone' => '09121110000', 'contact_visibility' => 'd1'])->save();
        $this->actingAs($this->u['W'], 'sanctum')->getJson("/api/persons/{$f->id}")->assertJsonPath('data.phone', '09121110000');
        // ولی «فقط بستگان درجه ۱» عروس: پدرشوهرش (درجه ۲ از دید عروس) نمی‌بیند، شوهرش می‌بیند
        $w->forceFill(['phone' => '09121110001', 'contact_visibility' => 'd1'])->save();
        $this->actingAs($this->u['F'], 'sanctum')->getJson("/api/persons/{$w->id}")->assertJsonPath('data.phone', null);
        $this->actingAs($this->u['Me'], 'sanctum')->getJson("/api/persons/{$w->id}")->assertJsonPath('data.phone', '09121110001');

        // فهرست «بستگان درجه یکِ پدر»: عروس با برچسب «عروس»
        $rows = collect($this->actingAs($this->u['F'], 'sanctum')->getJson("/api/persons/{$f->id}/kin?max=1")->json('data.0.people'))
            ->keyBy('person.first_name');
        $this->assertSame('عروس', $rows['W']['label']);
        $this->assertTrue($rows['W']['inlaw']);
        $this->assertFalse($rows->has('WF'));

        // نقشه: خانه پدر با سطح d1 برای عروس دیده می‌شود
        $f->forceFill(['home_lat' => 35.7, 'home_lng' => 51.4, 'location_visibility' => 'd1'])->save();
        $homes = collect($this->actingAs($this->u['W'], 'sanctum')->getJson('/api/map')->json('data'))->where('kind', 'home');
        $this->assertContains($f->id, $homes->pluck('person.id')->all());
    }

    public function test_phone_visibility_by_kinship_degree(): void
    {
        $me = $this->p['Me'];
        $me->forceFill(['phone' => '09121112233', 'landline' => '02188776655', 'social' => ['whatsapp' => '+989121112233', 'instagram' => 'me.insta']])->save();

        // پیش‌فرض: همه اعضای خاندان می‌بینند
        $this->actingAs($this->u['Stranger'], 'sanctum')->getJson("/api/persons/{$me->id}")
            ->assertJsonPath('data.phone', '09121112233')->assertJsonPath('data.contact_visibility', 'all')
            ->assertJsonPath('data.social.whatsapp', '+989121112233');

        $this->actingAs($this->u['Me'], 'sanctum')->patchJson("/api/persons/{$me->id}", ['contact_visibility' => 'd2'])->assertOk();

        foreach (['Uncle' => true, 'WF' => true, 'Nephew' => true, 'Sib' => true, 'Cousin' => false, 'UW' => false, 'Stranger' => false] as $key => $sees) {
            $res = $this->actingAs($this->u[$key], 'sanctum')->getJson("/api/persons/{$me->id}")->assertOk();
            $this->assertSame($sees ? '09121112233' : null, $res->json('data.phone'), "{$key} phone");
            $this->assertSame($sees ? '02188776655' : null, $res->json('data.landline'), "{$key} landline");
            // شماره واتس‌اپ هم مثل موبایل؛ شناسه اینستاگرام برای همه
            $this->assertSame($sees ? '+989121112233' : null, $res->json('data.social.whatsapp'), "{$key} whatsapp");
            $this->assertSame('me.insta', $res->json('data.social.instagram'));
            $this->assertSame(! $sees, $res->json('data.contact_hidden'), "{$key} hidden flag");
        }

        // فقط خودم: حتی برادر نمی‌بیند، مدیر می‌بیند
        $this->actingAs($this->u['Me'], 'sanctum')->patchJson("/api/persons/{$me->id}", ['contact_visibility' => 'self'])->assertOk();
        $this->actingAs($this->u['Sib'], 'sanctum')->getJson("/api/persons/{$me->id}")->assertJsonPath('data.phone', null);
        $admin = User::factory()->admin()->withPerson()->create();
        $this->actingAs($admin, 'sanctum')->getJson("/api/persons/{$me->id}")->assertJsonPath('data.phone', '09121112233');

        // تاریخچه عمومی شماره واتس‌اپ را لو نمی‌دهد
        $this->actingAs($this->u['Me'], 'sanctum')->patchJson("/api/persons/{$me->id}", ['social' => ['whatsapp' => '09125556677', 'instagram' => 'me.insta']])->assertOk();
        $log = ActivityLog::where('action', 'person.updated')->latest('id')->first();
        $this->assertStringNotContainsString('5556677', json_encode($log->properties));
    }

    public function test_editor_cannot_change_or_wipe_hidden_contact_or_privacy(): void
    {
        $me = $this->p['Me'];
        $me->forceFill([
            'email' => 'me@example.org', 'address' => 'خیابان اول', 'home_lat' => 35.7, 'home_lng' => 51.4,
            'social' => ['whatsapp' => '+989121112233', 'telegram' => 'me_tele'],
            'contact_visibility' => 'self', 'location_visibility' => 'self',
        ])->save();

        // برادر (ویرایشگر درجه یک) فرم را با فیلدهای خالی ذخیره می‌کند
        $this->actingAs($this->u['Sib'], 'sanctum')->patchJson("/api/persons/{$me->id}", [
            'nickname' => 'داداش', 'email' => null, 'address' => null, 'home_lat' => null, 'home_lng' => null,
            'social' => ['telegram' => 'me_tele', 'whatsapp' => '09120000000'],
            'contact_visibility' => 'all', 'location_visibility' => 'all',
        ])->assertOk()->assertJsonPath('data.nickname', 'داداش');

        $fresh = $me->fresh();
        $this->assertSame('me@example.org', $fresh->email);
        $this->assertSame('خیابان اول', $fresh->address);
        $this->assertSame('+989121112233', $fresh->social['whatsapp']);
        $this->assertSame('self', $fresh->contact_visibility);
        $this->assertSame('self', $fresh->location_visibility);
    }

    public function test_location_default_is_first_degree(): void
    {
        $me = $this->p['Me'];
        $me->forceFill(['address' => 'کوچه دوم', 'home_lat' => 35.7, 'home_lng' => 51.4])->save();

        $this->actingAs($this->u['W'], 'sanctum')->getJson("/api/persons/{$me->id}")->assertJsonPath('data.address', 'کوچه دوم');
        $this->actingAs($this->u['Uncle'], 'sanctum')->getJson("/api/persons/{$me->id}")
            ->assertJsonPath('data.address', null)->assertJsonPath('data.location_hidden', true);

        $this->actingAs($this->u['Me'], 'sanctum')->patchJson("/api/persons/{$me->id}", ['location_visibility' => 'd3'])->assertOk();
        $this->actingAs($this->u['Cousin'], 'sanctum')->getJson("/api/persons/{$me->id}")->assertJsonPath('data.address', 'کوچه دوم');
        $homes = collect($this->actingAs($this->u['Cousin'], 'sanctum')->getJson('/api/map')->json('data'))->where('kind', 'home');
        $this->assertSame([$me->id], $homes->pluck('person.id')->values()->all());
        $homes = collect($this->actingAs($this->u['CousinChild'], 'sanctum')->getJson('/api/map')->json('data'))->where('kind', 'home');
        $this->assertCount(0, $homes);
    }

    public function test_social_handles_are_normalized_from_any_input(): void
    {
        $cases = [
            ['instagram', 'https://www.instagram.com/ali.rezaei/?hl=fa', 'ali.rezaei'],
            ['instagram', '@Ali.Rezaei', 'Ali.Rezaei'],
            ['instagram', 'instagram.com/ali_r', 'ali_r'],
            ['instagram', 'https://evil.example/ali', null],
            ['instagram', 'javascript:alert(1)', null],
            ['telegram', 't.me/ali_rezaei', 'ali_rezaei'],
            ['telegram', 'https://t.me/+989121234567', '+989121234567'],
            ['telegram', '۰۹۱۲۱۲۳۴۵۶۷', '+989121234567'],
            ['telegram', 'https://t.me/+AbCdInvite', null],
            ['whatsapp', '0912 123 4567', '+989121234567'],
            ['whatsapp', 'https://wa.me/989121234567', '+989121234567'],
            ['whatsapp', 'https://api.whatsapp.com/send?phone=4915112345678', '+4915112345678'],
            ['whatsapp', 'abc', null],
            ['linkedin', 'https://www.linkedin.com/in/ali-rezaei/', 'ali-rezaei'],
            ['youtube', 'https://youtube.com/@AliTV', 'AliTV'],
            ['x', 'https://twitter.com/ali_r', 'ali_r'],
            ['tiktok', 'https://www.tiktok.com/@ali.r', 'ali.r'],
            ['github', 'github.com/ali-r', 'ali-r'],
        ];
        foreach ($cases as [$network, $input, $expected]) {
            $this->assertSame($expected, SocialNetworks::normalize($network, $input), "{$network}: {$input}");
        }
        $this->assertSame('https://wa.me/989121234567', SocialNetworks::url('whatsapp', '+989121234567'));
        $this->assertSame('https://t.me/+989121234567', SocialNetworks::url('telegram', '+989121234567'));
        $this->assertSame('https://www.instagram.com/ali.rezaei/', SocialNetworks::url('instagram', 'ali.rezaei'));

        $me = $this->p['Me'];
        $this->actingAs($this->u['Me'], 'sanctum')->patchJson("/api/persons/{$me->id}", [
            'social' => ['instagram' => 'https://instagram.com/ali.rezaei', 'whatsapp' => '09121234567', 'telegram' => ''],
        ])->assertOk()
            ->assertJsonPath('data.social.instagram', 'ali.rezaei')
            ->assertJsonPath('data.social.whatsapp', '+989121234567')
            ->assertJsonPath('data.social_profiles.0.network', 'instagram')
            ->assertJsonPath('data.social_profiles.0.display', '@ali.rezaei')
            ->assertJsonPath('data.social_profiles.1.url', 'https://wa.me/989121234567');

        $this->patchJson("/api/persons/{$me->id}", ['social' => ['instagram' => 'https://evil.example/x']])
            ->assertStatus(422)->assertJsonValidationErrors('social.instagram');
    }

    public function test_automatic_doctor_and_engineer_titles(): void
    {
        $p = fn (array $attrs) => Person::factory()->make($attrs);

        $this->assertSame('دکتر', $p(['education_level' => 'phd'])->honorific());
        $this->assertSame('دکتر', $p(['education_level' => 'professional_doctorate', 'education_field_group' => 'medical'])->honorific());
        $this->assertSame('دکتر', $p(['education_level' => 'master', 'academic_rank' => 'professor'])->honorific());
        $this->assertSame('مهندس', $p(['education_level' => 'bachelor', 'education_field_group' => 'engineering'])->honorific());
        $this->assertSame('مهندس', $p(['education_level' => 'master', 'education_field' => 'مهندسی برق'])->honorific());
        $this->assertSame('مهندس', $p(['education_level' => 'bachelor', 'education_field_group' => 'architecture'])->honorific());
        // پرستاری و رشته‌های غیرفنی هرگز «مهندس» نمی‌شوند
        $this->assertNull($p(['education_level' => 'bachelor', 'education_field_group' => 'nursing'])->honorific());
        $this->assertNull($p(['education_level' => 'master', 'education_field' => 'پرستاری'])->honorific());
        $this->assertNull($p(['education_level' => 'bachelor', 'education_field' => 'حسابداری'])->honorific());
        // گروه انتخاب‌شده بر نام رشته مقدم است
        $this->assertNull($p(['education_level' => 'bachelor', 'education_field' => 'مهندسی پزشکی', 'education_field_group' => 'nursing'])->honorific());
        // کاردانی فنی «مهندس» نیست
        $this->assertNull($p(['education_level' => 'associate', 'education_field_group' => 'engineering'])->honorific());
        // دکترای رشته فنی: «دکتر»
        $this->assertSame('دکتر', $p(['education_level' => 'phd', 'education_field_group' => 'engineering'])->honorific());
        // خاموش‌کردن یا عنوان دستی تکراری
        $this->assertNull($p(['education_level' => 'phd', 'honorific_mode' => 'none'])->honorific());
        $this->assertNull($p(['education_level' => 'phd', 'title' => 'دکتر'])->honorific());

        // ترتیب با عنوان دستی
        $this->assertSame('حاج دکتر علی رضایی', $p(['first_name' => 'علی', 'last_name' => 'رضایی', 'title' => 'حاج', 'education_level' => 'phd'])->fullName());
        $this->assertSame('دکتر سید علی رضایی', $p(['first_name' => 'علی', 'last_name' => 'رضایی', 'title' => 'سید', 'education_level' => 'phd'])->fullName());
        $this->assertSame('شهید مهندس علی', $p(['first_name' => 'علی', 'last_name' => null, 'title' => 'شهید', 'education_level' => 'bachelor', 'education_field_group' => 'engineering'])->fullName());

        // در API و گره‌های درخت
        $me = $this->p['Me'];
        $this->actingAs($this->u['Me'], 'sanctum')->patchJson("/api/persons/{$me->id}", ['education_level' => 'bachelor', 'education_field_group' => 'engineering'])
            ->assertOk()->assertJsonPath('data.honorific', 'مهندس')->assertJsonPath('data.display_title', 'مهندس');
        $this->patchJson("/api/persons/{$me->id}", ['education_field_group' => 'nursing'])->assertOk()->assertJsonPath('data.honorific', null);
        $this->patchJson("/api/persons/{$me->id}", ['education_field_group' => 'rocket'])->assertJsonValidationErrors('education_field_group');
        $this->patchJson("/api/persons/{$me->id}", ['honorific_mode' => 'always'])->assertJsonValidationErrors('honorific_mode');

        // جستجو با عنوان
        $this->patchJson("/api/persons/{$me->id}", ['education_level' => 'phd'])->assertOk();
        $this->assertStringContainsString('دکتر', (string) $me->fresh()->search_text);
    }
}
