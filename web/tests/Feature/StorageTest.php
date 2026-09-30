<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * فضای ابری عکس و ویدیو (S3): انتقال امن فایل‌ها، لینک امضاشده موقت، CSP و آزمایش از پنل مدیریت.
 */
class StorageTest extends TestCase
{
    use RefreshDatabase;

    private function media(User $owner, string $disk = 'media'): Media
    {
        $media = new Media;
        $media->forceFill([
            'person_id' => $owner->person_id,
            'uploaded_by' => $owner->id,
            'type' => 'image',
            'disk' => $disk,
            'path' => 'people/x/photo.jpg',
            'variants' => ['thumb' => 'people/x/photo_thumb.jpg', 'medium' => 'people/x/photo_medium.jpg'],
            'mime' => 'image/jpeg',
            'size' => 4,
            'status' => Media::STATUS_APPROVED,
        ])->save();

        return $media;
    }

    public function test_media_move_copies_verifies_and_switches_disk(): void
    {
        Storage::fake('media');
        Storage::fake('s3');
        $owner = User::factory()->withPerson()->create()->refresh();
        $media = $this->media($owner);
        foreach (['people/x/photo.jpg' => 'main', 'people/x/photo_thumb.jpg' => 'th', 'people/x/photo_medium.jpg' => 'med'] as $path => $body) {
            Storage::disk('media')->put($path, $body);
        }

        $this->artisan('pedigree:media-move', ['--to' => 's3'])->assertSuccessful();
        $this->assertSame('s3', $media->fresh()->disk);
        Storage::disk('s3')->assertExists(['people/x/photo.jpg', 'people/x/photo_thumb.jpg', 'people/x/photo_medium.jpg']);
        $this->assertSame('th', Storage::disk('s3')->get('people/x/photo_thumb.jpg'));
        Storage::disk('media')->assertMissing('people/x/photo.jpg');

        // برگرداندن با نگه داشتن نسخه ابری
        $this->artisan('pedigree:media-move', ['--to' => 'media', '--keep' => true])->assertSuccessful();
        $this->assertSame('media', $media->fresh()->disk);
        Storage::disk('media')->assertExists('people/x/photo_medium.jpg');
        Storage::disk('s3')->assertExists('people/x/photo_medium.jpg');

        $this->artisan('pedigree:media-move', ['--to' => 'ftp'])->assertFailed();
    }

    public function test_cloud_media_is_served_by_short_signed_redirect(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path, $expires) => 'https://pedigree.s3.ir-thr-at1.arvanstorage.ir/'.$path.'?X-Amz-Expires='.($expires->getTimestamp() - now()->getTimestamp()));
        $owner = User::factory()->withPerson()->create()->refresh();
        $media = $this->media($owner, 's3');
        Storage::disk('s3')->put('people/x/photo_thumb.jpg', 'th');

        $url = URL::temporarySignedRoute('media.file', now()->addMinutes(10), ['media' => $media->id, 'variant' => 'thumb'], false);
        $res = $this->get($url)->assertRedirect();
        $this->assertStringStartsWith('https://pedigree.s3.ir-thr-at1.arvanstorage.ir/people/x/photo_thumb.jpg', $res->headers->get('Location'));
        $this->assertStringContainsString('X-Amz-Expires=1800', $res->headers->get('Location'));
        // بدون امضای سایت: هرگز
        $this->get("/m/{$media->id}/thumb")->assertForbidden();
    }

    public function test_csp_allows_only_the_configured_storage_host(): void
    {
        $csp = fn () => (string) $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('arvanstorage', $csp());

        config(['filesystems.disks.s3.bucket' => 'pedigree', 'filesystems.disks.s3.endpoint' => 'https://s3.ir-thr-at1.arvanstorage.ir', 'filesystems.disks.s3.use_path_style_endpoint' => false]);
        $policy = $csp();
        $this->assertStringContainsString("img-src 'self' data: blob:", $policy);
        $this->assertStringContainsString('https://pedigree.s3.ir-thr-at1.arvanstorage.ir', $policy);
        preg_match('/media-src ([^;]+)/', $policy, $m);
        $this->assertStringContainsString('https://s3.ir-thr-at1.arvanstorage.ir', $m[1]);

        config(['filesystems.disks.s3.endpoint' => 'https://storage.c2.liara.space', 'filesystems.disks.s3.use_path_style_endpoint' => true]);
        $policy = $csp();
        $this->assertStringContainsString('https://storage.c2.liara.space', $policy);
        $this->assertStringNotContainsString('pedigree.storage', $policy);

        // مقدار دست‌کاری‌شده هرگز وارد CSP نمی‌شود
        config(['filesystems.disks.s3.endpoint' => 'https://evil.com; script-src *']);
        $this->assertStringNotContainsString('evil.com', $csp());
        config(['filesystems.disks.s3.endpoint' => 'https://ok.example', 'filesystems.disks.s3.bucket' => 'a b;script-src *']);
        $this->assertStringNotContainsString('ok.example', $csp());
    }

    public function test_admin_storage_check(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path) => 'https://bucket.example/'.$path.'?sig=1');
        $admin = User::factory()->withPerson()->admin()->create()->refresh();

        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'storage'])->assertStatus(422);
        config(['filesystems.disks.s3.bucket' => 'pedigree', 'filesystems.disks.s3.key' => 'AKIA-TEST']);
        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/settings/test', ['action' => 'storage'])->assertOk();
        $this->assertStringContainsString('درست کار می‌کند', $res->json('message'));
        $this->assertSame([], Storage::disk('s3')->allFiles());

        $group = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/settings')->json('data'))->firstWhere('key', 'storage');
        $this->assertNotNull($group);
        $secret = collect($group['fields'])->firstWhere('key', 'filesystems.disks.s3.secret');
        $this->assertArrayNotHasKey('value', $secret);
    }
}
