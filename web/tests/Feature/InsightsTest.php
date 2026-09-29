<?php

namespace Tests\Feature;

use App\Models\Marriage;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Social\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بینش‌های خاندان: بررسی ناسازگاری، آمار، خط زمان زندگی، زندگی‌نامه‌نویس و خواندن عکس با هوش مصنوعی.
 */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        SafeHttp::fakeResolver(fn () => ['142.250.185.10']);
        config([
            'pedigree.ai.enabled' => true,
            'pedigree.ai.provider' => 'gemini',
            'pedigree.ai.fallback_provider' => '',
            'pedigree.ai.proxy' => null,
            'pedigree.social.proxy' => null,
            'pedigree.ai.daily_per_user' => 40,
            'pedigree.ai.providers.gemini' => ['api_key' => 'GEMINI-SECRET', 'model' => 'gemini-flash-latest'],
        ]);
        $this->me = User::factory()->withPerson([
            'first_name' => 'علی', 'last_name' => 'احمدی', 'gender' => 'm', 'birth_date' => '1340-05-10', 'birth_place' => 'تفرش',
            'national_code' => '0012345679', 'phone' => '09121234567', 'occupation' => 'معلم',
        ])->create()->refresh();
    }

    private function person(array $attrs): Person
    {
        return Person::factory()->create($attrs + ['created_by' => $this->me->id]);
    }

    public function test_consistency_checker_finds_impossible_and_unlikely_data(): void
    {
        $father = $this->person(['first_name' => 'حسن', 'gender' => 'm', 'birth_date' => '1330', 'is_deceased' => true, 'death_date' => '1338']);
        $mother = $this->person(['first_name' => 'زهرا', 'gender' => 'f', 'birth_date' => '1332', 'is_deceased' => true, 'death_date' => '1339-01-01']);
        // پدر در ۱۰ سالگی، تولد پس از فوت مادر و بیش از ۱۰ ماه پس از فوت پدر
        $child = $this->person(['first_name' => 'رضا', 'last_name' => 'احمدی', 'gender' => 'm', 'birth_date' => '1340-02-01', 'father_id' => $father->id, 'mother_id' => $mother->id]);
        // تکراری (هم‌نام از همان پدر)
        $dup = $this->person(['first_name' => 'رضا', 'last_name' => 'احمدی', 'gender' => 'm', 'father_id' => $father->id]);
        // وفات پیش از تولد
        $weird = $this->person(['first_name' => 'مریم', 'gender' => 'f', 'birth_date' => '1350', 'is_deceased' => true, 'death_date' => '1349']);
        // ازدواج پیش از تولد همسر
        $wife = $this->person(['first_name' => 'سارا', 'gender' => 'f', 'birth_date' => '1360']);
        Marriage::create(['husband_id' => $this->me->person_id, 'wife_id' => $wife->id, 'marriage_date' => '1355', 'status' => 'married']);
        // داده درست: بدون هشدار
        $this->person(['first_name' => 'سالم', 'gender' => 'm', 'birth_date' => '1365', 'father_id' => $this->me->person_id, 'mother_id' => $wife->id]);

        $data = $this->actingAs($this->me, 'sanctum')->getJson('/api/insights/consistency')->assertOk()->json('data');
        $codes = collect($data['issues'])->groupBy('code');
        $this->assertTrue($codes->has('parent_too_young'));
        $this->assertSame($child->id, $codes['born_after_mother_death'][0]['person']['id']);
        $this->assertSame($mother->id, $codes['born_after_mother_death'][0]['other']['id']);
        $this->assertTrue($codes->has('born_after_father_death'));
        $this->assertSame($weird->id, $codes['death_before_birth'][0]['person']['id']);
        $this->assertSame($wife->id, $codes['marriage_before_birth'][0]['person']['id']);
        $this->assertSame($dup->id, $codes['possible_duplicate'][0]['person']['id']);
        $this->assertSame('error', $data['issues'][0]['level']);
        $this->assertArrayHasKey('parent_too_young', $data['codes']);
        // شخص سالم هیچ موردی ندارد
        $this->assertFalse(collect($data['issues'])->contains(fn ($i) => $i['person']['name'] === 'سالم'));
    }

    public function test_clan_statistics(): void
    {
        $grandpa = $this->person(['first_name' => 'حسن', 'gender' => 'm', 'birth_date' => '1300', 'is_deceased' => true, 'death_date' => '1380']);
        $grandma = $this->person(['first_name' => 'فاطمه', 'gender' => 'f', 'birth_date' => '1305', 'is_deceased' => true, 'death_date' => '1395']);
        $this->me->person->forceFill(['father_id' => $grandpa->id, 'mother_id' => $grandma->id])->save();
        $this->person(['first_name' => 'حسین', 'gender' => 'm', 'birth_date' => '1342', 'father_id' => $grandpa->id, 'mother_id' => $grandma->id]);
        $this->person(['first_name' => 'آرین', 'gender' => 'm', 'birth_date' => '1370', 'father_id' => $this->me->person_id]);

        $d = $this->actingAs($this->me, 'sanctum')->getJson('/api/insights/stats')->assertOk()->json('data');
        $this->assertSame(5, $d['totals']['persons']);
        $this->assertSame(2, $d['totals']['deceased']);
        $this->assertSame(3, $d['totals']['generations']);
        // ۱۳۰۰ تا ۱۳۸۰ (۷۹ یا ۸۰) و ۱۳۰۵ تا ۱۳۹۵ (۸۹ یا ۹۰): میانگین تقریبی ۸۴٫۵
        $this->assertEquals(84.5, $d['lifespan']['all']);
        $this->assertSame($grandpa->id, $d['records']['most_children']['person']['id']);
        $this->assertSame(2, $d['records']['most_children']['value']);
        $this->assertSame(1, $d['records']['most_grandchildren']['value']);
        $this->assertEquals([['decade' => 1300, 'avg' => 2, 'mothers' => 1]], $d['family_size']);
        $this->assertSame(2, collect($d['births_by_decade'])->firstWhere('decade', 1340)['count']);
        // اطلاعات حساس در آمار نیست
        $this->assertStringNotContainsString('0912', json_encode($d));
    }

    public function test_life_timeline_with_family_and_historical_events(): void
    {
        $uncle = $this->person(['first_name' => 'محمود', 'gender' => 'm', 'birth_date' => '1340-05-10', 'is_deceased' => true, 'death_date' => '1390-01-01']);
        $wife = $this->person(['first_name' => 'سارا', 'gender' => 'f', 'birth_date' => '1345']);
        Marriage::create(['husband_id' => $uncle->id, 'wife_id' => $wife->id, 'marriage_date' => '1365-06-01', 'status' => 'married']);
        $this->person(['first_name' => 'آرین', 'gender' => 'm', 'birth_date' => '1368-02-02', 'father_id' => $uncle->id, 'mother_id' => $wife->id]);

        $d = $this->actingAs($this->me, 'sanctum')->getJson("/api/persons/{$uncle->id}/timeline")->assertOk()->json('data');
        $events = collect($d['events']);
        $this->assertSame('birth', $events->first()['kind']);
        $this->assertSame('death', $events->last()['kind']);
        $this->assertSame(25, $events->firstWhere('kind', 'marriage')['age']);
        $this->assertFalse($events->firstWhere('kind', 'marriage')['approx']);
        $this->assertSame(27, $events->firstWhere('kind', 'child')['age']);
        // انقلاب ۱۳۵۷ در ۱۷ سالگی؛ رویدادهای پس از فوت نمی‌آیند
        $revolution = $events->first(fn ($e) => $e['kind'] === 'history' && $e['year'] === 1357);
        $this->assertSame(17, $revolution['age']);
        $this->assertSame('iran', $revolution['category']);
        $this->assertNull($events->first(fn ($e) => $e['kind'] === 'history' && $e['year'] > 1390));
        // مرتب بر اساس تاریخ
        $years = $events->pluck('year')->all();
        $sorted = $years;
        sort($sorted);
        $this->assertSame($sorted, $years);
    }

    public function test_ai_biographer_uses_only_public_profile_facts_and_needs_edit_rights(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => "## زندگی‌نامه\nعلی احمدی در سال ۱۳۴۰ در **تفرش** به دنیا آمد."]]]]],
        ])]);
        $this->me->person->forceFill(['address' => 'تهران، خیابان آزادی ۱۲', 'email' => 'ali@example.com'])->save();

        $res = $this->actingAs($this->me, 'sanctum')->postJson("/api/persons/{$this->me->person_id}/ai/biography", ['tone' => 'formal'])->assertOk();
        // Markdown پاک می‌شود
        $this->assertSame('علی احمدی در سال ۱۳۴۰ در تفرش به دنیا آمد.', $res->json('data.text'));
        $this->assertSame(39, $res->json('data.remaining'));

        Http::assertSent(function (Request $r) {
            $body = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return str_contains($body, 'علی') && str_contains($body, 'تفرش') && str_contains($body, 'معلم') && str_contains($body, 'رسمی')
                && ! str_contains($body, '0012345679') && ! str_contains($body, '0912') && ! str_contains($body, 'آزادی')
                && ! str_contains($body, 'ali@example.com');
        });

        // کسی که اجازه ویرایش این پروفایل را ندارد نمی‌تواند
        $stranger = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/persons/{$this->me->person_id}/ai/biography")->assertForbidden();

        // مدیر کل خاموشش کرده
        config(['pedigree.ai.biographer' => false]);
        $this->actingAs($this->me, 'sanctum')->postJson("/api/persons/{$this->me->person_id}/ai/biography")->assertStatus(403);
        $this->actingAs($this->me, 'sanctum')->postJson("/api/persons/{$this->me->person_id}/ai/biography", ['tone' => 'bad'])->assertStatus(422);
    }

    public function test_ai_photo_reader_sends_only_the_photo_and_respects_access(): void
    {
        Storage::fake('media');
        $upload = $this->actingAs($this->me, 'sanctum')
            ->postJson("/api/persons/{$this->me->person_id}/media", ['file' => UploadedFile::fake()->image('old.jpg', 900, 700)])
            ->assertCreated()->json('data');
        $media = Media::findOrFail($upload['id']);

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => "سه نفر در حیاط خانه‌ای قدیمی.\nدهه تقریبی: ۱۳۴۰ (۱۹۶۰)"]]]]],
        ])]);
        $res = $this->postJson("/api/media/{$media->id}/ai/read", ['task' => 'describe'])->assertOk();
        $this->assertStringContainsString('دهه تقریبی', $res->json('data.text'));
        $this->assertTrue($res->json('data.can_save'));
        Http::assertSent(function (Request $r) {
            $parts = $r->data()['contents'][0]['parts'];

            return count($parts) === 2 && $parts[1]['inline_data']['mime_type'] === 'image/jpeg' && strlen($parts[1]['inline_data']['data']) > 100
                && ! str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), 'علی');
        });

        $this->postJson("/api/media/{$media->id}/ai/read", ['task' => 'hack'])->assertStatus(422);

        // عکس در انتظار تأیید برای غریبه قابل دیدن نیست
        $media->forceFill(['status' => Media::STATUS_PENDING])->save();
        $stranger = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($stranger, 'sanctum')->postJson("/api/media/{$media->id}/ai/read", ['task' => 'describe'])->assertForbidden();

        config(['pedigree.ai.vision' => false]);
        $media->forceFill(['status' => Media::STATUS_APPROVED])->save();
        $this->actingAs($this->me, 'sanctum')->postJson("/api/media/{$media->id}/ai/read", ['task' => 'describe'])->assertStatus(403);
    }

    public function test_vision_request_formats_for_openai_and_claude(): void
    {
        Storage::fake('media');
        $id = $this->actingAs($this->me, 'sanctum')
            ->postJson("/api/persons/{$this->me->person_id}/media", ['file' => UploadedFile::fake()->image('doc.jpg', 800, 600)])->json('data.id');

        config(['pedigree.ai.provider' => 'openai', 'pedigree.ai.providers.openai' => ['api_key' => 'OPENAI-SECRET', 'model' => 'gpt-4.1-mini']]);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'متن نامه ...']]]])]);
        $this->postJson("/api/media/{$id}/ai/read", ['task' => 'transcribe'])->assertOk()->assertJsonPath('data.text', 'متن نامه ...');
        Http::assertSent(function (Request $r) {
            $content = $r->data()['messages'][1]['content'] ?? null;

            return is_array($content) && $content[0]['type'] === 'text' && str_starts_with($content[1]['image_url']['url'], 'data:image/jpeg;base64,');
        });

        config(['pedigree.ai.provider' => 'anthropic', 'pedigree.ai.providers.anthropic' => ['api_key' => 'CLAUDE-SECRET', 'model' => 'claude-model']]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'خلاصه: نامه']]])
            ->push(['error' => ['message' => 'image not supported']], 400)]);
        $this->postJson("/api/media/{$id}/ai/read", ['task' => 'transcribe'])->assertOk();
        Http::assertSent(fn (Request $r) => ($r->data()['messages'][0]['content'][0]['type'] ?? null) === 'image'
            && $r->data()['messages'][0]['content'][0]['source']['media_type'] === 'image/jpeg');

        // مدلی که عکس نمی‌پذیرد: پیام روشن برای مدیر
        $this->postJson("/api/media/{$id}/ai/read", ['task' => 'describe'])->assertStatus(502)->assertJsonPath('code', 'ai_vision');
    }
}
