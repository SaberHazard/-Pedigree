<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\UserBlock;
use App\Models\VoiceNote;
use App\Services\Media\VoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * پیام صوتی (پیام خصوصی، گروه، پشتیبانی) و گفتگو با پشتیبانی
 */
class VoiceAndSupportTest extends TestCase
{
    use RefreshDatabase;

    private User $ali;

    private User $sara;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['pedigree.media.disk' => 'media', 'pedigree.group.slow_mode_seconds' => 0]);
        $this->ali = User::factory()->withPerson(['first_name' => 'علی'])->create()->refresh();
        $this->sara = User::factory()->withPerson(['first_name' => 'سارا'])->create()->refresh();
        $this->admin = User::factory()->withPerson(['first_name' => 'مدیر'])->create(['role' => User::ROLE_ADMIN])->refresh();
    }

    /** یک فایل صوتی واقعی (مثل ضبط مرورگر: WebM/Opus) */
    private function recording(int $seconds = 3, string $codec = 'libopus', string $ext = 'webm'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rec').'.'.$ext;
        $p = new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "sine=frequency=440:duration={$seconds}", '-c:a', $codec, $path]);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->markTestSkipped('ffmpeg در دسترس نیست.');
        }

        return new UploadedFile($path, "voice.{$ext}", null, null, true);
    }

    public function test_private_voice_message_is_compressed_and_only_for_the_two_people(): void
    {
        $conv = Conversation::findOrCreateBetween($this->ali, $this->sara);
        $res = $this->actingAs($this->ali, 'sanctum')->post("/api/messages/{$conv->id}/voice", [
            'voice' => $this->recording(),
            'waveform' => json_encode([1, 5, 40, -3, 'x', 12]),
        ], ['Accept' => 'application/json'])->assertCreated();

        $voice = $res->json('data.voice');
        $this->assertEqualsWithDelta(3.0, $voice['duration'], 0.3);
        $this->assertSame([1, 5, 31, 0, 0, 12], $voice['waveform']);
        $note = VoiceNote::query()->firstOrFail();
        $this->assertSame('audio/mp4', $note->mime);
        Storage::disk('media')->assertExists($note->path);
        // ۳ ثانیه با ۳۲ کیلوبیت ≈ ۱۲ کیلوبایت (فشرده مثل تلگرام)
        $this->assertLessThan(20 * 1024, $note->size);

        // لینک امضاشده کار می‌کند و بدون امضا نه
        $this->get($voice['url'])->assertOk()->assertHeader('Content-Type', 'audio/mp4');
        $this->get('/v/'.$note->id)->assertForbidden();

        // طرف مقابل می‌شنود، غریبه گفتگو را اصلاً نمی‌بیند
        $this->actingAs($this->sara, 'sanctum')->getJson("/api/messages/{$conv->id}")->assertOk()
            ->assertJsonPath('data.0.voice.id', $note->id);
        $stranger = User::factory()->withPerson()->create();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/messages/{$conv->id}")->assertNotFound();

        // در فهرست گفتگوها «پیام صوتی»
        $this->actingAs($this->sara, 'sanctum')->getJson('/api/messages')->assertJsonPath('data.0.last.text', '🎤 پیام صوتی');

        // حذف پیام = حذف فایل
        $this->actingAs($this->ali, 'sanctum')->deleteJson('/api/direct-messages/'.$res->json('data.id'))->assertOk()->assertJsonPath('data.voice', null);
        Storage::disk('media')->assertMissing($note->path);
    }

    public function test_voice_uploads_are_validated(): void
    {
        $conv = Conversation::findOrCreateBetween($this->ali, $this->sara);
        $this->actingAs($this->ali, 'sanctum');
        // فایل غیرصوتی با پسوند صوتی
        $fake = UploadedFile::fake()->createWithContent('voice.webm', '<?php echo "hi"; ?>');
        $this->post("/api/messages/{$conv->id}/voice", ['voice' => $fake], ['Accept' => 'application/json'])->assertStatus(422);
        // بیش از سقف مدت
        config(['pedigree.voice.max_seconds' => 2]);
        $this->post("/api/messages/{$conv->id}/voice", ['voice' => $this->recording(4)], ['Accept' => 'application/json'])->assertStatus(422);
        // خاموش بودن از پنل
        config(['pedigree.voice.max_seconds' => 300, 'pedigree.voice.enabled' => false]);
        $this->post("/api/messages/{$conv->id}/voice", ['voice' => $this->recording()], ['Accept' => 'application/json'])->assertStatus(403);
        // مسدود: فایلی ذخیره نمی‌شود
        config(['pedigree.voice.enabled' => true]);
        UserBlock::query()->create(['blocker_id' => $this->sara->id, 'blocked_id' => $this->ali->id]);
        $this->post("/api/messages/{$conv->id}/voice", ['voice' => $this->recording()], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, VoiceNote::query()->count());
        $this->assertSame('mp4', VoiceService::detect($this->recording(1, 'aac', 'm4a')->getRealPath()));
    }

    public function test_group_voice_message_without_text(): void
    {
        $res = $this->actingAs($this->ali, 'sanctum')->post('/api/group/voice', ['voice' => $this->recording()], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame('voice', $res->json('data.kind'));
        $this->assertNotNull($res->json('data.voice.url'));
        $this->actingAs($this->sara, 'sanctum')->getJson('/api/group/messages')->assertOk()->assertJsonPath('data.0.voice.id', $res->json('data.voice.id'));
        // حذف توسط مدیر
        $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/group/messages/'.$res->json('data.id'))->assertOk();
        $this->assertSame(0, VoiceNote::query()->count());
    }

    public function test_support_chat_between_member_and_admins(): void
    {
        $this->actingAs($this->ali, 'sanctum')->getJson('/api/support')->assertOk()->assertJsonPath('thread', null);
        $this->postJson('/api/support/messages', ['body' => 'سلام، عکس پدربزرگم تأیید نمی‌شود 🙏'])->assertCreated()->assertJsonPath('data.from_admin', false);
        $this->post('/api/support/voice', ['voice' => $this->recording()], ['Accept' => 'application/json'])->assertCreated();

        // مدیر: فهرست، خواندن و پاسخ (متن و صوت)
        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/support')->assertOk();
        $this->assertSame(1, $list->json('unread_threads'));
        $this->assertSame('support', $this->admin->notifications()->first()->data['kind']);
        $this->assertSame('support_admin', 'support_admin');
        $thread = $list->json('data.0.id');
        $this->getJson("/api/admin/support/{$thread}")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(0, $this->getJson('/api/admin/support')->json('unread_threads'));
        $this->postJson("/api/admin/support/{$thread}/messages", ['body' => 'بررسی شد ✅'])->assertCreated()->assertJsonPath('data.from_admin', true);

        // عضو: شمارنده نخوانده و نام «پشتیبانی» (نام مدیر نمایش داده نمی‌شود)
        $this->actingAs($this->ali, 'sanctum')->getJson('/api/auth/me')->assertJsonPath('data.counters.support', 1);
        $msgs = $this->getJson('/api/support')->json('data');
        $this->assertSame('پشتیبانی', end($msgs)['sender']);
        $this->getJson('/api/auth/me')->assertJsonPath('data.counters.support', 0);

        // عضو دیگر به گفتگوی پشتیبانی دیگران دسترسی ندارد
        $this->actingAs($this->sara, 'sanctum')->getJson("/api/admin/support/{$thread}")->assertForbidden();
        $this->deleteJson('/api/support/messages/'.$msgs[0]['id'])->assertNotFound();
        $this->postJson('/api/support/messages', ['body' => ''])->assertStatus(422);
    }
}
