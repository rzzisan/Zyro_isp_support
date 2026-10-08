<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
    }

    private function customer(Company $c, string $id, array $extra = []): BillingCustomer
    {
        return BillingCustomer::create(['company_id' => $c->id, 'header_id' => (int) $id + 1000, 'customer_id' => $id,
            'name' => "Cust {$id}", 'username' => "kp.c{$id}", 'mobile' => '01400016191', 'zone' => 'Minto Vhai',
            'monthly_bill' => 500, 'due' => 1000, 'status' => 'Active', 'pppoe_password' => 'pw'.$id, ...$extra]);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_agent_sees_customers_of_own_company_with_search_and_filters(): void
    {
        $a = $this->customer($this->company, '0976');
        $b = $this->customer($this->company, '1922', ['zone' => 'Zisan', 'due' => 0, 'disabled' => true]);
        $x = $this->customer(Company::create(['name' => 'Other']), '0977');
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/customers")->assertOk();
        $this->as($this->agent);
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$x])
            ->searchTable('976')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->searchTable('')->filterTable('zone', 'Zisan')->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(ListCustomers::class)->filterTable('has_due', true)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
        // agents get neither the password nor the CSV
        Livewire::test(ViewCustomer::class, ['record' => $a->id])->assertActionHidden('password');
        Livewire::test(ListCustomers::class)->assertActionHidden('csv')->assertActionHidden('sync');
        $this->get("/export/{$this->company->slug}/customers.csv")->assertForbidden();
    }

    public function test_left_customers_have_their_own_list_and_stay_out_of_counts(): void
    {
        $a = $this->customer($this->company, '0976');
        $l = $this->customer($this->company, '5618', ['status' => 'Left', 'disabled' => true, 'is_left' => true, 'left_on' => '2026-10-04']);
        $g = $this->customer($this->company, '0100', ['gone_at' => now()]);
        $this->as($this->agent);
        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$l, $g])
            ->assertSee('1 জন কাস্টমার · Left 1 জন')
            ->filterTable('list', 'left')->assertCanSeeTableRecords([$l])->assertCanNotSeeTableRecords([$a, $g])
            ->assertSee('04 Oct 2026')
            ->filterTable('list', 'gone')->assertCanSeeTableRecords([$g])->assertCanNotSeeTableRecords([$a, $l])
            ->filterTable('list', 'all')->assertCanSeeTableRecords([$a, $l, $g]);
        DB::table('billing_customer_changes')->insert(['company_id' => $this->company->id, 'header_id' => $l->header_id,
            'change' => 'left', 'left_on' => '2026-10-04', 'seen_at' => '2026-10-04 06:00:00']);
        Livewire::test(ViewCustomer::class, ['record' => $l->id])->assertSee('Left হয়েছেন · 04 Oct 2026')->assertSee('04 Oct 2026 থেকে');
    }

    public function test_password_is_encrypted_and_viewing_it_is_logged(): void
    {
        $a = $this->customer($this->company, '0976');
        $this->assertNotSame('pw0976', DB::table('billing_customers')->where('id', $a->id)->value('pppoe_password'));
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $a->id])->assertActionVisible('password')->callAction('password');
        $this->assertSame(1, DB::table('customer_password_views')->where('user_id', $this->owner->id)->where('customer_id', '0976')->count());
    }

    public function test_csv_for_owner_without_passwords(): void
    {
        $this->customer($this->company, '0976');
        $this->customer(Company::create(['name' => 'Other']), '0977');
        $csv = $this->actingAs($this->owner)->get("/export/{$this->company->slug}/customers.csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Cust 0976', $csv);
        $this->assertStringNotContainsString('Cust 0977', $csv);
        $this->assertStringNotContainsString('pw0976', $csv);
    }

    public function test_live_state_comes_from_engine(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $a = $this->customer($this->company, '0976');
        Http::fake(['engine.test/*' => Http::response(['customer' => ['disabled' => true], 'pppoe' => ['connectivity' => 'Disconnected'],
            'bill' => ['due' => '1000.00', 'payment_status' => 'Due (1000.00 ৳)'], 'onu' => null, 'payments' => []])]);
        $this->as($this->agent);
        Livewire::test(ViewCustomer::class, ['record' => $a->id])->assertActionVisible('live');
        $live = \App\Services\Engine::customerLive($this->company->id, 1976);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), "/internal/{$this->company->id}/customers/1976/live"));
        $html = view('filament.app.customers.live', ['live' => $live, 'error' => null])->render();
        $this->assertStringContainsString('Disconnected', $html);
        $this->assertStringContainsString('বন্ধ', $html);
    }

    public function test_online_comes_from_fresh_mikrotik_sessions(): void
    {
        $a = $this->customer($this->company, '0976');
        $b = $this->customer($this->company, '1922');
        $other = Company::create(['name' => 'Other']);
        $router = \App\Models\MikrotikRouter::create(['company_id' => $this->company->id, 'host' => '10.0.0.1', 'api_port' => 8728,
            'username' => 'ro', 'password' => 'x', 'identity' => 'CLNBD']);
        $otherRouter = \App\Models\MikrotikRouter::create(['company_id' => $other->id, 'host' => '10.0.0.2', 'api_port' => 8728, 'username' => 'ro', 'password' => 'x']);
        DB::table('ppp_sessions')->insert([
            ['company_id' => $this->company->id, 'router_id' => $router->id, 'username' => 'kp.c0976', 'uptime' => '1h2m', 'seen_at' => now()],
            ['company_id' => $this->company->id, 'router_id' => $router->id, 'username' => 'kp.c1922', 'uptime' => '9h', 'seen_at' => now()->subMinutes(30)], // stale
            ['company_id' => $other->id, 'router_id' => $otherRouter->id, 'username' => 'kp.c1922', 'uptime' => '1m', 'seen_at' => now()], // other company
        ]);
        $this->as($this->agent);
        Livewire::test(ListCustomers::class)->assertSee('1h2m')
            ->filterTable('online', true)->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->filterTable('online', false)->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
        Livewire::test(\App\Filament\App\Widgets\NetworkStats::class)->assertOk();
        // a PPPoE user that is not in billing is never counted
        DB::table('ppp_sessions')->insert(['company_id' => $this->company->id, 'router_id' => $router->id, 'username' => 'not.in.billing', 'seen_at' => now()]);
        $w = new \App\Filament\App\Widgets\NetworkStats;
        $stats = (fn () => $this->getStats())->call($w);
        $this->assertSame('1', (string) $stats[0]->getValue());
    }
}
