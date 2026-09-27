<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * فشرده‌سازی واقعی ویدیو با ffmpeg (اگر روی سیستم نصب نباشد، تست رد می‌شود)
 */
class VideoProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function ffmpegAvailable(): bool
    {
        $p = new Process(['ffmpeg', '-version']);
        $p->run();

        return $p->isSuccessful();
    }

    public function test_uploaded_video_is_transcoded_to_mp4_with_poster(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed');
        }
        Storage::fake('media');

        // ساخت یک ویدیوی ۳ ثانیه‌ای 1280x960 با صدا (webm) برای آزمایش
        $source = tempnam(sys_get_temp_dir(), 'vid').'.webm';
        (new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=size=1280x960:rate=25:duration=3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', '-c:v', 'libvpx', '-b:v', '1M', '-c:a', 'libvorbis', $source]))->mustRun();

        $user = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($user, 'sanctum');

        $file = new UploadedFile($source, 'story.webm', 'video/webm', null, true);
        $response = $this->postJson("/api/persons/{$user->person_id}/media", ['file' => $file])->assertCreated();

        $media = Media::find($response->json('data.id'));
        $this->assertSame('video', $media->type);
        $this->assertSame('ready', $media->processing);
        $this->assertSame('video/mp4', $media->mime);
        $this->assertStringEndsWith('.mp4', $media->path);
        $this->assertSame(3, $media->duration);
        $this->assertSame(1280, $media->width);
        Storage::disk('media')->assertExists($media->path);
        Storage::disk('media')->assertExists($media->variants['poster']);
        Storage::disk('media')->assertExists($media->variants['thumb']);

        // ارتفاع خروجی حداکثر ۷۲۰ پیکسل است
        $probe = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=height,codec_name', '-of', 'csv=p=0', Storage::disk('media')->path($media->path)]);
        $probe->mustRun();
        $this->assertSame('h264,720', trim($probe->getOutput()));

        // پخش با Range (جلو/عقب بردن ویدیو)
        $url = $this->getJson("/api/media/{$media->id}")->json('data.urls.original');
        $this->get($url, ['Range' => 'bytes=0-99'])->assertStatus(206)->assertHeader('Content-Type', 'video/mp4');

        @unlink($source);
    }
}
