<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Olts\Pages\ManageOlts;
use App\Filament\App\Resources\Onus\Pages\ListOnus;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\MikrotikRouter;
use App\Models\Olt;
use App\Models\Onu;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OltTest extends TestCase
{
    use RefreshDatabase;

    public function test_olt_settings_and_onu_monitoring(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent)->get("/app/{$company->slug}/olts")->assertForbidden();

        Http::fake(['engine.test/*' => Http::response(['sys_name' => 'TILARDI_POP', 'sys_descr' => 'BDCOM P3608B', 'supported' => true])]);
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(ManageOlts::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'CLN_1_TILARDI', 'brand' => 'bdcom', 'host' => '10.11.12.2',
                'snmp_port' => 161, 'community' => 'public', 'enabled' => true])
            ->assertHasNoFormErrors()->assertNotified('সংযোগ ঠিক আছে: TILARDI_POP');
        $olt = Olt::sole();
        $this->assertSame('public', $olt->community);
        $this->assertNotSame('public', DB::table('olts')->value('community'));

        // ONUs with the customer behind them (router MAC -> PPPoE session -> billing customer)
        $router = MikrotikRouter::create(['company_id' => $company->id, 'host' => 'h', 'api_port' => 8728, 'username' => 'u', 'password' => 'p']);
        $good = Onu::forceCreate(['company_id' => $company->id, 'olt_id' => $olt->id, 'if_index' => 98, 'name' => 'EPON0/5:58', 'online' => true, 'rx_dbm' => -19.7, 'distance_m' => 1075, 'seen_at' => now()]);
        $weak = Onu::forceCreate(['company_id' => $company->id, 'olt_id' => $olt->id, 'if_index' => 99, 'name' => 'EPON0/5:55', 'online' => true, 'rx_dbm' => -28.4, 'seen_at' => now()]);
        $off = Onu::forceCreate(['company_id' => $company->id, 'olt_id' => $olt->id, 'if_index' => 25, 'name' => 'EPON0/1:5', 'online' => false, 'seen_at' => now()]);
        BillingCustomer::create(['company_id' => $company->id, 'header_id' => 5876, 'customer_id' => '5876', 'name' => 'Anondo', 'username' => 'tld.anondo']);
        DB::table('ppp_sessions')->insert(['company_id' => $company->id, 'router_id' => $router->id, 'username' => 'tld.anondo', 'caller_id' => 'cc:ba:bd:1f:e5:35', 'seen_at' => now()]);
        DB::table('customer_onus')->insert(['company_id' => $company->id, 'client_mac' => 'CC:BA:BD:1F:E5:35', 'olt_id' => $olt->id, 'onu_id' => $good->id, 'vlan' => 2333, 'checked_at' => now()]);

        $this->actingAs($agent)->get("/app/{$company->slug}/onus")->assertOk()->assertSee('মোট 3 ONU');
        $this->actingAs($agent);
        Livewire::test(ListOnus::class)
            ->assertCanSeeTableRecords([$good, $weak, $off])->assertSee('Anondo')
            ->filterTable('weak', true)->assertCanSeeTableRecords([$weak])->assertCanNotSeeTableRecords([$good, $off]);
        Livewire::test(ListOnus::class)->filterTable('online', false)->assertCanSeeTableRecords([$off])->assertCanNotSeeTableRecords([$good, $weak]);
        Livewire::test(ListOnus::class)->filterTable('pon', 'EPON0/5')->assertCanSeeTableRecords([$good, $weak])->assertCanNotSeeTableRecords([$off]);
    }
}
