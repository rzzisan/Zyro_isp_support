<?php

namespace Tests\Feature;

use App\Filament\App\Resources\AreaStaff\Pages\ListAreaStaff;
use App\Models\AreaStaff;
use App\Models\BillingOption;
use App\Models\BotSetting;
use App\Models\Company;
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
        $this->actingAs($owner);
        Livewire::test(ListAreaStaff::class)
            ->call('updateTableColumnState', 'pinned', (string) $a->getKey(), true)
            ->callAction('settings', ['auto_assign' => false, 'auto_assign_exclude' => ['1']])->assertHasNoActionErrors();
        $s = BotSetting::where('company_id', $company->id)->first();
        $this->assertFalse($s->auto_assign);
        $this->assertSame(['1'], $s->auto_assign_exclude);
        $this->assertTrue($a->fresh()->pinned);
    }
}
