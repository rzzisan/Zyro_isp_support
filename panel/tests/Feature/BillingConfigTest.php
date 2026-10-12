<?php

namespace Tests\Feature;

use App\Filament\App\Resources\BillingConfig\BoxResource;
use App\Filament\App\Resources\BillingConfig\Pages\BoxList;
use App\Filament\App\Resources\BillingConfig\Pages\PackageList;
use App\Filament\App\Resources\BillingConfig\Pages\ZoneList;
use App\Models\BillingCustomer;
use App\Models\BillingOption;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BillingConfigTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function option(string $kind, string $id, string $name, array $more = []): BillingOption
    {
        return BillingOption::create(['company_id' => $this->company->id, 'kind' => $kind, 'option_id' => $id, 'name' => $name] + $more);
    }

    public function test_lists_show_only_their_kind_with_customer_counts(): void
    {
        $zisan = $this->option('zones', '84', 'Zisan');
        $other = $this->option('subzones', '89', 'ছাদেরকোনা', ['parent_name' => 'Zisan']);
        $box = $this->option('boxes', '4089', 'বিউটি মাঠ', ['parent_name' => 'ছাদেরকোনা', 'zone_name' => 'Zisan']);
        $gone = $this->option('zones', '1', 'Old zone', ['active' => false]);
        foreach ([['1', false], ['2', false], ['3', true]] as [$id, $left]) {
            BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => $id, 'customer_id' => $id,
                'zone' => 'Zisan', 'subzone' => 'ছাদেরকোনা', 'box' => 'বিউটি মাঠ', 'is_left' => $left]);
        }
        $this->as($this->owner);

        Livewire::test(ZoneList::class)->assertCanSeeTableRecords([$zisan])->assertCanNotSeeTableRecords([$other, $gone])
            ->assertTableColumnStateSet('customers', 2, $zisan);
        Livewire::test(BoxList::class)->assertCanSeeTableRecords([$box])->assertTableColumnStateSet('customers', 2, $box);
        $this->assertStringContainsString('billing-config/boxes', BoxResource::getUrl('index'));
    }

    public function test_sync_button_asks_the_engine_for_that_list(): void
    {
        Http::fake(['*/billing-config/sync*' => Http::response(['packages' => ['total' => 18, 'inactive' => 0]])]);
        $this->as($this->owner);
        Livewire::test(PackageList::class)->callAction('sync')->assertNotified();
        Http::assertSent(fn ($r) => str_contains($r->url(), "/internal/{$this->company->id}/billing-config/sync?kind=packages"));
    }

    public function test_agents_cannot_open_the_lists(): void
    {
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->as($agent);
        $this->assertFalse(BoxResource::canAccess());
    }

    public function test_names_merge_the_list_with_values_in_use(): void
    {
        $this->option('zones', '84', 'Zisan');
        $this->option('zones', '85', 'Minto Vhai');
        $this->option('zones', '1', 'Old', ['active' => false]);
        $this->assertSame(['Minto Vhai' => 'Minto Vhai', 'Ticket only' => 'Ticket only', 'Zisan' => 'Zisan'],
            BillingOption::names($this->company->id, 'zones', ['Ticket only' => 'Ticket only', 'Zisan' => 'Zisan']));
    }
}
