<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ورود با نام کاربری/کد ملی/موبایل + رمز، قوانین ثبت‌نام، و استوری‌ها.
 */
class LoginAndStoriesTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $person = []): User
    {
        return User::factory()->withPerson($person)->create()->refresh();
    }

    public function test_child_sets_username_and_password_for_elderly_parent(): void
    {
        $son = $this->member(['gender' => 'm']);
        // مادربزرگ بدون موبایل و کد ملی؛ پسرش پروفایل را ساخته
        $grandma = $this->actingAs($son, 'sanctum')->postJson("/api/persons/{$son->person_id}/relatives", [
            'type' => 'mother', 'first_name' => 'طاهره', 'birth_cert_no' => '۱۲۳',
        ])->assertCreated()->json('data');

        $this->patchJson("/api/persons/{$grandma['id']}", ['username' => ' Tahereh.K ', 'password' => 'mama1234'])->assertOk()
            ->assertJsonPath('data.account.username', 'tahereh.k');

        // نام کاربری تکراری و رزروشده پذیرفته نمی‌شود
        $other = $this->member();
        $this->actingAs($other, 'sanctum')->patchJson("/api/persons/{$other->person_id}", ['username' => 'tahereh.k'])->assertJsonValidationErrors('username');
        $this->patchJson("/api/persons/{$other->person_id}", ['username' => 'admin'])->assertJsonValidationErrors('username');
        $this->patchJson("/api/persons/{$other->person_id}", ['username' => '1abc'])->assertJsonValidationErrors('username');

        // ورود مادربزرگ با نام کاربری
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['identifier' => 'TAHEREH.K', 'password' => 'mama1234', 'device_name' => 'tablet'])
            ->assertOk()->assertJsonPath('user.person.id', $grandma['id']);
        $this->assertSame('username', ActivityLog::where('action', 'auth.login')->latest('id')->first()->properties['method']);

        // رمز اشتباه
        $this->postJson('/api/auth/login', ['identifier' => 'tahereh.k', 'password' => 'wrong123'])->assertStatus(422);
    }

    public function test_login_identifier_accepts_national_code_and_phone(): void
    {
        $user = $this->member();
        $user->person->forceFill(['national_code' => '0012345679', 'phone' => '09121112233'])->save();

        $this->postJson('/api/auth/login', ['identifier' => '۰۰۱۲۳۴۵۶۷۹', 'password' => 'secret123', 'device_name' => 'a'])->assertOk();
        $this->postJson('/api/auth/login', ['identifier' => '+98 912 111 2233', 'password' => 'secret123', 'device_name' => 'a'])->assertOk();
        // فیلد قدیمی national_code (اپ‌های قبلی)
        $this->postJson('/api/auth/login', ['national_code' => '0012345679', 'password' => 'secret123', 'device_name' => 'a'])->assertOk();
        $this->postJson('/api/auth/login', ['password' => 'secret123'])->assertJsonValidationErrors('identifier');
    }

    public function test_account_username_endpoint(): void
    {
        $user = $this->member();
        $this->actingAs($user, 'sanctum')->putJson('/api/account/username', ['username' => 'Ali_R'])->assertOk()->assertJsonPath('username', 'ali_r');
        $this->putJson('/api/account/username', ['username' => 'ali r'])->assertJsonValidationErrors('username');
        $this->putJson('/api/account/username', ['username' => null])->assertOk()->assertJsonPath('username', null);
    }

    public function test_registration_requires_national_code_unless_declared_missing(): void
    {
        // خطای اعتبارسنجی توکن ثبت‌نام را مصرف نمی‌کند؛ کاربر فرم را اصلاح و دوباره ارسال می‌کند
        $code = $this->postJson('/api/auth/otp', ['phone' => '09350000001'])->json('debug_code');
        $token = $this->postJson('/api/auth/otp/verify', ['phone' => '09350000001', 'code' => $code])->json('registration_token');
        $register = function (array $extra) use ($token) {
            return $this->postJson('/api/auth/register', $extra + [
                'registration_token' => $token, 'first_name' => 'سارا', 'last_name' => 'نوری', 'gender' => 'f', 'device_name' => 'x',
            ]);
        };

        $register([])->assertJsonValidationErrors('national_code');
        $register(['national_code' => '1234567890'])->assertJsonValidationErrors('national_code');

        // کد ملی متعلق به پروفایل موجود: تصاحب با کد ملی ممکن نیست
        Person::factory()->create()->forceFill(['national_code' => '0023456787'])->save();
        $register(['national_code' => '0023456787'])->assertJsonValidationErrors('national_code');

        // کسی که کد ملی ایرانی ندارد
        $register(['no_national_code' => true])->assertOk();
        $this->assertNull(Person::findByPhone('09350000001')->national_code);
    }

    public function test_registration_without_national_code_can_be_disabled(): void
    {
        config(['pedigree.registration.allow_without_national_code' => false]);
        $code = $this->postJson('/api/auth/otp', ['phone' => '09350000002'])->json('debug_code');
        $token = $this->postJson('/api/auth/otp/verify', ['phone' => '09350000002', 'code' => $code])->json('registration_token');
        $this->postJson('/api/auth/register', [
            'registration_token' => $token, 'first_name' => 'x', 'last_name' => 'y', 'gender' => 'm', 'no_national_code' => true,
        ])->assertJsonValidationErrors('national_code');
    }

    public function test_stories_are_uploaded_listed_and_deletion_is_logged(): void
    {
        Storage::fake('media');
        config(['pedigree.media.disk' => 'media']);
        $me = $this->member();
        $this->actingAs($me, 'sanctum');

        $story = $this->post("/api/persons/{$me->person_id}/media", [
            'file' => UploadedFile::fake()->image('story.jpg', 900, 1600),
            'category' => 'story',
            'caption' => 'تولد مادربزرگ',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->assertSame('story', $story['category']);
        $this->assertSame('approved', $story['status']);

        $this->post("/api/persons/{$me->person_id}/media", ['file' => UploadedFile::fake()->image('g.jpg', 400, 300)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.category', 'gallery');

        $this->getJson("/api/persons/{$me->person_id}/media?category=story")->assertJsonCount(1, 'data');
        $this->post("/api/persons/{$me->person_id}/media", ['file' => UploadedFile::fake()->image('x.jpg'), 'category' => 'ads'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->deleteJson("/api/media/{$story['id']}")->assertOk();
        $history = collect($this->getJson("/api/persons/{$me->person_id}/history")->json('data'));
        $deleted = $history->firstWhere('action', 'media.deleted');
        $this->assertSame('story', $deleted['properties']['category']);
        $this->assertSame('تولد مادربزرگ', $deleted['properties']['caption']);
        $this->assertNotNull($history->firstWhere('action', 'media.uploaded'));
    }
}
