<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideo;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * تست‌های سخت‌سازی امنیتی: بمب فشرده‌سازی تصویر، ورودی مخرب ffmpeg، سقف پیامک،
 * سوءاستفاده از توکن ثبت‌نام و مسابقه (race) در تلاش‌های کد پیامکی.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** یک PNG کوچک که ادعا می‌کند ۴۰۰۰۰×۴۰۰۰۰ پیکسل است */
    private function pngBomb(): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $ihdr = pack('NNCCCCC', 40000, 40000, 8, 2, 0, 0, 0);
        $path = tempnam(sys_get_temp_dir(), 'bomb').'.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n".$chunk('IHDR', $ihdr).$chunk('IDAT', gzcompress(str_repeat("\0", 1000))).$chunk('IEND', ''));

        return $path;
    }

    public function test_decompression_bomb_is_rejected_before_decoding(): void
    {
        Storage::fake('media');
        config(['pedigree.media.disk' => 'media']);
        $user = User::factory()->withPerson()->create()->refresh();
        $bomb = new UploadedFile($this->pngBomb(), 'bomb.png', 'image/png', null, true);

        $this->actingAs($user, 'sanctum')->post("/api/persons/{$user->person_id}/media", ['file' => $bomb], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'مگاپیکسل'));
    }

    public function test_ffmpeg_refuses_playlists_and_network_protocols(): void
    {
        $ffmpeg = config('pedigree.media.video.ffmpeg');
        if ((new Process([$ffmpeg, '-version']))->run() !== 0) {
            $this->markTestSkipped('ffmpeg not installed');
        }
        // فهرست پخش HLS که می‌خواهد فایل محلی سرور را بخواند
        $dir = sys_get_temp_dir().'/hls'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/secret.txt", 'TOP SECRET');
        file_put_contents("{$dir}/evil.mp4", "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:1.0,\nfile://{$dir}/secret.txt\n#EXT-X-ENDLIST\n");

        $process = new Process([$ffmpeg, '-nostdin', ...ProcessVideo::inputGuard(), '-i', "{$dir}/evil.mp4", '-f', 'null', '-']);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertMatchesRegularExpression('/whitelist|Invalid data/i', $process->getErrorOutput());
    }

    public function test_global_daily_sms_cap(): void
    {
        config(['pedigree.otp.global_daily_limit' => 1]);
        Person::factory()->create()->forceFill(['phone' => '09120000001'])->save();
        Person::factory()->create()->forceFill(['phone' => '09120000002'])->save();

        $this->postJson('/api/auth/otp', ['phone' => '09120000001'])->assertOk();
        $this->postJson('/api/auth/otp', ['phone' => '09120000002'])->assertStatus(503)->assertJsonPath('code', 'otp_global_limit');
    }

    public function test_registration_token_cannot_be_used_to_probe_national_codes(): void
    {
        foreach (['0012345679', '0023456787', '0034567895'] as $code) {
            Person::factory()->create()->forceFill(['national_code' => $code])->save();
        }
        $otp = $this->postJson('/api/auth/otp', ['phone' => '09350000009'])->json('debug_code');
        $token = $this->postJson('/api/auth/otp/verify', ['phone' => '09350000009', 'code' => $otp])->json('registration_token');
        $base = ['registration_token' => $token, 'first_name' => 'x', 'last_name' => 'y', 'gender' => 'm'];

        $this->postJson('/api/auth/register', $base + ['national_code' => '0012345679'])->assertJsonValidationErrors('national_code');
        $this->postJson('/api/auth/register', $base + ['national_code' => '0023456787'])->assertJsonValidationErrors('national_code');
        $this->postJson('/api/auth/register', $base + ['national_code' => '0034567895'])->assertJsonValidationErrors('national_code');
        // پس از سه برخورد، توکن باطل شده است
        $this->postJson('/api/auth/register', $base + ['no_national_code' => true])->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'مهلت ثبت‌نام'));
    }

    public function test_otp_attempts_are_capped_even_for_the_right_code(): void
    {
        Person::factory()->create()->forceFill(['phone' => '09120000003'])->save();
        $code = $this->postJson('/api/auth/otp', ['phone' => '09120000003'])->json('debug_code');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/otp/verify', ['phone' => '09120000003', 'code' => '000000'])->assertStatus(422);
            $this->app['cache']->store()->flush(); // دور زدن محدودیت نرخ IP در تست (حمله از چند IP)
        }
        $this->postJson('/api/auth/otp/verify', ['phone' => '09120000003', 'code' => $code])->assertStatus(422);
    }
}
