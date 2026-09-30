<?php

namespace Tests\Feature;

use App\Models\Call;
use App\Models\CallSignal;
use App\Models\User;
use App\Models\UserBlock;
use App\Services\Calls\CallLinks;
use App\Services\Calls\CallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تماس مستقیم (WebRTC): فقط اعضای فعال و بدون مسدودسازی، زنگ از کش، پیام‌های راه‌اندازی فقط بین شرکت‌کنندگان،
 * تماس از دست رفته، پایان خودکار و دعوت با لینک امن سرویس‌های بیرونی.
 */
class CallTest extends TestCase
{
    use RefreshDatabase;

    private User $ali;

    private User $sara;

    private User $reza;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pedigree.calls.enabled' => true, 'pedigree.calls.max_participants' => 4, 'services.fcm.enabled' => false]);
        $this->ali = $this->member('علی');
        $this->sara = $this->member('سارا', 'f');
        $this->reza = $this->member('رضا');
    }

    private function member(string $name, string $gender = 'm'): User
    {
        $user = User::factory()->withPerson(['first_name' => $name, 'last_name' => 'کریمی', 'gender' => $gender])->create()->refresh();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    private function dial(User $from, array $to, string $kind = 'audio')
    {
        return $this->actingAs($from, 'sanctum')->postJson('/api/calls', ['person_ids' => array_map(fn (User $u) => $u->person_id, $to), 'kind' => $kind]);
    }

    public function test_one_to_one_call_rings_answers_and_exchanges_signals(): void
    {
        $res = $this->dial($this->ali, [$this->sara])->assertCreated();
        $id = $res->json('data.id');
        $this->assertSame('ringing', $res->json('data.status'));
        $this->assertSame('stun:stun.l.google.com:19302', $res->json('data.ice.servers.0.urls.0'));

        // سارا زنگ را می‌بیند؛ رضا نه
        $ring = $this->actingAs($this->sara, 'sanctum')->getJson('/api/calls/ring')->assertOk()->json('data');
        $this->assertSame($id, $ring['id']);
        $this->assertSame('علی کریمی', $ring['caller']['name']);
        $this->assertNull($this->actingAs($this->reza, 'sanctum')->getJson('/api/calls/ring')->json('data'));

        // پیش از پاسخ، سارا پیام راه‌اندازی نمی‌تواند بفرستد
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->ali->id, 'type' => 'offer', 'data' => ['sdp' => 'x']])->assertStatus(409);

        $answer = $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/answer")->assertOk()->json('data');
        $this->assertSame('active', $answer['status']);
        $this->assertNull($this->actingAs($this->sara, 'sanctum')->getJson('/api/calls/ring')->json('data'));

        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->sara->id, 'type' => 'offer', 'data' => ['sdp' => "v=0\r\no=- 1 2 IN IP4 127.0.0.1"]])->assertOk();
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->sara->id, 'type' => 'candidates', 'data' => ['list' => [['candidate' => 'candidate:1 1 udp 1 1.2.3.4 5000 typ host']]]])->assertOk();

        $poll = $this->actingAs($this->sara, 'sanctum')->getJson("/api/calls/{$id}/poll?after=0")->assertOk();
        $this->assertCount(2, $poll->json('signals'));
        $this->assertSame('offer', $poll->json('signals.0.type'));
        $this->assertSame($this->ali->id, $poll->json('signals.0.from'));
        $this->assertStringStartsWith('v=0', $poll->json('signals.0.data.sdp'));
        $last = $poll->json('signals.1.id');
        $this->assertSame([], $this->actingAs($this->sara, 'sanctum')->getJson("/api/calls/{$id}/poll?after={$last}")->json('signals'));
        // علی پیام‌های خودش را دریافت نمی‌کند
        $this->assertSame([], $this->actingAs($this->ali, 'sanctum')->getJson("/api/calls/{$id}/poll?after=0")->json('signals'));

        // غریبه: هیچ دسترسی (حتی وجود تماس فاش نمی‌شود)
        $this->actingAs($this->reza, 'sanctum')->getJson("/api/calls/{$id}/poll")->assertNotFound();
        $this->actingAs($this->reza, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->ali->id, 'type' => 'offer', 'data' => ['sdp' => 'x']])->assertNotFound();
        $this->actingAs($this->reza, 'sanctum')->postJson("/api/calls/{$id}/answer")->assertNotFound();
        // گیرنده بیرون از تماس، نوع نامعتبر، پیام بزرگ
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->reza->id, 'type' => 'offer', 'data' => ['sdp' => 'x']])->assertStatus(422);
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->sara->id, 'type' => 'eval', 'data' => []])->assertStatus(422);
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->sara->id, 'type' => 'offer', 'data' => ['sdp' => str_repeat('a', 40000)]])->assertStatus(422);

        // پایان: سارا قطع می‌کند ← تماس تمام
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/leave")->assertOk();
        $this->assertSame('ended', Call::query()->find($id)->status);
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->sara->id, 'type' => 'offer', 'data' => ['sdp' => 'x']])->assertStatus(409);
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/answer")->assertStatus(410);

        $history = $this->actingAs($this->ali, 'sanctum')->getJson('/api/calls')->assertOk()->json('data');
        $this->assertTrue($history[0]['outgoing']);
        $this->assertTrue($history[0]['answered']);
        $this->assertSame('سارا کریمی', $history[0]['people'][0]['name']);
    }

    public function test_missed_declined_and_abandoned_calls(): void
    {
        // بی‌پاسخ: پس از ۴۵ ثانیه «تماس از دست رفته»
        $id = $this->dial($this->ali, [$this->sara])->json('data.id');
        $this->travel(CallService::RING_SECONDS + 2)->seconds();
        $this->actingAs($this->ali, 'sanctum')->getJson("/api/calls/{$id}/poll")->assertOk()->assertJsonPath('call.status', 'ended');
        $this->assertNull($this->actingAs($this->sara, 'sanctum')->getJson('/api/calls/ring')->json('data'));
        $n = $this->sara->notifications()->first();
        $this->assertSame('missed_call', $n->data['kind']);
        $this->assertStringContainsString('علی کریمی', $n->data['body']);

        // رد تماس
        $id = $this->dial($this->ali, [$this->sara], 'video')->json('data.id');
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/decline")->assertOk();
        $poll = $this->actingAs($this->ali, 'sanctum')->getJson("/api/calls/{$id}/poll")->json('call');
        $this->assertSame('ended', $poll['status']);
        $this->assertSame('declined', collect($poll['participants'])->firstWhere('user_id', $this->sara->id)['state']);

        // تماس‌گیرنده پیش از پاسخ قطع می‌کند ← از دست رفته
        $id = $this->dial($this->ali, [$this->sara])->json('data.id');
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/calls/{$id}/leave")->assertOk();
        $this->assertSame('ended', Call::query()->find($id)->status);
        $this->assertSame(2, $this->sara->notifications()->get()->filter(fn ($n) => $n->data['kind'] === 'missed_call')->count());

        // شرکت‌کننده‌ای که دیگر خبری از او نیست (بسته شدن صفحه بدون قطع) ← تماس تمام
        $id = $this->dial($this->ali, [$this->sara])->json('data.id');
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/answer")->assertOk();
        $this->travel(CallService::GONE_SECONDS + 5)->seconds();
        $this->actingAs($this->ali, 'sanctum')->getJson("/api/calls/{$id}/poll")->assertOk();
        // علی همین حالا پرسیده؛ سارا ساکت مانده ← خارج و پایان
        $this->assertSame('ended', Call::query()->find($id)->status);

        // پاک‌سازی دوره‌ای پیام‌های قدیمی
        CallSignal::query()->create(['call_id' => $id, 'from_user_id' => $this->ali->id, 'to_user_id' => $this->sara->id, 'type' => 'bye', 'payload' => '{}']);
        $this->travel(20)->minutes();
        app(CallService::class)->prune();
        $this->assertSame(0, CallSignal::query()->count());
    }

    public function test_group_call_and_limits(): void
    {
        $res = $this->dial($this->ali, [$this->sara, $this->reza])->assertCreated();
        $id = $res->json('data.id');
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/answer")->assertOk();
        $this->actingAs($this->reza, 'sanctum')->postJson("/api/calls/{$id}/answer")->assertOk();
        // سارا و رضا هم می‌توانند با هم پیام راه‌اندازی رد و بدل کنند (mesh)
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/signal", ['to' => $this->reza->id, 'type' => 'offer', 'data' => ['sdp' => 'x']])->assertOk();
        // یک نفر برود، تماس بین بقیه ادامه دارد
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/calls/{$id}/leave")->assertOk();
        $this->assertSame('active', Call::query()->find($id)->status);
        $this->actingAs($this->reza, 'sanctum')->postJson("/api/calls/{$id}/leave")->assertOk();
        $this->assertSame('ended', Call::query()->find($id)->status);

        // سقف نفرات
        $more = [$this->sara, $this->reza, $this->member('مینا', 'f'), $this->member('حسن')];
        $this->dial($this->ali, $more)->assertStatus(422);
        // خودم، غیرعضو، مسدود، تماس خاموش
        $this->actingAs($this->ali, 'sanctum')->postJson('/api/calls', ['person_ids' => [$this->ali->person_id], 'kind' => 'audio'])->assertStatus(422);
        $guest = User::factory()->withPerson()->create()->refresh();
        $guest->forceFill(['last_login_at' => null])->save(); // هرگز وارد نشده
        $this->dial($this->ali, [$guest])->assertStatus(422);
        $this->actingAs($this->ali, 'sanctum')->postJson('/api/calls', ['person_ids' => ['00000000-0000-0000-0000-000000000000'], 'kind' => 'audio'])->assertStatus(422);
        UserBlock::query()->create(['blocker_id' => $this->sara->id, 'blocked_id' => $this->ali->id]);
        $this->dial($this->ali, [$this->sara])->assertStatus(403);
        $this->actingAs($this->ali, 'sanctum')->postJson('/api/calls', ['person_ids' => [$this->reza->person_id], 'kind' => 'screen'])->assertStatus(422);
        config(['pedigree.calls.enabled' => false]);
        $this->dial($this->ali, [$this->reza])->assertStatus(403);
        $this->assertNull($this->actingAs($this->reza, 'sanctum')->getJson('/api/calls/ring')->json('data'));
    }

    public function test_turn_credentials_and_relay_only(): void
    {
        config([
            'pedigree.calls.turn_urls' => 'turn:turn.example.com:3478?transport=udp, javascript:alert(1)',
            'pedigree.calls.turn_secret' => 'top-secret',
            'pedigree.calls.relay_only' => true,
        ]);
        $ice = $this->dial($this->ali, [$this->sara])->json('data.ice');
        $turn = $ice['servers'][1];
        $this->assertSame(['turn:turn.example.com:3478?transport=udp'], $turn['urls']);
        [$expires, $uid] = explode(':', $turn['username']);
        $this->assertSame((string) $this->ali->id, $uid);
        $this->assertGreaterThan(now()->addHours(5)->getTimestamp(), (int) $expires);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'top-secret', true)), $turn['credential']);
        $this->assertTrue($ice['relay_only']);
        $this->assertStringNotContainsString('top-secret', json_encode($ice));
    }

    public function test_member_search_hides_blocked_and_inactive(): void
    {
        UserBlock::query()->create(['blocker_id' => $this->reza->id, 'blocked_id' => $this->ali->id]);
        User::factory()->withPerson(['first_name' => 'مهمان'])->create()->refresh()->forceFill(['last_login_at' => null])->save();
        User::factory()->withPerson(['first_name' => 'معلق'])->create(['status' => User::STATUS_BLOCKED]);
        $names = collect($this->actingAs($this->ali, 'sanctum')->getJson('/api/calls/people')->assertOk()->json('data'))->pluck('first_name')->all();
        $this->assertSame(['سارا'], $names);
        $this->assertSame(['سارا'], collect($this->actingAs($this->ali, 'sanctum')->getJson('/api/calls/people?q=سارا')->json('data'))->pluck('first_name')->all());
    }

    public function test_guests_cannot_use_calls(): void
    {
        $this->getJson('/api/calls/people')->assertUnauthorized();
        $this->getJson('/api/calls/ring')->assertUnauthorized();
        $this->postJson('/api/calls', [])->assertUnauthorized();
        $this->postJson('/api/call-invites', [])->assertUnauthorized();
    }

    public function test_call_links_are_strictly_validated(): void
    {
        $ok = [
            'https://call.whatsapp.com/voice/AbCdEf123456' => 'whatsapp',
            'https://call.whatsapp.com/video/AbCdEf123456' => 'whatsapp',
            'https://meet.google.com/abc-defg-hij' => 'meet',
            'https://meet.jit.si/Shajare-Abc234xyz' => 'jitsi',
            'https://www.skyroom.online/ch/family/yalda' => 'skyroom',
            'https://us05web.zoom.us/j/12345678901?pwd=abc.DEF' => 'zoom',
            'https://t.me/family_group?videochat' => 'telegram',
        ];
        foreach ($ok as $url => $provider) {
            $this->assertSame($provider, CallLinks::parse($url)['provider'] ?? null, $url);
            $this->assertSame($url, CallLinks::parse($url)['url']);
        }
        foreach ([
            'http://call.whatsapp.com/voice/AbCdEf123456',
            'https://call.whatsapp.com.evil.com/voice/AbCdEf123456',
            'https://evil.com/call.whatsapp.com/voice/AbCdEf123456',
            'https://user@call.whatsapp.com/voice/AbCdEf123456',
            'https://call.whatsapp.com:8443/voice/AbCdEf123456',
            'https://call.whatsapp.com/voice/AbCdEf123456?utm=x',
            'https://call.whatsapp.com/voice/AbCdEf123456#x',
            'https://meet.google.com/abc-defg-hij/../../x',
            'javascript:alert(1)',
            'https://meet.jit.si/a b',
            'https://zoom.us/j/123456789?pwd=a&redirect=https://evil.com',
        ] as $bad) {
            $this->assertNull(CallLinks::parse($bad), $bad);
        }
    }

    public function test_invite_relatives_with_call_link(): void
    {
        $res = $this->actingAs($this->ali, 'sanctum')->postJson('/api/call-invites', [
            'url' => 'https://call.whatsapp.com/video/AbCdEf123456',
            'title' => "دورهمی یلدا\x07",
            'person_ids' => [$this->sara->person_id, $this->reza->person_id, $this->ali->person_id],
        ])->assertCreated();
        $this->assertStringContainsString('۲', $res->json('message'));
        $n = $this->sara->notifications()->first();
        $this->assertSame('call_invite', $n->data['kind']);
        $this->assertSame('#/calls', $n->data['link']);
        $invite = $this->actingAs($this->sara, 'sanctum')->getJson('/api/calls')->json('invites.0');
        $this->assertSame('https://call.whatsapp.com/video/AbCdEf123456', $invite['url']);
        $this->assertSame('دورهمی یلدا', $invite['title']);
        $this->assertFalse($invite['mine']);
        $this->assertSame([], $this->actingAs($this->member('غریبه'), 'sanctum')->getJson('/api/calls')->json('invites'));

        $this->actingAs($this->ali, 'sanctum')->postJson('/api/call-invites', ['url' => 'https://evil.com/x', 'title' => 'x', 'person_ids' => [$this->sara->person_id]])->assertStatus(422);
        $this->actingAs($this->sara, 'sanctum')->deleteJson('/api/call-invites/'.$invite['id'])->assertNotFound();
        $this->actingAs($this->ali, 'sanctum')->deleteJson('/api/call-invites/'.$invite['id'])->assertOk();
        config(['pedigree.calls.links' => false]);
        $this->actingAs($this->ali, 'sanctum')->postJson('/api/call-invites', ['url' => 'https://meet.google.com/abc-defg-hij', 'title' => 'x', 'person_ids' => [$this->sara->person_id]])->assertStatus(403);
    }
}
