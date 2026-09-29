<?php

namespace Tests\Feature;

use App\Models\EditRequest;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use App\Services\Access\PersonAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * پیشنهاد ویرایش بستگان درجه دو و سه (با تأیید مدیر) و قوانین ویرایش ازدواج و طلاق
 */
class EditRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $grandpa;

    private User $father;

    private User $uncle;

    private Person $child;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        // پدربزرگ ← (پدر، عمو) ← کودک
        $this->admin = User::factory()->withPerson(['first_name' => 'مدیر'])->create(['role' => User::ROLE_SUPER_ADMIN])->refresh();
        $this->grandpa = $this->member(['first_name' => 'حاج علی', 'gender' => 'm']);
        $this->father = $this->member(['first_name' => 'حسن', 'gender' => 'm'], $this->grandpa->person_id);
        $this->uncle = $this->member(['first_name' => 'رضا', 'gender' => 'm'], $this->grandpa->person_id);
        $this->child = Person::factory()->create(['first_name' => 'کودک', 'gender' => 'f', 'nickname' => null]);
        $this->child->forceFill(['father_id' => $this->father->person_id])->save();
        $this->stranger = $this->member(['first_name' => 'غریبه']);
    }

    private function member(array $person = [], ?string $fatherId = null): User
    {
        $user = User::factory()->withPerson($person)->create()->refresh();
        if ($fatherId) {
            $user->person->forceFill(['father_id' => $fatherId])->save();
        }

        return $user->refresh();
    }

    public function test_second_degree_relative_suggests_and_admin_approves(): void
    {
        // عمو درجه دو است: پیشنهاد، نه ویرایش مستقیم
        $perm = $this->actingAs($this->uncle, 'sanctum')->getJson("/api/persons/{$this->child->id}")->json('data.permissions');
        $this->assertFalse($perm['edit']);
        $this->assertTrue($perm['suggest']);
        $res = $this->patchJson("/api/persons/{$this->child->id}", ['nickname' => 'گل‌پری', 'occupation' => 'دانش‌آموز', 'edit_reason' => 'از خانواده شنیدم'])
            ->assertStatus(202)->assertJsonPath('pending', true);
        $this->assertEqualsCanonicalizing(['nickname', 'occupation'], $res->json('fields'));
        $this->assertNull($this->child->fresh()->nickname, 'تا تأیید مدیر در سایت نمایش داده نمی‌شود');
        // داده پیشنهاد در پایگاه داده رمزنگاری شده است
        $this->assertStringNotContainsString('گل‌پری', (string) DB::table('edit_requests')->value('changes'));

        // فیلدهای حساس پیشنهادی نیستند (نادیده گرفته می‌شوند)
        $this->patchJson("/api/persons/{$this->child->id}", ['contact_visibility' => 'self', 'address' => 'x'])->assertStatus(422);

        // غریبه (بدون نسبت) نه ویرایش نه پیشنهاد
        $this->actingAs($this->stranger, 'sanctum')->patchJson("/api/persons/{$this->child->id}", ['nickname' => 'x'])->assertForbidden();

        // عضو عادی به فهرست مدیر دسترسی ندارد
        $this->actingAs($this->uncle, 'sanctum')->getJson('/api/admin/edit-requests')->assertForbidden();
        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/edit-requests')->assertOk();
        $this->assertSame(1, $list->json('pending'));
        $row = $list->json('data.0');
        $this->assertSame(2, $row['degree']);
        $this->assertSame('از خانواده شنیدم', $row['reason']);
        $this->assertSame('گل‌پری', collect($row['fields'])->firstWhere('field', 'nickname')['new']);
        $this->assertSame('edit_request', $this->admin->notifications()->first()->data['kind']);

        $this->postJson("/api/admin/edit-requests/{$row['id']}/approve")->assertOk();
        $this->assertSame('گل‌پری', $this->child->fresh()->nickname);
        $this->assertSame('edit_request_decided', $this->uncle->notifications()->first()->data['kind']);
        $this->postJson("/api/admin/edit-requests/{$row['id']}/approve")->assertStatus(422);
    }

    public function test_rejected_suggestion_changes_nothing(): void
    {
        $id = $this->actingAs($this->uncle, 'sanctum')->patchJson("/api/persons/{$this->child->id}", ['nickname' => 'نادرست'])->json('request_id');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/edit-requests/{$id}/reject", ['note' => 'منبع معتبر نیست'])->assertOk();
        $this->assertNull($this->child->fresh()->nickname);
        $this->assertSame(EditRequest::STATUS_REJECTED, EditRequest::find($id)->status);
        // بدون تغییر = خطا
        $this->actingAs($this->uncle, 'sanctum')->patchJson("/api/persons/{$this->child->id}", ['nickname' => null])->assertStatus(422);
    }

    public function test_marriage_divorce_rules_first_then_second_degree(): void
    {
        // ازدواجِ «کودک» (دختر حسن) با مردی بیرون از خاندان
        $husband = Person::factory()->create(['gender' => 'm', 'first_name' => 'داماد']);
        $marriage = Marriage::create(['husband_id' => $husband->id, 'wife_id' => $this->child->id, 'status' => 'married', 'marriage_date' => '1380-05-10']);

        // درجه یک (پدرِ عروس) مستقیم طلاق و تاریخش را ثبت می‌کند
        $this->actingAs($this->father, 'sanctum')->getJson("/api/marriages/{$marriage->id}")->assertOk()->assertJsonPath('data.can_edit', true);
        $this->patchJson("/api/marriages/{$marriage->id}", ['status' => 'divorced', 'end_date' => '1395-03-01'])->assertOk()
            ->assertJsonPath('data.status', 'divorced')->assertJsonPath('data.end_date', '1395-03-01');

        // عموی عروس (درجه دو) وقتی درجه یکِ عضو (پدرش) هست: فقط پیشنهاد
        $show = $this->actingAs($this->uncle, 'sanctum')->getJson("/api/marriages/{$marriage->id}")->assertOk();
        $this->assertFalse($show->json('data.can_edit'));
        $this->assertTrue($show->json('data.can_suggest'));
        $this->assertSame('1380-05-10', $show->json('data.marriage.marriage_date'));
        $this->patchJson("/api/marriages/{$marriage->id}", ['end_date' => '1395-04-01'])->assertStatus(202);
        $this->assertSame('1395-03-01', $marriage->fresh()->end_date);
        $this->deleteJson("/api/marriages/{$marriage->id}")->assertForbidden();

        // غریبه هیچ اجازه‌ای ندارد
        $this->actingAs($this->stranger, 'sanctum')->patchJson("/api/marriages/{$marriage->id}", ['status' => 'married'])->assertForbidden();
    }

    public function test_second_degree_edits_marriage_directly_when_no_first_degree_member_exists(): void
    {
        // زن و شوهری که خودشان و بستگان درجه یکشان هیچ حساب فعالی ندارند
        $oldMan = Person::factory()->create(['gender' => 'm', 'first_name' => 'جد']);
        $oldWife = Person::factory()->create(['gender' => 'f', 'first_name' => 'جده']);
        $son = Person::factory()->create(['gender' => 'm']);
        $son->forceFill(['father_id' => $oldMan->id])->save();
        $grandson = $this->member(['first_name' => 'نوه', 'gender' => 'm'], $son->id);
        $marriage = Marriage::create(['husband_id' => $oldMan->id, 'wife_id' => $oldWife->id, 'status' => 'married']);

        $this->actingAs($grandson, 'sanctum')->getJson("/api/marriages/{$marriage->id}")->assertJsonPath('data.can_edit', true);
        $this->patchJson("/api/marriages/{$marriage->id}", ['status' => 'divorced', 'end_date' => '1340'])->assertOk();
        $this->assertSame('divorced', $marriage->fresh()->status);

        // وقتی پسرشان عضو فعال شود، نوه (درجه دو) دیگر مستقیم ویرایش نمی‌کند
        User::factory()->create(['person_id' => $son->id, 'last_login_at' => now()]);
        $this->app->forgetInstance(PersonAccess::class);
        $this->getJson("/api/marriages/{$marriage->id}")->assertJsonPath('data.can_edit', false);
    }
}
