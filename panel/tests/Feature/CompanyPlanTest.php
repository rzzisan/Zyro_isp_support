<?php

namespace Tests\Feature;

use App\Filament\Super\Resources\Companies\Pages\CreateCompany;
use App\Filament\Super\Resources\Companies\Pages\EditCompany;
use App\Filament\Super\Resources\Companies\RelationManagers\UsersRelationManager;
use App\Filament\Super\Resources\Plans\Pages\CreatePlan;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyPlanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $super = User::factory()->create();
        $super->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($super);
        Filament::setCurrentPanel('super');
    }

    public function test_super_admin_creates_a_plan(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'name' => 'Starter', 'price_monthly' => 1500, 'max_agents' => 3,
                'max_whatsapp_numbers' => 1, 'trial_days' => 14, 'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('plans', ['name' => 'Starter', 'price_monthly' => 1500, 'trial_days' => 14]);
    }

    public function test_company_with_new_owner_gets_trial_and_owner_can_log_in_to_app(): void
    {
        $plan = Plan::create(['name' => 'Starter', 'price_monthly' => 1500, 'max_agents' => 3, 'trial_days' => 14]);

        Livewire::test(CreateCompany::class)
            ->fillForm([
                'name' => 'Century Link Network', 'plan_id' => $plan->id, 'status' => 'trial',
                'owner_name' => 'Owner One', 'owner_email' => 'owner@example.com', 'owner_password' => 'secret-pass-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::where('name', 'Century Link Network')->firstOrFail();
        $this->assertSame('century-link-network', $company->slug);
        $this->assertSame($plan->id, $company->plan_id);
        $this->assertTrue($company->trial_ends_at->between(now()->addDays(13), now()->addDays(15)));

        $owner = User::where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('owner', $owner->roleIn($company));
        $this->assertFalse((bool) $owner->is_super_admin);

        $this->actingAs($owner)->get('/app/'.$company->slug)->assertOk();
        $this->actingAs($owner)->get('/super')->assertForbidden();
    }

    public function test_existing_user_is_attached_as_owner(): void
    {
        $existing = User::factory()->create(['email' => 'boss@example.com']);

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'ISP Two', 'status' => 'active', 'owner_email' => 'boss@example.com'])
            ->call('create')
            ->assertHasNoFormErrors();

        $company = Company::where('name', 'ISP Two')->firstOrFail();
        $this->assertSame('owner', $existing->roleIn($company));
        $this->assertSame(1, User::where('email', 'boss@example.com')->count());
    }

    public function test_new_owner_without_password_is_rejected(): void
    {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'ISP Three', 'status' => 'trial', 'owner_name' => 'X', 'owner_email' => 'new@example.com'])
            ->call('create')
            ->assertHasErrors();

        $this->assertDatabaseMissing('companies', ['name' => 'ISP Three']);
    }

    public function test_plan_seat_limit_blocks_extra_users(): void
    {
        $plan = Plan::create(['name' => 'Solo', 'max_agents' => 1]);
        $company = Company::create(['name' => 'Tiny ISP', 'plan_id' => $plan->id]);
        $company->users()->attach(User::factory()->create(), ['role' => 'owner']);
        $extra = User::factory()->create();

        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
            ->callAction(\Filament\Actions\Testing\TestAction::make('attach')->table(), ['recordId' => $extra->id, 'role' => 'agent']);

        $this->assertSame(1, $company->users()->count());
        $this->assertSame(0, $company->seatsLeft());
    }

    public function test_user_can_be_added_while_seats_remain(): void
    {
        $plan = Plan::create(['name' => 'Team', 'max_agents' => 3]);
        $company = Company::create(['name' => 'Team ISP', 'plan_id' => $plan->id]);
        $company->users()->attach(User::factory()->create(), ['role' => 'owner']);
        $extra = User::factory()->create();

        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
            ->callAction(\Filament\Actions\Testing\TestAction::make('attach')->table(), ['recordId' => $extra->id, 'role' => 'agent']);

        $this->assertSame(2, $company->users()->count());
        $this->assertSame('agent', $extra->roleIn($company));
    }

    public function test_super_pages_render(): void
    {
        $plan = Plan::create(['name' => 'Pro', 'max_agents' => 10]);
        $company = Company::create(['name' => 'Render ISP', 'plan_id' => $plan->id]);

        $this->get('/super/plans')->assertOk()->assertSee('Pro');
        $this->get('/super/companies')->assertOk()->assertSee('Render ISP');
        $this->get('/super/companies/'.$company->id.'/edit')->assertOk();
        $this->get('/super/plans/create')->assertOk();
        $this->get('/super/companies/create')->assertOk();
    }
}
