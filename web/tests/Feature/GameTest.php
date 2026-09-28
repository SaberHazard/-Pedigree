<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * بازی‌های خانوادگی از روی شجره‌نامه
 */
class GameTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_games(): void
    {
        Storage::fake('media');
        $admin = User::factory()->withPerson(['first_name' => 'مدیر', 'gender' => 'm', 'birth_date' => '1350-01-01'])->create(['role' => User::ROLE_ADMIN])->refresh();
        $this->actingAs($admin, 'sanctum');

        // هنوز عکسی نیست
        $this->getJson('/api/games/question?type=who')->assertStatus(422)->assertJsonPath('code', 'game_empty');
        $this->getJson('/api/games/question?type=hack')->assertStatus(422);

        // پدر و برادر مدیر
        $father = $this->postJson("/api/persons/{$admin->person_id}/relatives", ['type' => 'father', 'first_name' => 'حسن', 'last_name' => 'احمدی', 'gender' => 'm', 'birth_date' => '1320-05-05'])->assertCreated()->json('data.id');
        $this->postJson("/api/persons/{$father}/relatives", ['type' => 'child', 'first_name' => 'رضا', 'last_name' => 'احمدی', 'gender' => 'm', 'birth_date' => '1355-02-02'])->assertCreated();

        $kin = $this->getJson('/api/games/question?type=kin')->assertOk()->json('data');
        $this->assertContains($kin['answer'], ['پدر', 'برادر']);
        $this->assertCount(4, $kin['options']);
        $this->assertContains($kin['answer'], array_column($kin['options'], 'id'));

        $older = $this->getJson('/api/games/question?type=older')->assertOk()->json('data');
        $this->assertCount(2, $older['options']);

        // عکس پروفایل برای سه نفر
        foreach ([$admin->person_id, $father] as $i => $pid) {
            $img = imagecreatetruecolor(120, 120);
            imagefilledrectangle($img, 0, 0, 120, 120, imagecolorallocate($img, 30 * $i, 100, 200));
            $path = tempnam(sys_get_temp_dir(), 'av').'.png';
            imagepng($img, $path);
            $this->post("/api/persons/{$pid}/media", ['file' => new UploadedFile($path, 'a.png', 'image/png', null, true), 'as_avatar' => 1], ['Accept' => 'application/json'])->assertCreated();
        }
        $who = $this->getJson('/api/games/question?type=who')->assertOk()->json('data');
        $this->assertNotEmpty($who['image']);
        $this->assertContains($who['answer'], array_column($who['options'], 'id'));
        $this->assertGreaterThanOrEqual(3, count($who['options']));
    }
}
