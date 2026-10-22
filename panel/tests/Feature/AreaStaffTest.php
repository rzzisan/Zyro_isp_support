<?php

namespace Tests\Feature;

use App\Filament\App\Resources\AreaStaff\Pages\ListAreaStaff;
use App\Filament\App\Resources\StaffTeams\Pages\ManageStaffTeams;
use App\Models\AreaStaff;
use App\Models\BillingOption;
use App\Models\BotSetting;
use App\Models\Company;
use App\Models\StaffTeam;
use App\Models\Technician;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AreaStaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_own_company_and_owner_sets_auto_assign(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $other = Company::create(['name' => 'Other ISP']);
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $company->users()->attach($agent, ['role' => 'agent']);
        $mk = fn (Company $c, string $box, string $name) => AreaStaff::create(['company_id' => $c->id, 'zone' => 'Minto Vhai',
            'subzone' => 'বাসতলা', 'box' => $box, 'employee_name' => $name, 'tickets' => 3, 'last_complain_id' => '57589',
            'last_worked_at' => now()]);
        $a = $mk($company, 'মসজিদ২', 'Rezaul');
        $b = $mk($company, 'হরিসভা', 'Arif');
        $x = $mk($other, 'মসজিদ২', 'Nazmul');

        $this->actingAs($agent)->get("/app/{$company->slug}/area-staff")->assertOk()->assertSee('Rezaul');
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(ListAreaStaff::class)
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$x])
            ->filterTable('employee_name', 'Arif')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
            ->assertActionHidden('settings');

        BillingOption::create(['company_id' => $company->id, 'kind' => 'employees', 'option_id' => '1', 'name' => 'Zisan Ahammed']);
        BillingOption::create(['company_id' => $company->id, 'kind' => 'categories', 'option_id' => '5', 'name' => 'Line Off']);
        BillingOption::create(['company_id' => $company->id, 'kind' => 'categories', 'option_id' => '7', 'name' => 'Password change']);
        $this->actingAs($owner);
        Livewire::test(ListAreaStaff::class)
            ->call('updateTableColumnState', 'pinned', (string) $a->getKey(), true)
            ->callAction('settings', ['auto_assign' => false, 'auto_assign_exclude' => ['1'], 'auto_assign_area_categories' => ['5'],
                'auto_assign_category_staff' => [['category_id' => '5', 'employees' => ['1']]]])->assertHasActionErrors();
        Livewire::test(ListAreaStaff::class)
            ->callAction('settings', ['auto_assign' => false, 'auto_assign_exclude' => ['1'], 'auto_assign_area_categories' => ['5'],
                'auto_assign_category_staff' => [['category_id' => '7', 'employees' => ['1']]]])->assertHasNoActionErrors();
        $s = BotSetting::where('company_id', $company->id)->first();
        $this->assertFalse($s->auto_assign);
        $this->assertSame(['1'], $s->auto_assign_exclude);
        $this->assertSame(['5'], $s->auto_assign_area_categories);
        $this->assertSame([['category_id' => '7', 'employees' => ['1']]], $s->auto_assign_category_staff);
        $this->assertTrue($a->fresh()->pinned);
    }

    public function test_teams_keep_each_employee_in_one_team(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        foreach (['8' => 'Maruf', '9' => 'Rifat', '13' => 'Rubel'] as $id => $name) {
            BillingOption::create(['company_id' => $company->id, 'kind' => 'employees', 'option_id' => $id, 'name' => $name]);
        }
        foreach (['8' => 'Maruf', '9' => 'Rifat'] as $id => $name) {
            Technician::create(['company_id' => $company->id, 'name' => $name, 'billing_employee_id' => $id,
                'billing_employee_name' => $name, 'active' => false]);
        }
        $this->actingAs($owner)->get("/app/{$company->slug}/staff-teams")->assertOk();
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(ManageStaffTeams::class)
            ->callTableAction('create', data: ['name' => 'Rifat-Maruf', 'members' => ['9', '8']])->assertHasNoTableActionErrors()
            ->callTableAction('create', data: ['name' => 'Rifat-2', 'members' => ['9']])->assertHasTableActionErrors(['members']);
        Livewire::test(ManageStaffTeams::class)  // any billing employee, not only linked technicians
            ->callTableAction('create', data: ['name' => 'Rubel', 'members' => ['13']])->assertHasNoTableActionErrors();
        Livewire::test(ManageStaffTeams::class)  // not a billing employee
            ->callTableAction('create', data: ['name' => 'X', 'members' => ['99']])->assertHasTableActionErrors();
        $this->assertSame([['9', '8'], ['13']], StaffTeam::where('company_id', $company->id)->orderBy('id')->pluck('members')->all());
    }
}
