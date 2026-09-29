<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\Ai\VoiceAi;
use App\Services\Social\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * صدا و هوش مصنوعی: پیام صوتی فارسی به دستیار، خواندن پاسخ، کلید کوتاه‌عمر تماس زنده، مسابقه خاندان
 */
class AiVoiceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        SafeHttp::fakeResolver(fn () => ['142.250.185.10']);
        config([
            'pedigree.ai.enabled' => true,
            'pedigree.ai.provider' => 'gemini',
            'pedigree.ai.proxy' => null,
            'pedigree.social.proxy' => null,
            'pedigree.ai.providers.gemini' => ['api_key' => 'GEMINI-SECRET', 'model' => 'gemini-flash-latest'],
            'pedigree.ai.providers.groq' => ['api_key' => 'GROQ-SECRET', 'model' => 'llama-3.3-70b-versatile'],
            'pedigree.ai.providers.openai' => ['api_key' => 'OPENAI-SECRET', 'model' => 'gpt-4.1-mini'],
        ]);
        $grandpa = Person::factory()->create(['first_name' => 'حاج علی', 'last_name' => 'احمدی', 'gender' => 'm', 'birth_date' => '1310-01-01', 'birth_place' => 'شیراز', 'occupation' => 'فرش‌فروش']);
        $grandpa->forceFill(['phone' => '09120000001', 'national_code' => '0012345679', 'address' => 'خیابان محرمانه'])->save();
        $father = Person::factory()->create(['first_name' => 'حسن', 'last_name' => 'احمدی', 'gender' => 'm', 'father_id' => $grandpa->id]);
        $this->user = User::factory()->withPerson(['first_name' => 'کیوان', 'last_name' => 'احمدی'])->create()->refresh();
        $this->user->person->forceFill(['father_id' => $father->id])->save();
    }

    private function recording(int $seconds = 2): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rec').'.webm';
        $p = new Process(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "sine=frequency=300:duration={$seconds}", '-c:a', 'libopus', $path]);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->markTestSkipped('ffmpeg در دسترس نیست.');
        }

        return new UploadedFile($path, 'voice.webm', null, null, true);
    }

    public function test_voice_message_to_assistant_is_transcribed_with_groq_whisper(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['text' => ' سلام دستیار، یک مشاعره بکنیم؟ '])]);
        $info = $this->actingAs($this->user, 'sanctum')->getJson('/api/assistant')->json('data.voice');
        $this->assertTrue($info['stt']);
        $this->assertSame('browser', $info['tts']);
        $this->assertNull($info['live']);

        $this->post('/api/assistant/transcribe', ['voice' => $this->recording()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.text', 'سلام دستیار، یک مشاعره بکنیم؟')->assertJsonPath('data.remaining', 39);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.groq.com/openai/v1/audio/transcriptions'
            && $r->hasHeader('Authorization', 'Bearer GROQ-SECRET')
            && $r->isMultipart()
            && collect($r->data())->contains(fn ($part) => ($part['name'] ?? null) === 'language' && $part['contents'] === 'fa'));

        // فایل غیرصوتی
        $this->post('/api/assistant/transcribe', ['voice' => UploadedFile::fake()->createWithContent('v.webm', 'not audio')], ['Accept' => 'application/json'])->assertStatus(422);
        // خاموش
        config(['pedigree.ai.voice.stt' => 'off']);
        $this->post('/api/assistant/transcribe', ['voice' => $this->recording()], ['Accept' => 'application/json'])->assertStatus(503);
    }

    public function test_server_side_speech_with_gemini_tts(): void
    {
        $pcm = str_repeat("\x00\x10\x00\xF0", 24000); // نیم ثانیه PCM
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode($pcm)]]]]]]])]);
        $this->actingAs($this->user, 'sanctum');
        // پیش‌فرض: صدای خود گوشی (سرور صدا نمی‌سازد)
        $this->postJson('/api/assistant/speak', ['text' => 'سلام'])->assertStatus(422)->assertJsonPath('code', 'tts_browser');

        config(['pedigree.ai.voice.tts' => 'gemini']);
        $res = $this->post('/api/assistant/speak', ['text' => '**سلام** خوش آمدید'], ['Accept' => 'application/json'])->assertOk();
        $this->assertContains($res->headers->get('Content-Type'), ['audio/mp4', 'audio/wav']);
        $this->assertGreaterThan(100, strlen($res->getContent()));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'gemini-2.5-flash-preview-tts:generateContent')
            && $r->data()['generationConfig']['responseModalities'] === ['AUDIO']
            && ! str_contains(json_encode($r->data(), JSON_UNESCAPED_UNICODE), '**'));
        $this->assertSame('RIFF', substr(VoiceAi::wav('ab', 24000), 0, 4));
    }

    public function test_live_call_gets_a_short_lived_single_use_token_and_never_the_real_key(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/v1alpha/auth_tokens' => Http::response(['name' => 'auth_tokens/EPHEMERAL123']),
            'api.openai.com/v1/realtime/client_secrets' => Http::response(['value' => 'ek_EPHEMERAL', 'expires_at' => time() + 60]),
        ]);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/assistant/live')->assertStatus(503);

        config(['pedigree.ai.live.provider' => 'gemini', 'pedigree.ai.live.max_minutes' => 5, 'pedigree.ai.live.daily_per_user' => 2]);
        $res = $this->postJson('/api/assistant/live', ['mode' => 'family_quiz'])->assertOk();
        $this->assertSame('gemini', $res->json('data.provider'));
        $this->assertSame('auth_tokens/EPHEMERAL123', $res->json('data.token'));
        $this->assertSame(300, $res->json('data.max_seconds'));
        $this->assertStringNotContainsString('GEMINI-SECRET', $res->getContent());
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'auth_tokens')) {
                return false;
            }
            $d = $r->data();
            $prompt = $d['bidiGenerateContentSetup']['systemInstruction']['parts'][0]['text'];

            return $d['uses'] === 1
                && str_contains($prompt, 'حاج علی احمدی')   // مسابقه خاندان: اطلاعات عمومی بستگان
                && str_contains($prompt, 'فرش‌فروش')
                && ! str_contains($prompt, '09120000001')     // هرگز شماره، کد ملی یا نشانی
                && ! str_contains($prompt, '0012345679')
                && ! str_contains($prompt, 'محرمانه');
        });
        // CSP اجازه اتصال مستقیم مرورگر به همان سرویس را می‌دهد
        $this->assertStringContainsString('wss://generativelanguage.googleapis.com', $this->get('/')->headers->get('Content-Security-Policy'));

        config(['pedigree.ai.live.provider' => 'openai']);
        $this->postJson('/api/assistant/live')->assertOk()->assertJsonPath('data.token', 'ek_EPHEMERAL')->assertJsonPath('data.provider', 'openai');
        // سقف روزانه
        $this->postJson('/api/assistant/live')->assertStatus(429);
    }

    public function test_family_quiz_mode_can_be_switched_off(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'سؤال اول ...']]]]]])]);
        $this->actingAs($this->user, 'sanctum');
        $this->assertContains('family_quiz', array_column($this->getJson('/api/assistant')->json('data.modes'), 'key'));
        $this->postJson('/api/assistant/chat', ['mode' => 'family_quiz', 'messages' => [['role' => 'user', 'content' => 'شروع']]])->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->data()['systemInstruction']['parts'][0]['text'], 'حاج علی احمدی'));

        config(['pedigree.ai.family_data' => false]);
        $this->assertNotContains('family_quiz', array_column($this->getJson('/api/assistant')->json('data.modes'), 'key'));
        $this->postJson('/api/assistant/chat', ['mode' => 'family_quiz', 'messages' => [['role' => 'user', 'content' => 'شروع']]])->assertStatus(422)->assertJsonPath('code', 'ai_family');
    }
}
