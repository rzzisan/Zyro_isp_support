<?php

namespace Tests\Feature;

use App\Filament\App\Resources\BillCollections\Pages\ListBillCollections;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BillCollectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private BillingCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        $this->customer = BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 3, 'customer_id' => '0003',
            'name' => 'Bulbul', 'username' => 'en.enamul', 'mobile' => '01714080210', 'monthly_bill' => 525, 'due' => 525,
            'status' => 'Left']);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function fakeEngine(array $pending = [], array $bill = ['message' => 'Succeeded...!!!', 'daily_bill_id' => 170239, 'balance' => 0], int $status = 200): void
    {
        $cid = $this->company->id;
        Http::fake([
            "engine.test/internal/{$cid}/customers/3/bill-form*" => Http::response([
                'username' => 'en.enamul', 'status' => 'Left', 'monthly' => 525, 'payable' => 525, 'period_id' => '394',
                'bill_month' => 'October', 'received_from' => 'Bulbul', 'received_by' => ['1' => 'Zisan Ahammed', '3' => 'Arif'],
                'received_by_default' => '1', 'methods' => ['71' => 'Cash', '72' => 'bKash'], 'method_default' => '71',
                'pending' => $pending]),
            "engine.test/internal/{$cid}/customers/3/bill" => Http::response($bill, $status),
        ]);
    }

    public function test_owner_collects_full_bill_from_customer_page(): void
    {
        $this->fakeEngine();
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->assertActionVisible('receiveBill')
            ->mountAction('receiveBill')
            ->assertSchemaStateSet(['paid' => 525.0, 'method_id' => '71', 'received_by_id' => '1', 'bill_month' => 'October', 'sms' => true], 'mountedActionSchema0')
            ->callMountedAction()->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/customers/3/bill"
            && $r->hasHeader('x-internal-key', 'k') && $r['paid'] == 525 && $r['discount'] == 0 && $r['method_id'] === '71'
            && $r['received_by_id'] === '1' && $r['sms'] === true && $r['next_billing_date'] === false
            && $r['expected_payable'] == 525 && $r['user_id'] === $this->owner->id);
    }

    public function test_partial_payment_needs_a_note(): void
    {
        $this->fakeEngine();
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->callAction('receiveBill', ['paid' => 300, 'remarks' => ''])
            ->assertHasActionErrors(['remarks' => 'required']);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/customers/3/bill'));
    }

    public function test_engine_refusal_is_shown(): void
    {
        $this->fakeEngine([], ['detail' => 'এখনো approve হয়নি'], 400);
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->callAction('receiveBill')->assertNotified('বিল নেওয়া হয়নি');
    }

    public function test_agent_needs_bills_permission(): void
    {
        $this->fakeEngine();
        $this->as($this->agent);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])->assertActionHidden('receiveBill');
        $this->get("/app/{$this->company->slug}/bill-collections")->assertForbidden();

        $this->company->users()->updateExistingPivot($this->agent->id, ['permissions' => json_encode(['customers', 'bills'])]);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])->assertActionVisible('receiveBill');
    }

    public function test_log_lists_only_own_company(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        $mk = fn (Company $c, string $result) => BillCollection::create(['company_id' => $c->id, 'user_id' => $this->owner->id,
            'customer_header_id' => 3, 'customer_id' => '0003', 'customer_name' => 'Bulbul', 'paid' => 525, 'result' => $result]);
        $a = $mk($this->company, 'saved');
        $b = $mk($this->company, 'blocked');
        $x = $mk($other, 'saved');
        $this->as($this->owner);
        $this->get("/app/{$this->company->slug}/bill-collections")->assertOk()->assertSee('Bulbul');
        Livewire::test(ListBillCollections::class)
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$x])
            ->filterTable('result', 'saved')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }
}
