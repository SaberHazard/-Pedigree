<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\Media\MediaFormats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * هر قالب عکس به JPEG و هر ظرف ویدیو به MP4؛ فایل‌های غیرتصویری (SVG، PDF، اسکریپت) رد می‌شوند.
 */
class ImageFormatsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->user = User::factory()->withPerson()->create()->refresh();
    }

    private function tool(string $bin): bool
    {
        $p = new Process([$bin, '-version']);
        $p->run();

        return $p->isSuccessful() || str_contains($p->getOutput().$p->getErrorOutput(), 'heif');
    }

    private function upload(string $path, string $name, string $mime = 'application/octet-stream')
    {
        return $this->actingAs($this->user, 'sanctum')->postJson("/api/persons/{$this->user->person_id}/media", [
            'file' => new UploadedFile($path, $name, $mime, null, true),
        ]);
    }

    private function assertJpeg(string $id): Media
    {
        $media = Media::find($id);
        $this->assertSame('image/jpeg', $media->mime);
        $this->assertStringEndsWith('.jpg', $media->path);
        $bytes = Storage::disk('media')->get($media->path);
        $this->assertStringStartsWith("\xFF\xD8\xFF", $bytes);
        foreach ($media->variants as $variant) {
            $this->assertStringStartsWith("\xFF\xD8\xFF", Storage::disk('media')->get($variant));
        }

        return $media;
    }

    public function test_png_with_transparency_becomes_jpeg_on_white(): void
    {
        $img = imagecreatetruecolor(400, 300);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($img, $path);

        $media = $this->assertJpeg($this->upload($path, 'logo.png', 'image/png')->assertCreated()->json('data.id'));
        $out = imagecreatefromstring(Storage::disk('media')->get($media->path));
        $rgb = imagecolorat($out, 10, 10);
        $this->assertGreaterThan(240, ($rgb >> 16) & 0xFF, 'transparent pixels become white, not black');
    }

    public function test_tiff_via_ffmpeg_and_heic_via_libheif(): void
    {
        if (! $this->tool('ffmpeg')) {
            $this->markTestSkipped('ffmpeg is not installed');
        }
        $tiff = tempnam(sys_get_temp_dir(), 'tif').'.tiff';
        (new Process(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'testsrc=size=800x600', '-frames:v', '1', $tiff]))->mustRun();
        $media = $this->assertJpeg($this->upload($tiff, 'scan.tiff', 'image/tiff')->assertCreated()->json('data.id'));
        $this->assertSame([800, 600], [$media->width, $media->height]);

        $heic = tempnam(sys_get_temp_dir(), 'heic').'.heic';
        $enc = new Process(['heif-enc', '-q', '70', '-o', $heic, $this->pngFile(640, 480)]);
        $enc->run();
        if (! $enc->isSuccessful() || ! filesize($heic)) {
            $this->markTestSkipped('libheif (heif-enc) is not installed');
        }
        $this->assertSame('heic', MediaFormats::detectImage($heic));
        $media = $this->assertJpeg($this->upload($heic, 'IMG_0001.HEIC', 'image/heic')->assertCreated()->json('data.id'));
        $this->assertSame([640, 480], [$media->width, $media->height]);
    }

    public function test_non_images_are_rejected_whatever_their_name(): void
    {
        $cases = [
            'photo.jpg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>',
            'photo.png' => "%PDF-1.4\n1 0 obj<<>>endobj",
            'photo.gif' => '<?php system($_GET["c"]); ?>',
            'photo.heic' => "\0\0\0\x18ftypisom\0\0\0\0isomavc1",
        ];
        foreach ($cases as $name => $content) {
            $path = tempnam(sys_get_temp_dir(), 'bad');
            file_put_contents($path, $content);
            $this->upload($path, $name, 'image/jpeg')->assertStatus(422);
        }
        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }

    public function test_daily_upload_cap_and_video_queue_guard(): void
    {
        config(['pedigree.media.daily_uploads_per_user' => 2]);
        foreach ([1, 2] as $i) {
            $this->upload($this->pngFile(100 + $i, 100), "a{$i}.png", 'image/png')->assertCreated();
        }
        $this->upload($this->pngFile(120, 100), 'a3.png', 'image/png')->assertStatus(429)->assertJsonPath('code', 'upload_limit');

        // صف تبدیل ویدیو پر است
        config(['pedigree.media.daily_uploads_per_user' => 100, 'pedigree.media.video.queue_max' => 1]);
        Media::query()->first()->forceFill(['type' => 'video', 'processing' => 'queued'])->save();
        $video = tempnam(sys_get_temp_dir(), 'vid');
        file_put_contents($video, "\0\0\0\x18ftypmp42\0\0\0\0mp42isom".str_repeat("\0", 64));
        $this->upload($video, 'v.mp4', 'video/mp4')->assertStatus(503)->assertJsonPath('code', 'video_queue_full');
    }

    private function pngFile(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, random_int(0, 255), 120, 200));
        $path = tempnam(sys_get_temp_dir(), 'png').'.png';
        imagepng($img, $path);

        return $path;
    }
}
