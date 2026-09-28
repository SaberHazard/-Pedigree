<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideo;
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
        // 1280×960 → ضلع کوچک‌تر ۷۲۰
        $this->assertSame(960, $media->width);
        $this->assertSame(720, $media->height);
        Storage::disk('media')->assertExists($media->path);
        Storage::disk('media')->assertExists($media->variants['poster']);
        Storage::disk('media')->assertExists($media->variants['thumb']);

        // ضلع کوچک‌تر خروجی حداکثر ۷۲۰ پیکسل است
        $probe = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=height,codec_name', '-of', 'csv=p=0', Storage::disk('media')->path($media->path)]);
        $probe->mustRun();
        $this->assertSame('h264,720', trim($probe->getOutput()));

        // پخش با Range (جلو/عقب بردن ویدیو)
        $url = $this->getJson("/api/media/{$media->id}")->json('data.urls.original');
        $this->get($url, ['Range' => 'bytes=0-99'])->assertStatus(206)->assertHeader('Content-Type', 'video/mp4');

        @unlink($source);
    }

    /** ویدیوی عمودی ۶۰ فریم گوشی با موقعیت مکانی در متادیتا */
    public function test_portrait_phone_video_like_telegram(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed');
        }
        Storage::fake('media');

        $source = tempnam(sys_get_temp_dir(), 'vid').'.mp4';
        (new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1080x1920:rate=60:duration=3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', '-c:v', 'libx264', '-preset', 'ultrafast', '-crf', '12',
            '-c:a', 'aac', '-b:a', '256k', '-metadata', 'location=+35.6892+051.3890/', '-metadata', 'title=secret-trip', $source]))->mustRun();
        $sourceSize = filesize($source);

        $user = User::factory()->withPerson()->create()->refresh();
        $file = new UploadedFile($source, 'IMG_0001.MOV.mp4', 'video/mp4', null, true);
        $id = $this->actingAs($user, 'sanctum')->postJson("/api/persons/{$user->person_id}/media", ['file' => $file])->assertCreated()->json('data.id');

        $media = Media::find($id);
        $this->assertSame('ready', $media->processing);
        $this->assertSame([720, 1280], [$media->width, $media->height], 'portrait keeps 720p on the short side');
        $out = Storage::disk('media')->path($media->path);
        $this->assertLessThan($sourceSize / 3, filesize($out), 'much smaller than the phone file');

        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name,avg_frame_rate,channels:format_tags', '-of', 'json', $out]);
        $probe->mustRun();
        $info = json_decode($probe->getOutput(), true);
        $this->assertSame('h264', $info['streams'][0]['codec_name']);
        $this->assertSame('30/1', $info['streams'][0]['avg_frame_rate']);
        $this->assertSame('aac', $info['streams'][1]['codec_name']);
        $tags = json_encode($info['format']['tags'] ?? []);
        $this->assertStringNotContainsString('35.6892', $tags);
        $this->assertStringNotContainsString('secret-trip', $tags);

        // فقط یک ویدیو (فایل اصلی آپلودشده پاک شده) + پوستر و بندانگشتی
        $videos = array_filter(Storage::disk('media')->allFiles(), fn ($f) => ! str_ends_with($f, '.jpg'));
        $this->assertSame([$media->path], array_values($videos));

        @unlink($source);
    }

    /** ویدیویی که از قبل کم‌حجم است با تبدیل دوباره بزرگ‌تر نمی‌شود */
    public function test_already_small_video_never_grows(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not installed');
        }
        Storage::fake('media');

        $source = tempnam(sys_get_temp_dir(), 'vid').'.mp4';
        (new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=25:duration=3',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3', '-c:v', 'libx264', '-preset', 'veryslow', '-crf', '38',
            '-c:a', 'aac', '-b:a', '32k', '-metadata', 'location=+35.6892+051.3890/', $source]))->mustRun();
        $sourceSize = filesize($source);

        $user = User::factory()->withPerson()->create()->refresh();
        $file = new UploadedFile($source, 'small.mp4', 'video/mp4', null, true);
        $id = $this->actingAs($user, 'sanctum')->postJson("/api/persons/{$user->person_id}/media", ['file' => $file])->assertCreated()->json('data.id');

        $media = Media::find($id);
        $out = Storage::disk('media')->path($media->path);
        $this->assertLessThanOrEqual($sourceSize, filesize($out));
        $this->assertSame([640, 360], [$media->width, $media->height]);
        $probe = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format_tags', '-of', 'json', $out]);
        $probe->mustRun();
        $this->assertStringNotContainsString('35.6892', $probe->getOutput());

        @unlink($source);
    }

    public function test_probe_parsing_and_encode_command(): void
    {
        $probe = ProcessVideo::parseProbe([
            'streams' => [
                ['codec_type' => 'audio', 'codec_name' => 'aac'],
                ['codec_type' => 'video', 'codec_name' => 'hevc', 'width' => 1920, 'height' => 1080, 'avg_frame_rate' => '60000/1001'],
            ],
            'format' => ['duration' => '12.6', 'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
        ]);
        $this->assertSame(['duration' => 13, 'width' => 1920, 'height' => 1080, 'fps' => 59.94, 'vcodec' => 'hevc', 'acodec' => 'aac', 'format' => 'mov,mp4,m4a,3gp,3g2,mj2'], $probe);

        $config = ['ffmpeg' => 'ffmpeg', 'max_height' => 720, 'crf' => 99, 'max_duration' => 60, 'threads' => 2];
        $cmd = implode(' ', ProcessVideo::encodeCommand($config, $probe, 'in', 'out'));
        $this->assertStringContainsString('fps=30', $cmd);
        $this->assertStringContainsString('-crf 35', $cmd, 'crf is clamped');
        $this->assertStringContainsString('-maxrate 2200k', $cmd);
        $this->assertStringContainsString('-protocol_whitelist file', $cmd);
        $this->assertFalse(ProcessVideo::alreadyEfficient($config, $probe));
    }
}
