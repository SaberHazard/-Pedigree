<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
    }

    private function image(string $name = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 1200, 900);
    }

    private function child(Person $father, string $gender = 'm'): User
    {
        $user = User::factory()->withPerson(['gender' => $gender])->create()->refresh();
        $user->person->forceFill(['father_id' => $father->id])->save();

        return $user->refresh();
    }

    public function test_upload_for_deceased_parent_needs_majority_of_children(): void
    {
        $father = Person::factory()->male()->deceased()->create();
        $a = $this->child($father);
        $b = $this->child($father);
        $c = $this->child($father, 'f');
        $d = $this->child($father, 'f');

        // فرزند اول برای پدر درگذشته عکس آپلود می‌کند
        $this->actingAs($a, 'sanctum');
        $media = $this->postJson("/api/persons/{$father->id}/media", ['file' => $this->image(), 'caption' => 'عکس جوانی'])
            ->assertCreated()->json('data');
        $this->assertSame('pending', $media['status']);
        $this->assertSame('vote', $media['approval_mode']);
        $this->assertSame(3, $media['votes']['total']); // سه فرزند دیگر

        // فایل‌ها ساخته و به WebP تبدیل شده‌اند
        $model = Media::find($media['id']);
        Storage::disk('media')->assertExists($model->path);
        Storage::disk('media')->assertExists($model->variants['thumb']);
        $this->assertSame('image/jpeg', $model->mime);

        // دیگران (به جز رأی‌دهندگان) فایل در انتظار را نمی‌بینند
        $stranger = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($stranger, 'sanctum');
        $this->assertCount(0, $this->getJson("/api/persons/{$father->id}/media")->json('data'));

        // رأی اول موافق: هنوز اکثریت نیست (۱ از ۳)
        $this->actingAs($b, 'sanctum');
        $this->assertCount(1, $this->getJson('/api/approvals')->json('to_vote'));
        $this->postJson("/api/media/{$media['id']}/vote", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'pending');

        // رأی دوم موافق: ۲ از ۳ → تأیید
        $this->actingAs($c, 'sanctum');
        $this->postJson("/api/media/{$media['id']}/vote", ['decision' => 'approve'])->assertOk()->assertJsonPath('data.status', 'approved');

        // حالا برای همه قابل مشاهده است
        $this->actingAs($stranger, 'sanctum');
        $this->assertCount(1, $this->getJson("/api/persons/{$father->id}/media")->json('data'));

        // رأی بعد از پایان رأی‌گیری ممکن نیست
        $this->actingAs($d, 'sanctum');
        $this->postJson("/api/media/{$media['id']}/vote", ['decision' => 'reject'])->assertStatus(422);
    }

    public function test_rejection_deletes_files(): void
    {
        $father = Person::factory()->male()->deceased()->create();
        $a = $this->child($father);
        $b = $this->child($father);

        $this->actingAs($a, 'sanctum');
        $id = $this->postJson("/api/persons/{$father->id}/media", ['file' => $this->image()])->json('data.id');
        $path = Media::find($id)->path;

        $this->actingAs($b, 'sanctum');
        $this->postJson("/api/media/{$id}/vote", ['decision' => 'reject', 'comment' => 'نامناسب'])->assertJsonPath('data.status', 'rejected');
        Storage::disk('media')->assertMissing($path);
    }

    public function test_living_owner_decides_and_self_upload_is_auto_approved(): void
    {
        $father = Person::factory()->male()->create();
        $me = $this->child($father);
        $sister = $this->child($father, 'f');

        // آپلود برای خودم: خودکار تأیید
        $this->actingAs($me, 'sanctum');
        $this->postJson("/api/persons/{$me->person_id}/avatar", ['file' => $this->image('me.png')])
            ->assertCreated()->assertJsonPath('data.status', 'approved');
        $this->assertNotNull($me->person->fresh()->avatar_media_id);

        // آپلود برای خواهرم که حساب فعال دارد: فقط خودش تصمیم می‌گیرد
        $media = $this->postJson("/api/persons/{$sister->person_id}/media", ['file' => $this->image()])->json('data');
        $this->assertSame('owner', $media['approval_mode']);
        $this->actingAs($sister, 'sanctum');
        $this->postJson("/api/media/{$media['id']}/vote", ['decision' => 'approve'])->assertJsonPath('data.status', 'approved');
    }

    public function test_signed_media_urls(): void
    {
        $user = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($user, 'sanctum');
        $media = $this->postJson("/api/persons/{$user->person_id}/media", ['file' => $this->image()])->json('data');

        $this->get($media['urls']['thumb'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // بدون امضا دسترسی نیست
        $this->get('/m/'.$media['id'].'/thumb')->assertForbidden();
    }

    public function test_stranger_cannot_upload(): void
    {
        $person = Person::factory()->create();
        $stranger = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($stranger, 'sanctum');
        $this->postJson("/api/persons/{$person->id}/media", ['file' => $this->image()])->assertForbidden();
    }

    public function test_non_media_files_are_rejected(): void
    {
        $user = User::factory()->withPerson()->create()->refresh();
        $this->actingAs($user, 'sanctum');
        $file = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo "hi"; ?>');
        $this->postJson("/api/persons/{$user->person_id}/media", ['file' => $file])->assertStatus(422);
    }
}
