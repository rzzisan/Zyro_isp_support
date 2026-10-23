<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Filament\App\Resources\PermissionGroups\Pages\ManagePermissionGroups;
use App\Models\Company;
use App\Models\Membership;
use App\Models\PermissionGroup;
use App\Models\User;
use App\Support\Menu;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MenuPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_limits_an_agents_menus_and_pages_follow(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $admin = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $company->users()->attach($agent, ['role' => 'agent']);
        $company->users()->attach($admin, ['role' => 'admin']);
        $slug = $company->slug;

        // defaults: agent sees work pages, not settings
        $this->actingAs($agent)->get("/app/{$slug}/customers")->assertOk();
        $this->actingAs($agent)->get("/app/{$slug}/tickets")->assertOk();
        $this->actingAs($agent)->get("/app/{$slug}/technicians")->assertForbidden();

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        $m = Membership::where('user_id', $agent->id)->sole();
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('edit')->table($m), ['role' => 'agent', 'permissions' => ['inbox', 'tickets', 'technicians']])
            ->assertHasNoFormErrors();
        $this->assertSame(['inbox', 'tickets', 'technicians'], $m->fresh()->permissions);

        $this->actingAs($agent)->get("/app/{$slug}/customers")->assertForbidden();
        $this->actingAs($agent)->get("/app/{$slug}/inbox")->assertOk();
        $this->actingAs($agent)->get("/app/{$slug}/tickets")->assertOk();
        // a settings menu ticked for an agent still does not open
        $this->actingAs($agent)->get("/app/{$slug}/technicians")->assertForbidden();
        // dashboard and own billing login stay open
        $this->actingAs($agent)->get("/app/{$slug}")->assertOk();
        $this->actingAs($agent)->get("/app/{$slug}/my-billing-login")->assertOk();

        // admin can be limited too; owner always sees everything
        Membership::where('user_id', $admin->id)->update(['permissions' => json_encode(['inbox'])]);
        $this->actingAs($admin)->get("/app/{$slug}/technicians")->assertForbidden();
        $this->actingAs($admin)->get("/app/{$slug}/inbox")->assertOk();
        $this->assertTrue(Menu::allows($owner, $company, 'technicians'));
    }

    public function test_a_saved_list_with_the_old_my_jobs_menu_still_saves(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $company->users()->attach($agent, ['role' => 'agent', 'permissions' => json_encode(['inbox', 'field_jobs'])]);
        $group = PermissionGroup::create(['company_id' => $company->id, 'name' => 'Technisian', 'permissions' => ['tickets', 'field_jobs']]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        $m = Membership::where('user_id', $agent->id)->sole();
        Livewire::test(ManageMemberships::class)->callAction(TestAction::make('edit')->table($m))->assertHasNoFormErrors();
        $this->assertSame(['inbox'], $m->fresh()->permissions);
        Livewire::test(ManagePermissionGroups::class)->callAction(TestAction::make('edit')->table($group))->assertHasNoFormErrors();
        $this->assertSame(['tickets'], $group->fresh()->permissions);

        (include database_path('migrations/2026_10_23_000006_drop_field_jobs_from_menu_lists.php'))->up();
    }

    public function test_group_menus_reach_every_member_of_the_group(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $other = Company::create(['name' => 'Other ISP']);
        $owner = User::factory()->create();
        $tech = User::factory()->create();
        $tech2 = User::factory()->create();
        $mixed = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        foreach ([$tech, $tech2, $mixed] as $u) {
            $company->users()->attach($u, ['role' => 'agent']);
        }
        $slug = $company->slug;
        // own list of a member keeps working and adds to the group's menus
        Membership::where('user_id', $mixed->id)->update(['permissions' => json_encode(['inbox'])]);

        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        $ids = Membership::whereIn('user_id', [$tech->id, $tech2->id, $mixed->id])->pluck('id')->all();
        Livewire::test(ManagePermissionGroups::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'টেকনিশিয়ান', 'permissions' => ['tickets', 'onus'], 'memberships' => $ids])
            ->assertHasNoFormErrors();
        $group = PermissionGroup::sole();
        $this->assertSame($company->id, $group->company_id);
        $this->assertCount(3, $group->memberships);

        // in a group with no own list: only the group's menus, not the agent defaults
        $this->actingAs($tech)->get("/app/{$slug}/tickets")->assertOk();
        $this->actingAs($tech)->get("/app/{$slug}/customers")->assertForbidden();
        $this->actingAs($tech)->get("/app/{$slug}/inbox")->assertForbidden();
        $this->actingAs($mixed)->get("/app/{$slug}/inbox")->assertOk();
        $this->actingAs($mixed)->get("/app/{$slug}/tickets")->assertOk();
        $this->assertSame(['inbox', 'tickets', 'onus'], Menu::keys(Membership::where('user_id', $mixed->id)->sole()));

        // the app API sees the same menus
        $menus = $this->actingAs($tech2, 'sanctum')->getJson('/api/v1/me')->assertOk()->json('companies.0.menus');
        $this->assertSame(['tickets', 'onus'], $menus);

        // changing the group changes it for everyone; another company never sees it
        $group->update(['permissions' => ['customers']]);
        $this->actingAs($tech)->get("/app/{$slug}/customers")->assertOk();
        $this->actingAs($tech2)->get("/app/{$slug}/tickets")->assertForbidden();
        $other->users()->attach($owner, ['role' => 'owner']);
        Filament::setTenant($other);
        $this->actingAs($owner);
        Livewire::test(ManagePermissionGroups::class)->assertCanNotSeeTableRecords([$group]);

        // team page: the edit form shows the groups; saving with no own ticks keeps the own list unset
        Filament::setTenant($company);
        $m = Membership::where('user_id', $tech->id)->sole();
        Livewire::test(ManageMemberships::class)
            ->mountAction(TestAction::make('edit')->table($m))
            ->assertSchemaStateSet(['groups' => [(string) $group->id], 'permissions' => []], 'mountedActionSchema0')
            ->callMountedAction()->assertHasNoFormErrors();
        $this->assertNull($m->fresh()->permissions);
        // leaving the group with own ticks: just those
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('edit')->table($m), ['role' => 'agent', 'groups' => [], 'permissions' => ['inbox']])
            ->assertHasNoFormErrors();
        $this->assertCount(0, $m->fresh()->groups);
        $this->assertSame(['inbox'], Menu::keys($m->fresh()));
    }
}
