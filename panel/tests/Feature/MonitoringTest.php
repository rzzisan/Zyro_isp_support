<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Monitoring;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\MikrotikRouter;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_lists_online_offline_per_server_and_actions_call_engine(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $company = Company::create(['name' => 'Century Link Network']);
        $agent = User::factory()->create();
        $company->users()->attach($agent, ['role' => 'agent']);
        $mk = fn ($id, $srv) => BillingCustomer::create(['company_id' => $company->id, 'header_id' => (int) $id, 'customer_id' => $id,
            'username' => "u{$id}", 'name' => "N{$id}", 'server' => $srv, 'status' => 'Active', 'zone' => 'Z']);
        $on = $mk('0001', 'CLNBD');
        $off = $mk('0002', 'CLNBD');
        $other = $mk('0003', 'CLN_4');
        $r = MikrotikRouter::create(['company_id' => $company->id, 'host' => 'h', 'api_port' => 8728, 'username' => 'u', 'password' => 'p', 'identity' => 'CLNBD', 'billing_server' => 'CLNBD']);
        DB::table('ppp_sessions')->insert([
            ['company_id' => $company->id, 'router_id' => $r->id, 'username' => 'u0001', 'address' => '10.1.1.1', 'uptime' => '2h', 'seen_at' => now()],
            ['company_id' => $company->id, 'router_id' => $r->id, 'username' => 'u0002', 'address' => '10.1.1.2', 'uptime' => '1h', 'seen_at' => now()->subHour()],
        ]);
        $this->actingAs($agent)->get("/app/{$company->slug}/monitoring")->assertOk()->assertSee('সব সার্ভার');
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        $page = Livewire::test(Monitoring::class);
        $this->assertSame(['total' => 3, 'online' => 1], $page->instance()->counts()['']);
        $this->assertSame(['total' => 2, 'online' => 1], $page->instance()->counts()['CLNBD']);
        $page->assertCanSeeTableRecords([$on, $off, $other])->assertSee('10.1.1.1')->assertDontSee('10.1.1.2')
            ->call('setServer', 'CLNBD')->assertCanSeeTableRecords([$on, $off])->assertCanNotSeeTableRecords([$other])
            ->filterTable('online', true)->assertCanSeeTableRecords([$on])->assertCanNotSeeTableRecords([$off]);

        Http::fake(['engine.test/*' => Http::response(['online' => true, 'router' => 'CLNBD', 'uptime' => '2h1m', 'address' => '10.1.1.1', 'caller_id' => 'AA'])]);
        Livewire::test(Monitoring::class)->callAction(TestAction::make('recheck')->table($on))->assertNotified('অনলাইন · 2h1m · CLNBD');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), "/internal/{$company->id}/monitor/1/recheck"));
    }

    public function test_zone_subzone_box_filters_apply_and_cascade(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $agent = User::factory()->create();
        $company->users()->attach($agent, ['role' => 'agent']);
        $mk = fn ($id, $z, $sz, $b) => BillingCustomer::create(['company_id' => $company->id, 'header_id' => (int) $id, 'customer_id' => $id,
            'username' => "u{$id}", 'server' => 'CLNBD', 'status' => 'Active', 'zone' => $z, 'subzone' => $sz, 'box' => $b]);
        $a = $mk('1', 'Zisan', 'এনায়েতনগর', 'Box-1');
        $b = $mk('2', 'Zisan', 'ধোপা পাড়া', 'Box-2');
        $c = $mk('3', 'Binodpur', 'বড় বাড়ি', 'Box-3');
        MikrotikRouter::create(['company_id' => $company->id, 'host' => 'h', 'api_port' => 8728, 'username' => 'u', 'password' => 'p']);
        $this->actingAs($agent);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        $page = Livewire::test(Monitoring::class)
            ->filterTable('zone', 'Zisan')->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c]);
        $page->filterTable('subzone', 'ধোপা পাড়া')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a, $c]);
        Livewire::test(Monitoring::class)->filterTable('box', 'Box-3')->assertCanSeeTableRecords([$c])->assertCanNotSeeTableRecords([$a, $b]);
        // subzone choices follow the zone
        $m = Livewire::test(Monitoring::class)->set('tableFilters.zone.value', 'Binodpur');
        $opts = (fn () => $this->scopedOptions('subzone', ['zone']))->call($m->instance());
        $this->assertSame(['বড় বাড়ি' => 'বড় বাড়ি'], $opts);
    }
}
