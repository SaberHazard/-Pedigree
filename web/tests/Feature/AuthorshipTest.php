<?php

namespace Tests\Feature;

use App\Models\Marriage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «ایجادکننده» هر چیز در سایت: نام کاربری عمومی (@sabertiger) یا نام کامل، با شناسه پروفایل برای لینک؛
 * جستجو با @نام‌کاربری و لینک‌های /api/u/{username}.
 */
class AuthorshipTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $person = [], array $user = []): User
    {
        return User::factory()->withPerson($person)->create($user)->refresh();
    }

    public function test_username_is_public_to_members_and_resolves_to_the_profile(): void
    {
        $saber = $this->member(['gender' => 'm', 'first_name' => 'محمد صابر', 'last_name' => 'حسینی فرجی'], ['username' => 'sabertiger']);
        $other = $this->member(['gender' => 'f', 'first_name' => 'مریم']);

        // هر عضو (نه فقط بستگان نزدیک) نام کاربری را در پروفایل می‌بیند
        $this->actingAs($other, 'sanctum')->getJson("/api/persons/{$saber->person_id}")->assertOk()
            ->assertJsonPath('data.account.username', 'sabertiger')
            ->assertJsonPath('data.account.has_password', null);

        // لینک @sabertiger (حروف بزرگ هم پذیرفته می‌شود)
        $this->getJson('/api/u/SaberTiger')->assertOk()
            ->assertJsonPath('data.person_id', $saber->person_id)
            ->assertJsonPath('data.username', 'sabertiger');
        $this->getJson('/api/u/nobody')->assertNotFound();
        // نویسه‌های غیرمجاز اصلاً به کنترلر نمی‌رسند
        $this->getJson('/api/u/'.rawurlencode("a'b"))->assertNotFound();
        $this->getJson('/api/u/'.str_repeat('a', 31))->assertNotFound();

        // مهمان دسترسی ندارد
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/u/sabertiger')->assertUnauthorized();
    }

    public function test_search_by_at_username_prefix_without_like_wildcards(): void
    {
        $saber = $this->member(['first_name' => 'صابر'], ['username' => 'saber_t']);
        $sabra = $this->member(['first_name' => 'صبرا'], ['username' => 'saberxt']);
        $me = $this->member(['first_name' => 'علی']);

        $this->actingAs($me, 'sanctum');
        $ids = collect($this->getJson('/api/persons?q='.rawurlencode('@saber'))->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$saber->person_id, $sabra->person_id], $ids->all());

        // «_» نویسه عام LIKE نیست: فقط saber_t
        $ids = collect($this->getJson('/api/persons?q='.rawurlencode('@saber_'))->json('data'))->pluck('id');
        $this->assertSame([$saber->person_id], $ids->all());

        // «%» در نام کاربری مجاز نیست، پس جستجوی نام معمولی انجام می‌شود و چیزی پیدا نمی‌شود
        $this->assertSame([], $this->getJson('/api/persons?q='.rawurlencode('@%'))->json('data'));
    }

    public function test_creators_and_uploaders_come_with_username_and_profile_link(): void
    {
        Storage::fake('media');
        $me = $this->member(['gender' => 'm', 'first_name' => 'علی'], ['username' => 'ali.r']);
        $this->actingAs($me, 'sanctum');
        $myId = $me->person_id;

        // پروفایل پدر را من ساخته‌ام
        $father = $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'father', 'first_name' => 'حسن'])->assertCreated()->json('data');
        $mother = $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'mother', 'first_name' => 'زهرا'])->assertCreated()->json('data');
        $this->getJson("/api/persons/{$father['id']}")->assertOk()
            ->assertJsonPath('data.creator.id', $me->id)
            ->assertJsonPath('data.creator.username', 'ali.r')
            ->assertJsonPath('data.creator.person_id', $myId);

        // ازدواج پدر و مادر را هم من ثبت کرده‌ام
        $marriage = Marriage::where('husband_id', $father['id'])->where('wife_id', $mother['id'])->firstOrFail();
        $this->getJson("/api/marriages/{$marriage->id}")->assertOk()
            ->assertJsonPath('data.creator.username', 'ali.r')
            ->assertJsonPath('data.creator.person_id', $myId);

        // عکس: آپلودکننده با نام کاربری و لینک پروفایل
        $media = $this->postJson("/api/persons/{$myId}/media", ['file' => UploadedFile::fake()->image('a.jpg', 800, 600)])->assertCreated()->json('data');
        $this->assertSame('ali.r', $media['uploader']['username']);
        $this->assertSame($myId, $media['uploader']['person_id']);

        // متن بلند: نسخه‌ها با نویسنده
        $this->putJson("/api/persons/{$myId}/texts/biography", ['text' => 'سلام'])->assertOk();
        $this->getJson("/api/persons/{$myId}/texts/biography/revisions")->assertOk()
            ->assertJsonPath('data.0.user.username', 'ali.r')
            ->assertJsonPath('data.0.user.person_id', $myId);

        // هر فیلد پروفایل: نویسنده در راهنمای رنگ با نام کاربری
        $this->patchJson("/api/persons/{$father['id']}", ['workplace' => 'بازار تهران'])->assertOk();
        $person = $this->getJson("/api/persons/{$father['id']}")->json('data');
        $this->assertSame($me->id, $person['field_meta']['workplace']['u']);
        $contributor = collect($person['contributors'])->firstWhere('id', $me->id);
        $this->assertSame('ali.r', $contributor['username']);
        $this->assertSame($myId, $contributor['person_id']);

        // بدون نام کاربری: نام کامل برمی‌گردد
        $me->forceFill(['username' => null])->save();
        $this->getJson("/api/persons/{$father['id']}")->assertJsonPath('data.creator.username', null)
            ->assertJsonPath('data.creator.name', $me->displayName());
    }
}
