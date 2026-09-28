<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * پیام‌رسان اعضا: فقط دو طرف گفتگو، متن رمزنگاری‌شده، مسدودسازی، سقف ارسال و شمارنده نخوانده.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private User $ali;

    private User $sara;

    private User $reza;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ali = $this->member('علی');
        $this->sara = $this->member('سارا');
        $this->reza = $this->member('رضا');
    }

    private function member(string $name, array $user = []): User
    {
        return User::factory()->withPerson(['first_name' => $name, 'last_name' => 'احمدی'])->create($user + ['last_login_at' => now()])->refresh();
    }

    private function start(User $from, User $to): int
    {
        return $this->actingAs($from, 'sanctum')->postJson('/api/messages/start', ['person_id' => $to->person_id])
            ->assertOk()->json('data.id');
    }

    public function test_members_can_chat_and_unread_counter_updates(): void
    {
        $id = $this->start($this->ali, $this->sara);
        $this->assertSame($id, $this->start($this->ali, $this->sara), 'same pair → same conversation');

        $this->actingAs($this->ali, 'sanctum')->postJson("/api/messages/{$id}", ['body' => "سلام سارا 👋\r\nخوبی؟"])
            ->assertCreated()->assertJsonPath('data.mine', true)->assertJsonPath('data.body', "سلام سارا 👋\nخوبی؟");

        // سارا: یک پیام نخوانده
        $this->actingAs($this->sara, 'sanctum')->getJson('/api/auth/me')->assertJsonPath('data.counters.messages', 1);
        $this->getJson('/api/messages')->assertOk()
            ->assertJsonPath('unread_total', 1)
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.unread', 1)
            ->assertJsonPath('data.0.other.user_id', $this->ali->id)
            ->assertJsonPath('data.0.last.text', "سلام سارا 👋\nخوبی؟");

        // باز کردن گفتگو = خوانده شد
        $this->getJson("/api/messages/{$id}")->assertOk()
            ->assertJsonPath('data.0.mine', false)
            ->assertJsonPath('conversation.can_send', true);
        $this->getJson('/api/messages/unread')->assertJsonPath('unread', 0);

        $this->postJson("/api/messages/{$id}", ['body' => 'سلام علی 🌹'])->assertCreated();
        $res = $this->actingAs($this->ali, 'sanctum')->getJson("/api/messages/{$id}")->assertOk();
        $this->assertCount(2, $res->json('data'));
        $this->assertSame($res->json('data.0.id'), $res->json('conversation.read_up_to'), 'double tick for the read message');

        // فقط پیام‌های جدیدتر
        $this->getJson("/api/messages/{$id}?after=".$res->json('data.0.id'))->assertJsonCount(1, 'data');
    }

    public function test_message_text_is_encrypted_at_rest(): void
    {
        $id = $this->start($this->ali, $this->sara);
        $this->postJson("/api/messages/{$id}", ['body' => 'رمز گاوصندوق ۱۲۳۴'])->assertCreated();

        $raw = DB::table('direct_messages')->value('body');
        $this->assertStringNotContainsString('گاوصندوق', $raw);
        $this->assertStringNotContainsString('۱۲۳۴', $raw);
    }

    public function test_only_participants_can_see_or_use_a_conversation(): void
    {
        $id = $this->start($this->ali, $this->sara);
        $this->postJson("/api/messages/{$id}", ['body' => 'خصوصی'])->assertCreated();
        $messageId = DB::table('direct_messages')->value('id');

        // حتی مدیر کل سایت هم نمی‌بیند
        $admin = $this->member('مدیر', ['role' => User::ROLE_SUPER_ADMIN]);
        foreach ([$this->reza, $admin] as $outsider) {
            $this->actingAs($outsider, 'sanctum');
            $this->getJson("/api/messages/{$id}")->assertNotFound();
            $this->postJson("/api/messages/{$id}", ['body' => 'نفوذ'])->assertNotFound();
            $this->postJson("/api/messages/{$id}/block", ['blocked' => true])->assertNotFound();
            $this->deleteJson("/api/direct-messages/{$messageId}")->assertNotFound();
            $this->getJson('/api/messages')->assertJsonCount(0, 'data');
        }

        // گیرنده هم نمی‌تواند پیام فرستنده را حذف کند
        $this->actingAs($this->sara, 'sanctum')->deleteJson("/api/direct-messages/{$messageId}")->assertNotFound();
        $this->assertSame(1, DB::table('direct_messages')->whereNull('deleted_at')->count());

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/messages')->assertUnauthorized();
    }

    public function test_sender_can_delete_message_for_both_sides(): void
    {
        $id = $this->start($this->ali, $this->sara);
        $messageId = $this->postJson("/api/messages/{$id}", ['body' => 'اشتباه فرستادم'])->json('data.id');

        $this->deleteJson("/api/direct-messages/{$messageId}")->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.body', null);

        $this->actingAs($this->sara, 'sanctum')->getJson("/api/messages/{$id}")
            ->assertJsonPath('data.0.deleted', true)->assertJsonPath('data.0.body', null);
        $this->assertSame('', decrypt(DB::table('direct_messages')->value('body'), false));
        $this->getJson('/api/messages/unread')->assertJsonPath('unread', 0);
    }

    public function test_block_stops_messages_both_ways(): void
    {
        $id = $this->start($this->ali, $this->sara);
        $this->actingAs($this->sara, 'sanctum')->postJson("/api/messages/{$id}/block", ['blocked' => true])->assertOk();
        $this->getJson("/api/messages/{$id}")->assertJsonPath('conversation.blocked_by_me', true)->assertJsonPath('conversation.can_send', false);
        $this->postJson("/api/messages/{$id}", ['body' => 'سلام'])->assertForbidden()->assertJsonPath('code', 'blocked');

        $this->actingAs($this->ali, 'sanctum')->postJson("/api/messages/{$id}", ['body' => 'سلام'])->assertForbidden();
        $this->postJson('/api/messages/start', ['person_id' => $this->sara->person_id])->assertForbidden();
        $this->getJson("/api/messages/{$id}")->assertJsonPath('conversation.blocked_by_me', false)->assertJsonPath('conversation.can_send', false);

        $this->actingAs($this->sara, 'sanctum')->postJson("/api/messages/{$id}/block", ['blocked' => false])->assertOk();
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/messages/{$id}", ['body' => 'سلام دوباره'])->assertCreated();
    }

    public function test_cannot_message_self_non_members_or_inactive_users(): void
    {
        $this->actingAs($this->ali, 'sanctum')->postJson('/api/messages/start', ['person_id' => $this->ali->person_id])->assertStatus(422);

        // شخصی بدون حساب
        $person = User::factory()->withPerson()->create()->person;
        $person->user()->delete();
        $this->postJson('/api/messages/start', ['person_id' => $person->id])->assertStatus(422)->assertJsonPath('code', 'not_member');

        // حساب ساخته شده ولی هرگز وارد نشده
        $never = User::factory()->withPerson()->create();
        $never->forceFill(['last_login_at' => null])->save();
        $this->postJson('/api/messages/start', ['person_id' => $never->person_id])->assertStatus(422)->assertJsonPath('code', 'not_member');

        // حساب غیرفعال شده پس از شروع گفتگو
        $id = $this->start($this->ali, $this->reza);
        $this->reza->forceFill(['status' => User::STATUS_BLOCKED])->save();
        $this->actingAs($this->ali, 'sanctum')->postJson("/api/messages/{$id}", ['body' => 'سلام'])->assertStatus(422);

        $this->postJson('/api/messages/start', ['person_id' => 'not-a-uuid'])->assertStatus(422);
        $this->assertSame(1, Conversation::count());
    }

    public function test_body_is_validated_and_cleaned(): void
    {
        $id = $this->start($this->ali, $this->sara);
        $this->postJson("/api/messages/{$id}", ['body' => "   \n\n  "])->assertStatus(422);
        $this->postJson("/api/messages/{$id}", ['body' => str_repeat('ا', 2001)])->assertStatus(422);
        $this->postJson("/api/messages/{$id}", ['body' => ['x']])->assertStatus(422);

        // نویسه‌های جهت‌دهی مخفی (فریب متن) و کنترلی حذف می‌شوند؛ نیم‌فاصله و ایموجی‌های ترکیبی می‌مانند
        $this->postJson("/api/messages/{$id}", ['body' => "می\u{200C}خواهم \u{202E}olleh\u{202C} 👨\u{200D}👩\u{200D}👧\x07\n\n\n\nتمام"])
            ->assertCreated()->assertJsonPath('data.body', "می\u{200C}خواهم olleh 👨\u{200D}👩\u{200D}👧\n\nتمام");

        // HTML فقط متن است (نمایش سمت کاربر با textContent)
        $this->postJson("/api/messages/{$id}", ['body' => '<img src=x onerror=alert(1)>'])->assertCreated()
            ->assertJsonPath('data.body', '<img src=x onerror=alert(1)>');
    }

    public function test_send_rate_limit(): void
    {
        config(['pedigree.messaging.daily_limit' => 3]);
        $id = $this->start($this->ali, $this->sara);
        foreach (range(1, 3) as $i) {
            $this->postJson("/api/messages/{$id}", ['body' => "پیام {$i}"])->assertCreated();
        }
        $this->postJson("/api/messages/{$id}", ['body' => 'یکی دیگر'])->assertStatus(429)->assertJsonPath('code', 'message_limit');
    }
}
