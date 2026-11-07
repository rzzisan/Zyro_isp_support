<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingWaivers;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\DeskCustomer;
use App\Models\DeskWaiver;
use App\Models\Membership;
use App\Models\User;
use App\Support\Ledger;
use App\Support\Statement;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** In-house billing phase 4c: the waive list, the Desk payment receipt and the customer statement. */
class DeskBillingStatementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private int $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        Membership::where('company_id', $this->company->id)->where('user_id', $this->agent->id)
            ->update(['permissions' => json_encode(['tickets'])]);
        BillingSetting::forCompany($this->company)->fill(['ledger_control' => 'test', 'ledger_test_codes' => ['0001']])->save();
        $c = $this->company->id;
        BillingCustomer::create(['company_id' => $c, 'header_id' => 1, 'customer_id' => '0001', 'name' => 'Zisan Home', 'username' => 'u0001',
            'status' => 'Active', 'monthly_bill' => 500]);
        $this->a = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 1, 'customer_code' => '0001',
            'username' => 'u0001', 'name' => 'Zisan Home', 'status' => 'Active', 'monthly_bill' => 500, 'mobile' => '01700000000']);
        DB::table('desk_customer_balances')->insert(['customer_ref' => $this->a, 'company_id' => $c, 'balance' => 1200, 'has_history' => true]);
        $sep = DB::table('desk_bills')->insertGetId(['company_id' => $c, 'customer_ref' => $this->a, 'period_start' => '2026-09-01',
            'period_end' => '2026-09-30', 'amount' => 500, 'status' => 'open', 'source' => 'ispdigital', 'ext_id' => 1]);
        $oct = DB::table('desk_bills')->insertGetId(['company_id' => $c, 'customer_ref' => $this->a, 'period_start' => '2026-10-01',
            'period_end' => '2026-10-31', 'amount' => 500, 'status' => 'open', 'source' => 'ispdigital', 'ext_id' => 2]);
        foreach ([['2026-08-31', 'opening', 200, null, 'o'], ['2026-09-01', 'bill', 500, $sep, 'b1'], ['2026-10-01', 'bill', 500, $oct, 'b2']] as [$d, $k, $amt, $bill, $ref]) {
            DB::table('desk_ledger_entries')->insert(['company_id' => $c, 'customer_ref' => $this->a, 'entry_date' => $d, 'kind' => $k,
                'amount' => $amt, 'bill_ref' => $bill, 'source' => 'ispdigital', 'source_ref' => $ref, 'created_at' => now()]);
        }
        DB::table('desk_waivers')->insert(['company_id' => $c, 'period_start' => '2026-10-01', 'customer_ref' => $this->a, 'bill_ref' => $oct,
            'amount' => 500, 'status' => 'shadow', 'off_since' => '20 Sep 2026']);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_statement_has_a_running_balance(): void
    {
        $p = Ledger::recordCollection(BillingCustomer::first(), ['paid' => 700, 'discount' => 0, 'method' => 'Cash', 'received_by' => 'Arju'],
            ['daily_bill_id' => 9001], $this->owner);
        $d = Statement::data(DeskCustomer::find($this->a), '2026-09-01', now('Asia/Dhaka')->toDateString());
        $this->assertSame(200.0, $d['opening']);
        $this->assertSame([700.0, 1200.0, 500.0], array_column($d['rows'], 'balance'));
        $this->assertSame(500.0, $d['closing']);
        $this->assertSame(['bill', 'bill', 'payment'], array_column($d['rows'], 'kind'));

        $slug = $this->company->slug;
        $this->actingAs($this->agent)->get("/print/{$slug}/statement/0001")->assertForbidden();
        $this->actingAs($this->owner)->get("/print/{$slug}/statement/0001?from=2026-09-01")->assertOk()
            ->assertSee('Zisan Home')->assertSee('1,200.00')->assertSee('Sep 2026');

        // the receipt of the Desk payment: payable was the balance before it (1200), 500 still due
        $this->actingAs($this->owner)->get("/print/{$slug}/desk-payment/{$p->id}?print=0")->assertOk()
            ->assertSee('DP-'.$p->id)->assertSee('1200.00')->assertSee('500.00');
        $this->actingAs($this->agent)->get("/print/{$slug}/desk-payment/{$p->id}")->assertForbidden();
    }

    public function test_waivers_page_lists_the_shadow_month(): void
    {
        $this->as($this->owner);
        $this->get('/app/'.$this->company->slug.'/billing-waivers')->assertOk()->assertSee('Zisan Home')->assertSee('20 Sep 2026')
            ->assertSee('শুধু তালিকা');
        Livewire::test(BillingWaivers::class)->assertCanSeeTableRecords(DeskWaiver::all());
        $this->actingAs($this->agent)->get('/app/'.$this->company->slug.'/billing-waivers')->assertForbidden();
    }
}
