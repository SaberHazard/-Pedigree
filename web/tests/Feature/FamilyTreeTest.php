<?php

namespace Tests\Feature;

use App\Models\LinkRequest;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyTreeTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $person = []): User
    {
        return User::factory()->withPerson($person)->create()->refresh();
    }

    public function test_add_relatives_and_build_descendant_tree(): void
    {
        $me = $this->member(['gender' => 'm', 'first_name' => 'علی']);
        $this->actingAs($me, 'sanctum');
        $myId = $me->person_id;

        // پدر و مادر
        $father = $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'father', 'first_name' => 'حسن', 'last_name' => 'احمدی', 'is_deceased' => true])
            ->assertCreated()->json('data');
        $mother = $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'mother', 'first_name' => 'زهرا'])
            ->assertCreated()->json('data');
        $this->assertSame('m', $father['gender']);
        $this->assertSame('f', $mother['gender']);
        // پدر و مادر خودکار همسر ثبت شده‌اند
        $this->assertTrue(Marriage::where('husband_id', $father['id'])->where('wife_id', $mother['id'])->exists());

        // پدر دوباره قابل افزودن نیست
        $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'father', 'first_name' => 'X'])->assertStatus(422);

        // خواهر
        $sister = $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'sibling', 'first_name' => 'مریم', 'gender' => 'f'])
            ->assertCreated()->json('data');
        $this->assertSame($father['id'], $sister['father_id']);

        // همسر و فرزند
        $wife = $this->postJson("/api/persons/{$myId}/relatives", ['type' => 'spouse', 'first_name' => 'سارا', 'marriage_date' => '۱۳۹۰'])
            ->assertCreated()->json('data');
        $this->assertSame('f', $wife['gender']);
        $child = $this->postJson("/api/persons/{$myId}/relatives", [
            'type' => 'child', 'first_name' => 'رضا', 'gender' => 'm', 'other_parent_id' => $wife['id'], 'birth_date' => '1395/05/10',
        ])->assertCreated()->json('data');
        $this->assertSame($wife['id'], $child['mother_id']);
        $this->assertSame('1395-05-10', $child['birth_date']);

        // درخت نوادگانِ پدر
        $tree = $this->getJson("/api/tree/{$father['id']}/descendants?depth=3")->assertOk()->json();
        $ids = array_column($tree['persons'], 'id');
        foreach ([$father['id'], $mother['id'], $myId, $sister['id'], $wife['id'], $child['id']] as $id) {
            $this->assertContains($id, $ids);
        }
        $this->assertCount(2, $tree['marriages']);

        // عمق ۱: نوه نمایش داده نمی‌شود و من «قابل گسترش» هستم
        $shallow = $this->getJson("/api/tree/{$father['id']}/descendants?depth=1")->json();
        $this->assertNotContains($child['id'], array_column($shallow['persons'], 'id'));
        $this->assertContains($myId, $shallow['meta']['expandable']);

        // نیاکان فرزند
        $anc = $this->getJson("/api/tree/{$child['id']}/ancestors?depth=5")->json();
        $this->assertContains($father['id'], array_column($anc['persons'], 'id'));

        // مسیر نسبی
        $lineage = $this->getJson("/api/tree/lineage?from={$father['id']}&to={$child['id']}")->assertOk()->json();
        $this->assertSame([$father['id'], $myId, $child['id']], $lineage['meta']['path']);

        // نسبت
        $rel = $this->getJson("/api/persons/{$child['id']}/relationship/{$sister['id']}")->json();
        $this->assertSame('عمه', $rel['label']);
        $rel = $this->getJson("/api/persons/{$wife['id']}/relationship/{$father['id']}")->json();
        $this->assertSame('پدرشوهر', $rel['label']);
    }

    public function test_cousin_relationship_labels(): void
    {
        $admin = User::factory()->admin()->withPerson(['gender' => 'm'])->create()->refresh();
        $this->actingAs($admin, 'sanctum');

        $grand = Person::factory()->male()->create();
        $uncle = Person::factory()->male()->create(['father_id' => $grand->id]);
        $dad = Person::factory()->male()->create(['father_id' => $grand->id]);
        $aunt = Person::factory()->female()->create(['father_id' => $grand->id]);
        $me = Person::factory()->male()->create(['father_id' => $dad->id]);
        $cousin = Person::factory()->female()->create(['father_id' => $uncle->id]);
        $cousin2 = Person::factory()->male()->create(['mother_id' => $aunt->id]);

        $this->assertSame('دخترعمو', $this->getJson("/api/persons/{$me->id}/relationship/{$cousin->id}")->json('label'));
        $this->assertSame('پسرعمه', $this->getJson("/api/persons/{$me->id}/relationship/{$cousin2->id}")->json('label'));
        $this->assertSame('پدربزرگ پدری', $this->getJson("/api/persons/{$me->id}/relationship/{$grand->id}")->json('label'));
    }

    public function test_edit_permissions_follow_kinship_rules(): void
    {
        $me = $this->member(['gender' => 'm']);
        $sibling = Person::factory()->female()->create();
        $stranger = $this->member();

        $father = Person::factory()->male()->deceased()->create();
        $me->person->forceFill(['father_id' => $father->id])->save();
        $sibling->forceFill(['father_id' => $father->id])->save();

        $this->actingAs($me, 'sanctum');
        // خواهر/برادر قابل ویرایش است
        $this->patchJson("/api/persons/{$sibling->id}", ['nickname' => 'گلی'])->assertOk();
        // پدر درگذشته توسط فرزند قابل ویرایش است
        $this->patchJson("/api/persons/{$father->id}", ['burial_place' => 'قم'])->assertOk();
        // غریبه قابل ویرایش نیست
        $this->patchJson("/api/persons/{$stranger->person_id}", ['nickname' => 'x'])->assertForbidden();

        // نوه‌ی شخص درگذشته هم حق ویرایش دارد
        $grandchild = $this->member(['gender' => 'f']);
        $grandchild->person->forceFill(['father_id' => $me->person_id])->save();
        $this->actingAs($grandchild->refresh(), 'sanctum');
        $this->patchJson("/api/persons/{$father->id}", ['occupation' => 'کشاورز'])->assertOk();
    }

    public function test_sensitive_fields_are_hidden_from_relatives_of_claimed_profiles(): void
    {
        $me = $this->member(['gender' => 'm']);
        $sister = $this->member(['gender' => 'f']);
        $father = Person::factory()->male()->create();
        $me->person->forceFill(['father_id' => $father->id])->save();
        $sister->person->forceFill(['father_id' => $father->id])->save();
        $sister->person->national_code = '1234567891';
        $sister->person->save();

        $this->actingAs($me->refresh(), 'sanctum');
        $data = $this->getJson("/api/persons/{$sister->person_id}")->assertOk()->json('data');
        $this->assertNull($data['national_code']);
        $this->assertTrue($data['has_national_code']);
        $this->assertTrue($data['permissions']['edit']);
        $this->assertFalse($data['permissions']['sensitive']);

        // تلاش برای تغییر کد ملی نادیده گرفته می‌شود
        $this->patchJson("/api/persons/{$sister->person_id}", ['national_code' => '0499370899'])->assertOk();
        $this->assertSame('1234567891', $sister->person->fresh()->national_code);

        // خود شخص می‌بیند
        $this->actingAs($sister->refresh(), 'sanctum');
        $this->assertSame('1234567891', $this->getJson("/api/persons/{$sister->person_id}")->json('data.national_code'));
    }

    public function test_linking_existing_spouse_from_other_tree_requires_approval(): void
    {
        $me = $this->member(['gender' => 'm']);
        $herBrother = $this->member(['gender' => 'm']);
        $herFather = Person::factory()->male()->create();
        $her = Person::factory()->female()->create(['father_id' => $herFather->id]);
        $herBrother->person->forceFill(['father_id' => $herFather->id])->save();

        $this->actingAs($me, 'sanctum');
        $response = $this->postJson("/api/persons/{$me->person_id}/link", ['type' => 'spouse', 'target_id' => $her->id])
            ->assertStatus(202)->assertJsonPath('status', 'requested');
        $requestId = $response->json('request.data.id') ?? $response->json('request.id');
        $this->assertFalse(Marriage::where('husband_id', $me->person_id)->exists());

        // برادرِ همسر (که حق ویرایش خواهرش را دارد) تأیید می‌کند
        $this->actingAs($herBrother->refresh(), 'sanctum');
        $pending = $this->getJson('/api/link-requests')->assertOk()->json('data');
        $this->assertCount(1, $pending);
        $this->postJson('/api/link-requests/'.$pending[0]['id'].'/accept')->assertOk();

        $this->assertTrue(Marriage::where('husband_id', $me->person_id)->where('wife_id', $her->id)->exists());
        $this->assertSame('accepted', LinkRequest::first()->status);

        // حالا درخت همسر (نیاکانش) قابل مشاهده است
        $anc = $this->getJson("/api/tree/{$her->id}/ancestors")->json();
        $this->assertContains($herFather->id, array_column($anc['persons'], 'id'));
    }

    public function test_cycles_are_prevented(): void
    {
        $admin = User::factory()->admin()->withPerson()->create()->refresh();
        $this->actingAs($admin, 'sanctum');
        $grand = Person::factory()->male()->create();
        $dad = Person::factory()->male()->create(['father_id' => $grand->id]);
        $son = Person::factory()->male()->create(['father_id' => $dad->id]);

        // پسر نمی‌تواند پدرِ پدربزرگش شود
        $this->postJson("/api/persons/{$grand->id}/link", ['type' => 'father', 'target_id' => $son->id])->assertStatus(422);
    }

    public function test_search_and_gedcom_export(): void
    {
        $admin = User::factory()->admin()->withPerson(['first_name' => 'اکبر', 'last_name' => 'کریمی', 'gender' => 'm'])->create()->refresh();
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/persons?q='.urlencode('كريمي'))->assertOk()->assertJsonPath('meta.total', 1);

        $response = $this->get('/api/export/gedcom?mode=all')->assertOk();
        $this->assertStringContainsString('0 HEAD', $response->getContent());
        $this->assertStringContainsString('1 NAME اکبر /کریمی/', $response->getContent());
    }

    public function test_merge_duplicates(): void
    {
        $admin = User::factory()->admin()->withPerson()->create()->refresh();
        $this->actingAs($admin, 'sanctum');
        $a = Person::factory()->male()->create(['birth_place' => null]);
        $b = Person::factory()->male()->create(['birth_place' => 'تبریز']);
        $child = Person::factory()->create(['father_id' => $b->id]);

        $this->postJson("/api/admin/persons/{$a->id}/merge", ['duplicate_id' => $b->id])->assertOk();
        $this->assertSame($a->id, $child->fresh()->father_id);
        $this->assertSame('تبریز', $a->fresh()->birth_place);
        $this->assertSoftDeleted('persons', ['id' => $b->id]);
    }
}
