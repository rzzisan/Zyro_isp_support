<?php

namespace Tests\Feature;

use App\Filament\App\Resources\LineEnables\Pages\ListLineEnables;
use App\Models\Company;
use App\Models\LineEnable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LineEnableTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_lists_own_company_with_filters(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $other = Company::create(['name' => 'Other ISP']);
        $agent = User::factory()->create();
        $company->users()->attach($agent, ['role' => 'agent']);
        $mk = fn (Company $c, string $tech, string $result) => LineEnable::create(['company_id' => $c->id, 'technician_name' => $tech,
            'customer_id' => '5629', 'customer_name' => 'Didar', 'result' => $result, 'request' => '5629 line chalu koro']);
        $a = $mk($company, 'Nazmul', 'enabled');
        $b = $mk($company, 'Arif', 'already_active');
        $x = $mk($other, 'Nazmul', 'enabled');

        $this->actingAs($agent)->get("/app/{$company->slug}/line-enables")->assertOk()->assertSee('Nazmul');
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(ListLineEnables::class)
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$x])
            ->filterTable('technician_name', 'Nazmul')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }
}
