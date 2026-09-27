<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeMember(string $phone = '09121234567', ?string $nationalCode = '1234567891'): User
    {
        $user = User::factory()->create();
        $person = Person::factory()->male()->make();
        $person->phone = $phone;
        $person->national_code = $nationalCode;
        $person->created_by = $user->id;
        $person->save();
        $user->person_id = $person->id;
        $user->save();

        return $user;
    }

    public function test_otp_login_issues_token_for_mobile_clients(): void
    {
        $this->makeMember();

        $response = $this->postJson('/api/auth/otp', ['phone' => '۰۹۱۲ ۱۲۳ ۴۵۶۷'])->assertOk();
        $code = $response->json('debug_code');
        $this->assertNotEmpty($code);

        $this->postJson('/api/auth/otp/verify', ['phone' => '09121234567', 'code' => '000000x', 'device_name' => 'test'])
            ->assertStatus(422);

        $login = $this->postJson('/api/auth/otp/verify', ['phone' => '09121234567', 'code' => $code, 'device_name' => 'Pixel'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'person']]);

        $this->withToken($login->json('token'))->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.person.gender', 'm');
    }

    public function test_otp_code_cannot_be_reused(): void
    {
        $this->makeMember();
        $code = $this->postJson('/api/auth/otp', ['phone' => '09121234567'])->json('debug_code');
        $this->postJson('/api/auth/otp/verify', ['phone' => '09121234567', 'code' => $code, 'device_name' => 'a'])->assertOk();
        $this->postJson('/api/auth/otp/verify', ['phone' => '09121234567', 'code' => $code, 'device_name' => 'a'])->assertStatus(422);
    }

    public function test_otp_resend_cooldown(): void
    {
        $this->makeMember();
        $this->postJson('/api/auth/otp', ['phone' => '09121234567'])->assertOk();
        $this->postJson('/api/auth/otp', ['phone' => '09121234567'])->assertStatus(429);
    }

    public function test_registration_for_new_phone(): void
    {
        $code = $this->postJson('/api/auth/otp', ['phone' => '09350000000'])->json('debug_code');
        $verify = $this->postJson('/api/auth/otp/verify', ['phone' => '09350000000', 'code' => $code])
            ->assertOk()->assertJsonPath('registration_required', true);

        $this->postJson('/api/auth/register', [
            'registration_token' => $verify->json('registration_token'),
            'first_name' => 'مریم',
            'last_name' => 'کریمی',
            'gender' => 'f',
            'national_code' => '۰۰۱۲۳۴۵۶۷۹',
            'device_name' => 'iPhone',
        ])->assertOk()->assertJsonStructure(['token']);
        $this->assertSame('0012345679', Person::findByPhone('09350000000')->national_code);

        $this->assertNotNull(Person::findByPhone('09350000000'));
        // ثبت‌نام آزاد هرگز مدیر نمی‌سازد (مگر با تنظیم صریح)
        $this->assertSame(User::ROLE_MEMBER, User::first()->role);
    }

    public function test_password_login_and_lockout(): void
    {
        $this->makeMember();

        $this->postJson('/api/auth/login', ['national_code' => '1234567891', 'password' => 'secret123', 'device_name' => 'x'])
            ->assertOk()->assertJsonStructure(['token']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['national_code' => '1234567891', 'password' => 'wrong-pass1'])->assertStatus(422);
        }
        $this->postJson('/api/auth/login', ['national_code' => '1234567891', 'password' => 'secret123'])->assertStatus(429);
    }

    public function test_password_set_by_others_is_cleared_when_owner_logs_in_with_otp(): void
    {
        // یکی از بستگان برای پروفایل من رمز گذاشته و وارد شده
        $relative = User::factory()->withPerson(['gender' => 'm'])->create()->refresh();
        $me = $this->makeMember('09127777777', '0499370899');
        $me->forceFill(['password' => 'relative123', 'password_set_by' => $relative->id])->save();
        $token = $me->createToken('relative-device')->plainTextToken;

        // صاحب واقعی شماره با پیامک وارد می‌شود
        $code = $this->postJson('/api/auth/otp', ['phone' => '09127777777'])->json('debug_code');
        $this->postJson('/api/auth/otp/verify', ['phone' => '09127777777', 'code' => $code, 'device_name' => 'mine'])->assertOk();

        $me->refresh();
        $this->assertNull($me->password);
        $this->assertSame(1, $me->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_relatives_cannot_mark_active_member_as_deceased(): void
    {
        $me = $this->makeMember();
        $me->forceFill(['last_login_at' => now()])->save();
        $father = Person::factory()->male()->create();
        $me->person->forceFill(['father_id' => $father->id])->save();
        $sibling = User::factory()->withPerson(['gender' => 'f'])->create()->refresh();
        $sibling->person->forceFill(['father_id' => $father->id])->save();

        $this->actingAs($sibling->refresh(), 'sanctum');
        $this->patchJson("/api/persons/{$me->person_id}", ['is_deceased' => true])->assertForbidden();
        $this->assertFalse($me->person->fresh()->is_deceased);
    }

    public function test_deceased_person_cannot_login(): void
    {
        $user = $this->makeMember();
        $user->person->update(['is_deceased' => true]);
        $this->postJson('/api/auth/login', ['national_code' => '1234567891', 'password' => 'secret123', 'device_name' => 'x'])
            ->assertStatus(403);
    }

    public function test_sensitive_data_is_encrypted_at_rest(): void
    {
        $user = $this->makeMember();
        $raw = \DB::table('persons')->where('id', $user->person_id)->first();
        $this->assertStringNotContainsString('1234567891', $raw->national_code);
        $this->assertStringNotContainsString('09121234567', $raw->phone);
        $this->assertSame('1234567891', $user->person->fresh()->national_code);
    }

    public function test_guests_cannot_read_tree_by_default(): void
    {
        $user = $this->makeMember();
        $this->getJson('/api/tree/'.$user->person_id.'/descendants')->assertStatus(401);
    }

    public function test_security_headers_present(): void
    {
        $this->get('/')->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = $this->get('/')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $csp);
    }
}
