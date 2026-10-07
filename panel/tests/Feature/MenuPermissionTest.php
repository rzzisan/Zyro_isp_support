<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Models\Company;
use App\Models\Membership;
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
}
