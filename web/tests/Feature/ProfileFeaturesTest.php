<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\PersonText;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * پروفایل کامل: فیلدهای جدید، حریم نشانی/تماس، متن‌های رنگی با نویسنده هر کلمه،
 * رزومه، نظرها، امتیاز ویژگی‌ها، نقشه و تاریخچه عمومی.
 */
class ProfileFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private User $father;

    private User $me;

    private User $brother;

    private User $cousin;

    protected function setUp(): void
    {
        parent::setUp();
        // پدر، من، برادرم (فرزندان پدر) و یک عضو غیر درجه یک (پسرعمو)
        $this->father = $this->member(['gender' => 'm', 'first_name' => 'حسن']);
        $this->me = $this->member(['gender' => 'm', 'first_name' => 'علی'], $this->father->person_id);
        $this->brother = $this->member(['gender' => 'm', 'first_name' => 'رضا'], $this->father->person_id);
        $this->cousin = $this->member(['gender' => 'm', 'first_name' => 'مهدی']);
    }

    private function member(array $person = [], ?string $fatherId = null): User
    {
        $user = User::factory()->withPerson($person)->create()->refresh();
        if ($fatherId) {
            $user->person->forceFill(['father_id' => $fatherId])->save();
        }

        return $user->refresh();
    }

    public function test_rich_profile_fields_and_privacy(): void
    {
        $id = $this->me->person_id;
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", [
            'education_level' => 'phd',
            'education_field' => 'مهندسي برق',
            'academic_rank' => 'associate_professor',
            'workplace' => 'دانشگاه تهران',
            'country' => 'de',
            'province' => 'برلین',
            'city' => 'برلین',
            'address' => 'Alexanderplatz 1',
            'postal_code' => '10178',
            'home_lat' => '۵۲.۵۲۱۹',
            'home_lng' => '13.4132',
            'landline' => '+49 30 1234',
            'website' => 'example.org',
            'social' => ['telegram' => '@ali_rezaei', 'instagram' => ''],
            'contact_visibility' => 'd1',
            'blood_type' => 'O+',
            'custom_fields' => [['label' => 'غذای محبوب', 'value' => 'قورمه‌سبزی'], ['label' => '', 'value' => 'x']],
            'burial_lat' => null,
        ])->assertOk()
            ->assertJsonPath('data.education_level', 'phd')
            ->assertJsonPath('data.education_field', 'مهندسی برق')
            ->assertJsonPath('data.country', 'DE')
            ->assertJsonPath('data.home_location.lat', 52.5219)
            ->assertJsonPath('data.website', 'https://example.org')
            ->assertJsonPath('data.social.telegram', 'ali_rezaei')
            ->assertJsonPath('data.social_profiles.0.url', 'https://t.me/ali_rezaei')
            ->assertJsonPath('data.custom_fields.0.value', 'قورمه‌سبزی')
            ->assertJsonCount(1, 'data.custom_fields');

        // نشانی و مختصات رمزنگاری‌شده ذخیره می‌شوند
        $raw = DB::table('persons')->where('id', $id)->first();
        $this->assertStringNotContainsString('Alexanderplatz', $raw->address);
        $this->assertStringNotContainsString('52.5219', $raw->home_lat);

        // در تاریخچه مقدار نشانی ماسک می‌شود
        $log = ActivityLog::where('action', 'person.updated')->latest('id')->first();
        $this->assertSame(['***', '***'], $log->properties['changes']['address']);

        // عضو غیر درجه یک: نشانی و تماس را نمی‌بیند؛ برادر (ویرایشگر) می‌بیند
        $this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}")
            ->assertJsonPath('data.address', null)->assertJsonPath('data.home_location', null)
            ->assertJsonPath('data.landline', null)->assertJsonPath('data.has_home_location', true)
            ->assertJsonPath('data.city', 'برلین');
        $this->actingAs($this->brother, 'sanctum')->getJson("/api/persons/{$id}")
            ->assertJsonPath('data.address', 'Alexanderplatz 1');

        // با اجازه خود شخص همه اعضا می‌بینند
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", ['location_visibility' => 'all', 'contact_visibility' => 'all'])->assertOk();
        $this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}")
            ->assertJsonPath('data.address', 'Alexanderplatz 1')
            ->assertJsonPath('data.landline', '+49 30 1234');

        // اعتبارسنجی: کد پستی ایران ۱۰ رقمی، کشور نامعتبر، مختصات ناقص
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", ['country' => 'IR', 'postal_code' => '123'])
            ->assertStatus(422)->assertJsonValidationErrors('postal_code');
        $this->patchJson("/api/persons/{$id}", ['country' => 'XX'])->assertJsonValidationErrors('country');
        $this->patchJson("/api/persons/{$id}", ['home_lat' => '35.7', 'home_lng' => null])->assertJsonValidationErrors('home_lng');
        $this->patchJson("/api/persons/{$id}", ['education_level' => 'wizard'])->assertJsonValidationErrors('education_level');
        $this->patchJson("/api/persons/{$id}", ['social' => ['myspace' => 'x']])->assertJsonValidationErrors('social');
        $this->patchJson("/api/persons/{$id}", ['contact_visibility' => 'd9'])->assertJsonValidationErrors('contact_visibility');
    }

    public function test_field_editors_are_tracked_with_colors(): void
    {
        $fatherId = $this->father->person_id;
        // پدر شغل خودش را می‌نویسد، پسرش محل تولد پدر را
        $this->actingAs($this->father, 'sanctum')->patchJson("/api/persons/{$fatherId}", ['occupation' => 'کشاورز'])->assertOk();
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$fatherId}", ['birth_place' => 'یزد'])->assertOk();

        $data = $this->getJson("/api/persons/{$fatherId}")->json('data');
        $this->assertSame($this->father->id, $data['field_meta']['occupation']['u']);
        $this->assertSame($this->me->id, $data['field_meta']['birth_place']['u']);

        $byId = collect($data['contributors'])->keyBy('id');
        $this->assertSame('owner', $byId[$this->father->id]['color']);
        $this->assertSame('خود شخص', $byId[$this->father->id]['relation']);
        $this->assertSame(0, $byId[$this->me->id]['color']);
        $this->assertSame('پسر', $byId[$this->me->id]['relation']);
    }

    public function test_attributed_text_keeps_author_of_every_word(): void
    {
        $id = $this->father->person_id;

        // پدر زندگی‌نامه خودش را می‌نویسد (با غلط املایی)
        $r1 = $this->actingAs($this->father, 'sanctum')->putJson("/api/persons/{$id}/texts/biography", [
            'text' => "در سال ۱۳۰۵ در یزد بدنیا امدم.\nکشاورز بودم.",
            'base_revision' => 0,
        ])->assertOk()->json('data');
        $this->assertSame(1, $r1['revision']);

        // پسرش فقط غلط املایی را درست می‌کند و یک خط اضافه می‌کند
        $r2 = $this->actingAs($this->me, 'sanctum')->putJson("/api/persons/{$id}/texts/biography", [
            'text' => "در سال ۱۳۰۵ در یزد بدنیا آمدم.\nکشاورز بودم.\nپدرم مردی مهربان بود.",
            'base_revision' => 1,
        ])->assertOk()->json();
        $this->assertSame(2, $r2['data']['revision']);

        $byAuthor = [];
        foreach ($r2['data']['segments'] as [$author, $text]) {
            $byAuthor[$author] = ($byAuthor[$author] ?? '').$text;
        }
        // کلمه اصلاح‌شده و خط تازه به نام پسر، بقیه به نام پدر
        $this->assertStringContainsString('آمدم.', $byAuthor[$this->me->id]);
        $this->assertStringContainsString('پدرم مردی مهربان بود.', $byAuthor[$this->me->id]);
        $this->assertStringContainsString('در سال ۱۳۰۵ در یزد بدنیا', $byAuthor[$this->father->id]);
        $this->assertStringContainsString('کشاورز بودم.', $byAuthor[$this->father->id]);
        $this->assertStringNotContainsString('بدنیا', $byAuthor[$this->me->id]);

        // راهنمای رنگ: خود شخص + پسر
        $colors = collect($r2['contributors'])->pluck('color', 'id');
        $this->assertSame('owner', $colors[$this->father->id]);
        $this->assertSame(0, $colors[$this->me->id]);

        // ویرایش هم‌زمان روی نسخه قدیمی رد می‌شود (تغییرات دیگری پاک نشود)
        $this->actingAs($this->brother, 'sanctum')->putJson("/api/persons/{$id}/texts/biography", [
            'text' => 'متن دیگر', 'base_revision' => 1,
        ])->assertStatus(409)->assertJsonPath('code', 'text_conflict');

        // پسرعمو (درجه یک نیست) نمی‌تواند ویرایش کند ولی نسخه‌ها را می‌بیند
        $this->actingAs($this->cousin, 'sanctum')->putJson("/api/persons/{$id}/texts/biography", ['text' => 'x'])->assertForbidden();
        $revisions = $this->getJson("/api/persons/{$id}/texts/biography/revisions")->assertOk()->json('data');
        $this->assertCount(2, $revisions);
        $this->assertSame($this->me->id, $revisions[0]['user']['id']);
        $this->assertSame(1, $revisions[0]['removed']);

        // بازگردانی نسخه ۱ (نویسندگان آن نسخه حفظ می‌شوند)
        $restored = $this->actingAs($this->brother, 'sanctum')->postJson("/api/persons/{$id}/texts/biography/revisions/1/restore")->assertOk()->json('data');
        $this->assertSame(3, $restored['revision']);
        $this->assertSame([[$this->father->id, "در سال ۱۳۰۵ در یزد بدنیا امدم.\nکشاورز بودم."]], $restored['segments']);
        $this->assertSame("در سال ۱۳۰۵ در یزد بدنیا امدم.\nکشاورز بودم.", Person::find($id)->biography);

        // فیلد نامعتبر
        $this->putJson("/api/persons/{$id}/texts/secret", ['text' => 'x'])->assertNotFound();

        // همه تغییرات در تاریخچه پروفایل (برای همه اعضا) دیده می‌شود
        $actions = collect($this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}/history")->assertOk()->json('data'))->pluck('action');
        $this->assertContains('text.updated', $actions);
        $this->assertContains('text.restored', $actions);
    }

    public function test_legacy_biography_field_goes_through_attribution(): void
    {
        $id = $this->me->person_id;
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", ['biography' => 'سلام'])->assertOk();
        $text = PersonText::where('person_id', $id)->where('field', 'biography')->first();
        $this->assertSame([[$this->me->id, 'سلام']], $text->segments);
    }

    public function test_resume_items_crud_is_logged(): void
    {
        $id = $this->me->person_id;
        $item = $this->actingAs($this->brother, 'sanctum')->postJson("/api/persons/{$id}/resume", [
            'type' => 'education', 'title' => 'کارشناسی مهندسی', 'organization' => 'دانشگاه صنعتی شریف',
            'start_date' => '۱۳۸۵', 'end_date' => '1389/06',
        ])->assertCreated()->json('data');
        $this->assertSame('1389-06', $item['end_date']);

        $this->patchJson("/api/resume/{$item['id']}", ['is_current' => true])->assertOk()->assertJsonPath('data.end_date', null);
        $this->getJson("/api/persons/{$id}")->assertJsonPath('data.resume.0.title', 'کارشناسی مهندسی');

        $this->actingAs($this->cousin, 'sanctum')->deleteJson("/api/resume/{$item['id']}")->assertForbidden();
        $this->actingAs($this->me, 'sanctum')->deleteJson("/api/resume/{$item['id']}")->assertOk();

        $logs = ActivityLog::whereIn('action', ['resume.created', 'resume.updated', 'resume.deleted'])->orderBy('id')->pluck('action')->all();
        $this->assertSame(['resume.created', 'resume.updated', 'resume.deleted'], $logs);
        $this->assertSame('کارشناسی مهندسی', ActivityLog::where('action', 'resume.deleted')->first()->properties['title']);

        $this->postJson("/api/persons/{$id}/resume", ['type' => 'spaceflight', 'title' => 'x'])->assertJsonValidationErrors('type');
    }

    public function test_comments_by_any_member_with_moderation(): void
    {
        $id = $this->me->person_id;
        $comment = $this->actingAs($this->cousin, 'sanctum')->postJson("/api/persons/{$id}/comments", ['body' => '  آدم بسیار خوش‌برخوردی است  '])
            ->assertCreated()->json('data');
        $this->assertSame('آدم بسیار خوش‌برخوردی است', $comment['body']);
        $this->assertSame($this->cousin->id, $comment['author']['id']);

        // صاحب پروفایل مطلع می‌شود
        $this->assertSame(1, $this->me->fresh()->unreadNotifications()->count());

        // فقط نویسنده ویرایش می‌کند
        $this->actingAs($this->brother, 'sanctum')->patchJson("/api/comments/{$comment['id']}", ['body' => 'x'])->assertForbidden();
        $this->actingAs($this->cousin, 'sanctum')->patchJson("/api/comments/{$comment['id']}", ['body' => 'آدم بسیار خوش‌برخورد و مهربانی است'])
            ->assertOk()->assertJsonPath('data.body', 'آدم بسیار خوش‌برخورد و مهربانی است');

        // برادر (ویرایشگر) می‌تواند مخفی کند ولی حذف نه
        $this->actingAs($this->brother, 'sanctum')->deleteJson("/api/comments/{$comment['id']}")->assertForbidden();
        $this->postJson("/api/comments/{$comment['id']}/hide", ['hidden' => true])->assertOk()->assertJsonPath('data.hidden', true);

        // عضو دیگر نظر مخفی را نمی‌بیند؛ نویسنده و ویرایشگران می‌بینند
        $other = $this->member();
        $this->actingAs($other, 'sanctum')->getJson("/api/persons/{$id}/comments")->assertJsonCount(0, 'data');
        $this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}/comments")->assertJsonCount(1, 'data');
        // پسرعمو نمی‌تواند نظرهای پروفایل «من» را مدیریت کند
        $this->postJson("/api/comments/{$comment['id']}/hide", ['hidden' => false])->assertForbidden();

        $this->deleteJson("/api/comments/{$comment['id']}")->assertOk();
        $this->assertSoftDeleted('person_comments', ['id' => $comment['id']]);
        $this->assertTrue(ActivityLog::where('action', 'comment.deleted')->exists());

        $this->postJson("/api/persons/{$id}/comments", ['body' => '   '])->assertStatus(422);
    }

    public function test_trait_ratings(): void
    {
        $id = $this->me->person_id;
        $this->actingAs($this->me, 'sanctum')->putJson("/api/persons/{$id}/ratings", ['scores' => ['humor' => 5]])->assertForbidden();

        $this->actingAs($this->cousin, 'sanctum')->putJson("/api/persons/{$id}/ratings", ['scores' => ['humor' => 5, 'charisma' => 3]])->assertOk();
        $res = $this->actingAs($this->brother, 'sanctum')->putJson("/api/persons/{$id}/ratings", ['scores' => ['humor' => 4]])->assertOk()->json();

        $humor = collect($res['traits'])->firstWhere('key', 'humor');
        $this->assertEquals(4.5, $humor['average']);
        $this->assertSame(2, $humor['count']);
        $this->assertSame(4, $humor['mine']);
        $this->assertSame([0, 0, 0, 1, 1], $humor['distribution']);
        $this->assertSame(2, $res['raters_count']);
        $this->assertCount(2, $res['raters']);

        // تغییر و حذف امتیاز
        $this->putJson("/api/persons/{$id}/ratings", ['scores' => ['humor' => null]])->assertOk();
        $humor = collect($this->getJson("/api/persons/{$id}/ratings")->json('traits'))->firstWhere('key', 'humor');
        $this->assertSame(1, $humor['count']);

        $this->putJson("/api/persons/{$id}/ratings", ['scores' => ['humor' => 6]])->assertStatus(422);
        $this->putJson("/api/persons/{$id}/ratings", ['scores' => ['beauty_of_soul' => 3]])->assertStatus(422);
    }

    public function test_family_map_respects_location_privacy(): void
    {
        $this->me->person->forceFill(['home_lat' => '35.7', 'home_lng' => '51.4', 'location_visibility' => 'd1'])->save();
        $this->cousin->person->forceFill(['home_lat' => '32.6', 'home_lng' => '51.6', 'location_visibility' => 'all'])->save();
        $grave = Person::factory()->deceased()->create();
        $grave->forceFill(['burial_lat' => 31.9, 'burial_lng' => 54.3])->save();

        // برادر: خانه من (درجه یک) + خانه پسرعمو (عمومی) + مزار
        $points = collect($this->actingAs($this->brother, 'sanctum')->getJson('/api/map')->assertOk()->json('data'));
        $this->assertEqualsCanonicalizing([$this->me->person_id, $this->cousin->person_id], $points->where('kind', 'home')->pluck('person.id')->all());
        $this->assertSame([$grave->id], $points->where('kind', 'burial')->pluck('person.id')->values()->all());

        // عضو دیگر: فقط خانه عمومی پسرعمو
        $points = collect($this->actingAs($this->member(), 'sanctum')->getJson('/api/map')->json('data'));
        $this->assertSame([$this->cousin->person_id], $points->where('kind', 'home')->pluck('person.id')->values()->all());
    }

    public function test_creator_edit_window_and_first_degree_rule(): void
    {
        // پسرعمو برای «من» یک فرزند نمی‌تواند بسازد (درجه یک نیست)
        $this->actingAs($this->cousin, 'sanctum')->postJson("/api/persons/{$this->me->person_id}/relatives", ['type' => 'child', 'first_name' => 'x', 'gender' => 'm'])
            ->assertForbidden();

        // من برای خودم فرزند می‌سازم؛ برادرم (عموی بچه) درجه یک او نیست
        $child = $this->actingAs($this->me, 'sanctum')->postJson("/api/persons/{$this->me->person_id}/relatives", ['type' => 'child', 'first_name' => 'کودک', 'gender' => 'f'])
            ->assertCreated()->json('data');
        $this->actingAs($this->brother, 'sanctum')->patchJson("/api/persons/{$child['id']}", ['nickname' => 'x'])->assertForbidden();

        // نوه (فرزند من) را پدرم ساخته باشد: پس از ۷۲ ساعت دیگر حق ویرایش ندارد
        $grandchild = Person::factory()->create(['created_by' => $this->father->id]);
        $grandchild->forceFill(['father_id' => $this->me->person_id])->save();
        $this->actingAs($this->father, 'sanctum')->patchJson("/api/persons/{$grandchild->id}", ['nickname' => 'نوه'])->assertOk();
        $this->travel(73)->hours();
        $this->actingAs($this->father, 'sanctum')->patchJson("/api/persons/{$grandchild->id}", ['nickname' => 'نوه عزیز'])->assertForbidden();
        // پدرِ نوه (من) همچنان ویرایش می‌کند
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$grandchild->id}", ['nickname' => 'نوه عزیز'])->assertOk();
    }

    public function test_history_is_visible_to_all_members_without_ip(): void
    {
        $id = $this->me->person_id;
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", ['nickname' => 'علی‌آقا'])->assertOk();
        $logs = $this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}/history")->assertOk()->json('data');
        $this->assertNotEmpty($logs);
        $this->assertNull($logs[0]['ip_address']);

        config(['pedigree.permissions.history_public' => false]);
        $this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}/history")->assertForbidden();
    }

    public function test_public_history_does_not_leak_private_data(): void
    {
        $id = $this->me->person_id;
        // ایمیل تغییر کرد، یک ورود ثبت شد و نظری نوشته و سپس مخفی شد
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", ['email' => 'secret@example.com'])->assertOk();
        app(AuditLogger::class)->log('auth.login', $this->me->person, ['method' => 'otp'], $this->me);
        $comment = $this->actingAs($this->cousin, 'sanctum')->postJson("/api/persons/{$id}/comments", ['body' => 'متن توهین‌آمیز'])->json('data');
        $this->actingAs($this->me, 'sanctum')->postJson("/api/comments/{$comment['id']}/hide")->assertOk();

        $other = $this->member();
        $raw = $this->actingAs($other, 'sanctum')->getJson("/api/persons/{$id}/history")->assertOk()->getContent();
        $this->assertStringNotContainsString('secret@example.com', $raw);
        $this->assertStringNotContainsString('توهین', $raw);
        $this->assertStringNotContainsString('auth.login', $raw);

        // خود شخص ورودهایش را می‌بیند
        $own = $this->actingAs($this->me, 'sanctum')->getJson("/api/persons/{$id}/history")->getContent();
        $this->assertStringContainsString('auth.login', $own);
    }

    public function test_completeness_is_shown_to_editors_only(): void
    {
        $id = $this->me->person_id;
        $mine = $this->actingAs($this->me, 'sanctum')->getJson("/api/persons/{$id}")->json('data.completeness');
        $this->assertIsInt($mine['percent']);
        $this->assertContains('summary', $mine['missing']);
        $this->assertNotContains('father', $mine['missing']);

        $this->actingAs($this->cousin, 'sanctum')->getJson("/api/persons/{$id}")->assertJsonPath('data.completeness', null);
    }
}
