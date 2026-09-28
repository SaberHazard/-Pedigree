<?php

namespace Tests\Feature;

use App\Models\GroupMessage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * گروه خاطرات خاندان: متن و خاطره تصویری، قوانین ضد اسپم، واکنش، پاسخ، گزارش و مدیریت.
 */
class GroupTest extends TestCase
{
    use RefreshDatabase;

    private User $ali;

    private User $sara;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['pedigree.group.slow_mode_seconds' => 0]);
        $this->ali = User::factory()->withPerson(['first_name' => 'علی'])->create(['last_login_at' => now()])->refresh();
        $this->sara = User::factory()->withPerson(['first_name' => 'سارا'])->create(['last_login_at' => now()])->refresh();
        $this->admin = User::factory()->withPerson(['first_name' => 'مدیر'])->create(['role' => User::ROLE_ADMIN, 'last_login_at' => now()])->refresh();
    }

    private function say(User $user, string $body, array $extra = [])
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/group/messages', ['body' => $body] + $extra);
    }

    private function photo(): UploadedFile
    {
        $img = imagecreatetruecolor(300, 200);
        imagefilledrectangle($img, 0, 0, 300, 200, imagecolorallocate($img, random_int(0, 255), 90, 40));
        $path = tempnam(sys_get_temp_dir(), 'old').'.png';
        imagepng($img, $path);

        return new UploadedFile($path, 'old-photo.png', 'image/png', null, true);
    }

    public function test_members_chat_and_emoji_only_is_rejected(): void
    {
        $id = $this->say($this->ali, 'سلام به همه 👋 یادتان هست عید ۱۳۶۵؟')->assertCreated()
            ->assertJsonPath('data.mine', true)->assertJsonPath('data.user.name', fn ($n) => str_contains($n, 'علی'))->json('data.id');

        // فقط ایموجی (یا علامت) پذیرفته نیست
        foreach (['😂😂😂', '❤️ 🌹', '!!! ؟؟؟', "  \n "] as $bad) {
            $this->say($this->sara, $bad)->assertStatus(422);
        }
        $this->say($this->sara, '😂😂')->assertJsonPath('code', 'emoji_only');
        // ایموجی همراه متن مشکلی ندارد
        $this->say($this->sara, 'آره یادمه 😂❤️', ['reply_to' => $id])->assertCreated()
            ->assertJsonPath('data.reply_to.id', $id);

        // پاسخ = اعلان برای نویسنده پیام اصلی
        $this->assertSame('group_reply', $this->ali->notifications()->first()->data['kind']);

        // متن در پایگاه داده رمزنگاری‌شده است
        $this->assertStringNotContainsString('یادمه', DB::table('group_messages')->pluck('body')->implode(' '));

        // شمارنده نخوانده و خواندن
        $this->actingAs($this->ali, 'sanctum')->getJson('/api/auth/me')->assertJsonPath('data.counters.group', 1);
        $last = $this->getJson('/api/group/messages')->assertOk()->assertJsonCount(2, 'data')->json('data.1.id');
        $this->postJson('/api/group/read', ['up_to' => $last])->assertJsonPath('unread', 0);
    }

    public function test_anti_spam_rules(): void
    {
        // لینک
        $this->say($this->ali, 'ببینید https://spam.example/x')->assertStatus(422)->assertJsonPath('code', 'links');
        $this->say($this->ali, 'عضو کانال شوید t.me/spam')->assertStatus(422);
        $this->say($this->ali, 'سایت ما: shop-cheap.ir')->assertStatus(422);
        // تکراری
        $this->say($this->ali, 'سلام خوبید؟')->assertCreated();
        $this->say($this->ali, 'سلام خوبید؟')->assertStatus(422)->assertJsonPath('code', 'duplicate');
        // بیش از حد طولانی
        $this->say($this->ali, str_repeat('ب', 2001))->assertStatus(422);
        // نویسه‌های مخفی جهت‌دهی حذف می‌شوند
        $this->say($this->ali, "متن \u{202E}وارونه")->assertCreated()->assertJsonPath('data.body', 'متن وارونه');

        // حالت آهسته
        config(['pedigree.group.slow_mode_seconds' => 5]);
        $this->say($this->sara, 'اول')->assertCreated();
        $this->say($this->sara, 'دوم')->assertStatus(429)->assertJsonPath('code', 'slow_mode');

        // سقف روزانه
        config(['pedigree.group.slow_mode_seconds' => 0, 'pedigree.group.daily_messages' => 3]);
        $this->say($this->admin, 'یک')->assertCreated();
        $this->say($this->admin, 'دو')->assertCreated();
        $this->say($this->admin, 'سه')->assertCreated();
        $this->say($this->admin, 'چهار')->assertStatus(429)->assertJsonPath('code', 'group_limit');
    }

    public function test_memory_photo_is_kept_in_the_senders_profile_with_tags(): void
    {
        $res = $this->actingAs($this->ali, 'sanctum')->post('/api/group/media', [
            'file' => $this->photo(),
            'caption' => 'عروسی عمو حسن، تابستان ۱۳۵۸ 📷',
            'taken_at' => '1358',
            'tags' => [$this->sara->person_id, $this->ali->person_id],
        ], ['Accept' => 'application/json'])->assertCreated();

        $res->assertJsonPath('data.kind', 'media')->assertJsonPath('data.media.type', 'image')
            ->assertJsonPath('data.media.visible', true)->assertJsonCount(2, 'data.media.tags');
        $media = Media::find($res->json('data.media.id'));
        $this->assertSame($this->ali->person_id, $media->person_id);
        $this->assertSame(Media::CATEGORY_MEMORY, $media->category);
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertTrue($media->isApproved());
        $this->assertSame('1358', $media->taken_at);

        // در گالری پروفایل فرستنده هم هست؛ دیگران هم می‌بینند
        $gallery = $this->actingAs($this->sara, 'sanctum')->getJson("/api/persons/{$this->ali->person_id}/media")->assertOk();
        $this->assertContains($media->id, array_column($gallery->json('data'), 'id'));

        // توضیح لازم است و فقط ایموجی کافی نیست؛ فقط عکس و فیلم
        $this->post('/api/group/media', ['file' => $this->photo(), 'caption' => '📷📷'], ['Accept' => 'application/json'])->assertStatus(422);
        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, "%PDF-1.4\n");
        $this->post('/api/group/media', ['file' => new UploadedFile($pdf, 'doc.pdf', 'application/pdf', null, true), 'caption' => 'سند قدیمی'], ['Accept' => 'application/json'])->assertStatus(422);

        // حذف پیام از گروه، عکس را از پروفایل پاک نمی‌کند
        $this->actingAs($this->ali, 'sanctum')->deleteJson('/api/group/messages/'.$res->json('data.id'))->assertOk();
        $this->assertNotNull(Media::find($media->id));
    }

    public function test_reactions_delete_report_pin_and_mute(): void
    {
        $id = $this->say($this->ali, 'این عکس را کسی می‌شناسد؟')->json('data.id');

        // واکنش (فقط از فهرست؛ یکی برای هر نفر)
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/group/messages/{$id}/react", ['emoji' => '❤️'])->assertOk()
            ->assertJsonPath('data.reactions.0', ['emoji' => '❤️', 'count' => 1, 'mine' => true]);
        $this->postJson("/api/group/messages/{$id}/react", ['emoji' => '😂'])->assertJsonPath('data.reactions.0.emoji', '😂')->assertJsonCount(1, 'data.reactions');
        $this->postJson("/api/group/messages/{$id}/react", ['emoji' => '💩'])->assertStatus(422);
        $this->postJson("/api/group/messages/{$id}/react", ['emoji' => null])->assertJsonCount(0, 'data.reactions');

        // تغییرها با since به بقیه می‌رسد
        $since = now()->subSecond()->toIso8601String();
        $this->travel(2)->seconds();
        $this->postJson("/api/group/messages/{$id}/react", ['emoji' => '👍']);
        $this->actingAs($this->ali, 'sanctum')->getJson("/api/group/messages?after={$id}&since=".urlencode($since))
            ->assertJsonCount(0, 'data')->assertJsonPath('updated.0.id', $id);

        // حذف پیام دیگران ممنوع
        $this->actingAs($this->sara, 'sanctum')->deleteJson("/api/group/messages/{$id}")->assertForbidden();
        // سنجاق فقط مدیر
        $this->postJson("/api/admin/group/messages/{$id}/pin", ['pinned' => true])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/group/messages/{$id}/pin", ['pinned' => true])->assertOk();
        $this->actingAs($this->sara, 'sanctum')->getJson('/api/group')->assertJsonPath('data.pinned.0.id', $id);

        // گزارش ← مدیر حذف و سکوت می‌کند
        $bad = $this->say($this->ali, 'حرف ناشایست')->json('data.id');
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/group/messages/{$bad}/report", ['reason' => 'توهین'])->assertOk();
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/group/messages/{$bad}/report")->assertStatus(422); // گزارش پیام خود
        $this->actingAs($this->sara, 'sanctum')->getJson('/api/admin/group/reports')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/group/reports')->assertOk()
            ->assertJsonPath('data.0.message.id', $bad)->assertJsonPath('data.0.reports.0.reason', 'توهین');
        $this->postJson("/api/admin/group/reports/{$bad}/resolve", ['action' => 'mute', 'days' => 7])->assertOk();
        $this->assertNotNull(GroupMessage::find($bad)->deleted_at);
        $this->assertCount(0, $this->getJson('/api/admin/group/reports')->json('data'));

        $this->ali->refresh();
        $this->say($this->ali, 'دوباره سلام')->assertForbidden()->assertJsonPath('code', 'group_blocked');
        $this->actingAs($this->ali, 'sanctum')->getJson('/api/group')->assertJsonPath('data.can_post', false);
        // برداشتن سکوت
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$this->ali->id}/group-mute", ['days' => 0])->assertOk();
        $this->say($this->ali->refresh(), 'دوباره سلام')->assertCreated();
        // مدیر معمولی مدیر دیگر را ساکت نمی‌کند
        $other = User::factory()->withPerson()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/users/{$other->id}/group-mute", ['days' => 3])->assertForbidden();
    }

    public function test_daily_prompt_and_disabled_group(): void
    {
        $this->travelTo(Carbon::parse('2026-09-28 11:00:00', 'Asia/Tehran'));
        $this->artisan('pedigree:group-prompt')->assertSuccessful();
        $this->artisan('pedigree:group-prompt')->assertSuccessful();
        $this->assertSame(1, GroupMessage::where('kind', 'prompt')->count());
        $this->actingAs($this->sara, 'sanctum')->getJson('/api/group/messages')->assertJsonPath('data.0.kind', 'prompt')
            ->assertJsonPath('data.0.user', null);

        config(['pedigree.group.enabled' => false]);
        $this->getJson('/api/group')->assertForbidden();
        $this->say($this->sara, 'سلام')->assertForbidden();
        $this->getJson('/api/auth/me')->assertJsonPath('data.counters.group', 0);

        $this->app['auth']->forgetGuards();
        config(['pedigree.group.enabled' => true]);
        $this->getJson('/api/group/messages')->assertUnauthorized();
    }
}
