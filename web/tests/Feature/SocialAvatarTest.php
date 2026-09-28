<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\Social\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * عکس پروفایل شبکه‌های اجتماعی: پیش‌نمایش، دریافت امن (بدون SSRF)، اولویت اینستاگرام ← واتس‌اپ ← تلگرام.
 */
class SocialAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $brother;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        config(['pedigree.social.fetch_enabled' => true, 'pedigree.social.proxy' => null]);
        SafeHttp::fakeResolver(fn (string $host) => ['149.154.167.99']);

        $father = User::factory()->withPerson(['gender' => 'm'])->create()->refresh();
        $this->me = User::factory()->withPerson(['gender' => 'm'])->create()->refresh();
        $this->brother = User::factory()->withPerson(['gender' => 'm'])->create()->refresh();
        $this->me->person->forceFill(['father_id' => $father->person_id])->save();
        $this->brother->person->forceFill(['father_id' => $father->person_id])->save();
    }

    private static function jpeg(int $r, int $g = 60, int $b = 90): string
    {
        $im = imagecreatetruecolor(320, 320);
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    private function fakeNetworks(array $extra = []): void
    {
        Http::fake($extra + [
            't.me/*' => Http::response('<html><head><meta property="og:title" content="Ali Rezaei"><meta property="og:image" content="https://cdn4.telesco.pe/file/tg.jpg"></head></html>', 200, ['Content-Type' => 'text/html']),
            'cdn4.telesco.pe/*' => Http::response(self::jpeg(10), 200, ['Content-Type' => 'image/jpeg']),
            'www.instagram.com/*' => Http::response('<meta content="https://scontent.cdninstagram.com/v/ig.jpg?x=1&amp;y=2" property="og:image"><meta property="og:title" content="Ali Rezaei (@ali.r) • Instagram photos and videos">', 200, ['Content-Type' => 'text/html']),
            'scontent.cdninstagram.com/*' => Http::response(self::jpeg(200), 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_preview_shows_name_photo_and_direct_link(): void
    {
        $this->fakeNetworks();
        $res = $this->actingAs($this->me, 'sanctum')->getJson('/api/social/preview?network=telegram&value='.urlencode('https://t.me/ali_rezaei'))
            ->assertOk()
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.value', 'ali_rezaei')
            ->assertJsonPath('data.url', 'https://t.me/ali_rezaei')
            ->assertJsonPath('data.name', 'Ali Rezaei');
        $this->assertStringStartsWith('data:image/jpeg;base64,', $res->json('data.image'));

        $this->getJson('/api/social/preview?network=instagram&value=@ali.r')->assertOk()
            ->assertJsonPath('data.name', 'Ali Rezaei')->assertJsonPath('data.url', 'https://www.instagram.com/ali.r/');

        // واتس‌اپ: فقط لینک مستقیم، بدون تماس با سرور
        $this->getJson('/api/social/preview?network=whatsapp&value=09121234567')->assertOk()
            ->assertJsonPath('data.url', 'https://wa.me/989121234567')->assertJsonPath('data.fetchable', false);

        $this->getJson('/api/social/preview?network=instagram&value='.urlencode('https://evil.example/x'))->assertStatus(422);
        $this->getJson('/api/social/preview?network=myspace&value=x')->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/social/preview?network=telegram&value=ali_rezaei')->assertUnauthorized();
    }

    public function test_fetcher_refuses_internal_addresses_and_foreign_hosts(): void
    {
        // og:image به سرور متادیتای ابری یا دامنه شبیه‌سازی‌شده اشاره می‌کند
        Http::fake([
            't.me/evil_one' => Http::response('<meta property="og:image" content="https://169.254.169.254/latest/meta-data">', 200),
            't.me/evil_two' => Http::response('<meta property="og:image" content="https://cdn4.telesco.pe.attacker.com/x.jpg">', 200),
            't.me/evil_three' => Http::response('', 302, ['Location' => 'https://internal.example/admin']),
            '*' => Http::response('should not be called', 500),
        ]);
        $this->actingAs($this->me, 'sanctum');
        foreach (['evil_one', 'evil_two'] as $handle) {
            $this->getJson("/api/social/preview?network=telegram&value={$handle}")->assertOk()->assertJsonPath('data.image', null);
        }
        $this->getJson('/api/social/preview?network=telegram&value=evil_three')->assertOk()
            ->assertJsonPath('data.image', null)->assertJsonPath('data.error', 'دامنه مجاز نیست.');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254') || str_contains($request->url(), 'attacker') || str_contains($request->url(), 'internal.example'));

        // DNS به IP داخلی می‌رسد (DNS rebinding)
        SafeHttp::fakeResolver(fn () => ['10.0.0.5']);
        $this->getJson('/api/social/preview?network=telegram&value=rebind_me')->assertOk()->assertJsonPath('data.error', 'آدرس مجاز نیست.');
        $this->assertFalse(SafeHttp::isPublicIp('127.0.0.1'));
        $this->assertFalse(SafeHttp::isPublicIp('::ffff:127.0.0.1'));
        $this->assertFalse(SafeHttp::isPublicIp('100.64.1.1'));
        $this->assertFalse(SafeHttp::isPublicIp('fd00::1'));
        $this->assertTrue(SafeHttp::isPublicIp('149.154.167.99'));
    }

    public function test_social_photo_becomes_avatar_by_priority(): void
    {
        $this->fakeNetworks();
        $id = $this->me->person_id;

        // خودم تلگرام را ثبت می‌کنم ← عکس تلگرام خودکار دریافت و عکس پروفایل می‌شود
        $this->actingAs($this->me, 'sanctum')->patchJson("/api/persons/{$id}", ['social' => ['telegram' => 'ali_rezaei']])->assertOk();
        $person = $this->me->person->fresh();
        $this->assertNotNull($person->avatar_media_id);
        $this->assertSame('social:telegram', $person->avatar_source);
        $telegramMedia = $person->avatar_media_id;
        $this->assertSame(Media::CATEGORY_SOCIAL, Media::find($telegramMedia)->category);

        // اینستاگرام اولویت بالاتری دارد
        $this->patchJson("/api/persons/{$id}", ['social' => ['telegram' => 'ali_rezaei', 'instagram' => 'ali.r']])->assertOk();
        $person->refresh();
        $this->assertSame('social:instagram', $person->avatar_source);
        $instagramMedia = $person->avatar_media_id;

        // عکس واتس‌اپ (دستی) از تلگرام مقدم است ولی از اینستاگرام نه
        $this->patchJson("/api/persons/{$id}", ['social' => ['telegram' => 'ali_rezaei', 'instagram' => 'ali.r', 'whatsapp' => '09121234567']])->assertOk();
        $file = UploadedFile::fake()->createWithContent('wa.jpg', self::jpeg(120));
        $this->post("/api/persons/{$id}/social-avatar", ['network' => 'whatsapp', 'file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame($instagramMedia, $person->fresh()->avatar_media_id);

        // حذف عکس اینستاگرام ← واتس‌اپ جایگزین می‌شود
        $this->deleteJson("/api/media/{$instagramMedia}")->assertOk();
        $this->assertSame('social:whatsapp', $person->fresh()->avatar_source);

        // پروفایل با لینک مستقیم و عکس هر شبکه
        $profiles = collect($this->getJson("/api/persons/{$id}")->json('data.social_profiles'))->keyBy('network');
        $this->assertSame('https://wa.me/989121234567', $profiles['whatsapp']['url']);
        $this->assertNotNull($profiles['whatsapp']['photo']);
        $this->assertNotNull($profiles['telegram']['photo']);

        // عکسی که در سایت آپلود شود همیشه مقدم است و شبکه‌ها جایش را نمی‌گیرند
        $upload = UploadedFile::fake()->createWithContent('me.jpg', self::jpeg(33, 200, 10));
        $this->post("/api/persons/{$id}/avatar", ['file' => $upload], ['Accept' => 'application/json'])->assertCreated();
        $person->refresh();
        $this->assertNull($person->avatar_source);
        $real = $person->avatar_media_id;
        Http::fake(['cdninstagram.com/*' => Http::response(self::jpeg(250), 200, ['Content-Type' => 'image/jpeg'])]);
        $this->postJson("/api/persons/{$id}/social-avatar", ['network' => 'instagram'])->assertCreated();
        $this->assertSame($real, $person->fresh()->avatar_media_id);
    }

    public function test_photo_fetched_by_relative_needs_owner_approval(): void
    {
        $this->fakeNetworks();
        $id = $this->me->person_id;
        $this->me->forceFill(['last_login_at' => now()])->save();

        $this->actingAs($this->brother, 'sanctum')->patchJson("/api/persons/{$id}", ['social' => ['telegram' => 'ali_rezaei']])->assertOk();
        $media = Media::where('person_id', $id)->where('category', Media::CATEGORY_SOCIAL)->first();
        $this->assertNotNull($media);
        $this->assertSame(Media::STATUS_PENDING, $media->status);
        $this->assertNull($this->me->person->fresh()->avatar_media_id);

        // صاحب پروفایل تأیید می‌کند ← عکس پروفایل می‌شود
        $this->actingAs($this->me, 'sanctum')->postJson("/api/media/{$media->id}/vote", ['decision' => 'approve'])->assertOk();
        $this->assertSame($media->id, $this->me->person->fresh()->avatar_media_id);
    }
}
