<?php

namespace Tests\Feature;

use App\Filament\App\BillActions;
use App\Filament\App\Resources\BillCollections\Pages\ListBillCollections;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Filament\App\Resources\DailyBills\Pages\ListDailyBills;
use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
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
            ->assertSchemaStateSet(['paid' => 525.0, 'method_id' => '71', 'received_by_id' => '1', 'bill_month' => 'October'], 'mountedActionSchema0')
            ->callMountedAction()->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/customers/3/bill"
            && $r->hasHeader('x-internal-key', 'k') && $r['paid'] == 525 && $r['discount'] == 0 && $r['method_id'] === '71'
            && $r['received_by_id'] === '1' && $r['sms'] === false && $r['next_billing_date'] === true
            && $r['expected_payable'] == 525 && $r['user_id'] === $this->owner->id);
    }

    public function test_collected_bill_queues_the_bill_payment_sms_through_the_desk_gateway(): void
    {
        $this->fakeEngine(bill: ['message' => 'Succeeded...!!!', 'collection_id' => null, 'balance' => 100]);
        Http::fake(['engine.test/internal/*/sms/kick' => Http::response(['started' => true])]);
        $template = SmsTemplate::create(['company_id' => $this->company->id, 'type' => 'bill_paid', 'name' => 'Paid',
            'body' => 'প্রিয় {name}, {months} মাসের {paid} টাকা পেয়েছি ({method}), ছাড় {discount}, বকেয়া {due} (মোট ছিল {payable})। {company}']);
        SmsSetting::forCompany($this->company)->fill(['api_key' => 'a', 'secret_key' => 'b', 'caller_id' => 'CLN',
            'bill_sms' => true, 'bill_template_id' => $template->id])->save();
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->mountAction('receiveBill')
            ->assertSchemaStateSet(['desk_sms' => true], 'mountedActionSchema0')
            ->fillForm(['paid' => 400, 'discount' => 25, 'remarks' => 'partial'])
            ->callMountedAction()->assertHasNoActionErrors();
        // billing's own SMS stays off; the Desk queues its own
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/customers/3/bill') && $r['sms'] === false);
        $m = SmsMessage::sole();
        $this->assertSame('8801714080210', $m->mobile);
        $this->assertSame('প্রিয় Bulbul, October মাসের 400 টাকা পেয়েছি (Cash), ছাড় 25, বকেয়া 100 (মোট ছিল 525)। Century Link Network', $m->text);
        $this->assertSame('bill', $m->campaign->kind);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), "/internal/{$this->company->id}/sms/kick"));
    }

    public function test_no_desk_sms_when_unticked_or_not_set_up(): void
    {
        $this->fakeEngine();
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->mountAction('receiveBill')
            ->assertSchemaStateSet(['desk_sms' => false], 'mountedActionSchema0')
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_form_shows_live_balance(): void
    {
        $html = view('filament.app.bills.summary', ['payable' => 525, 'discount' => 25, 'paid' => 300])->render();
        foreach (['মোট বকেয়া', '525 টাকা', '− 25 টাকা', '− 300 টাকা', 'বাকি বকেয়া', '200 টাকা'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
        $this->assertStringContainsString('#dc2626', $html);
        $html = view('filament.app.bills.summary', ['payable' => 525, 'discount' => 0, 'paid' => 525])->render();
        $this->assertStringContainsString('0 টাকা', $html);
        $this->assertStringContainsString('#16a34a', $html);
        $html = view('filament.app.bills.summary', ['payable' => 525, 'discount' => 0, 'paid' => 600])->render();
        $this->assertStringContainsString('75 টাকা বেশি নেওয়া হচ্ছে', $html);
    }

    public function test_customer_search_label_has_pppoe_and_place(): void
    {
        $this->customer->update(['zone' => 'Zisan', 'subzone' => 'এনায়েতনগর', 'box' => 'Sudipta']);
        $this->assertSame('ID 0003 · Bulbul · en.enamul · 01714080210 · Zisan / এনায়েতনগর / Sudipta',
            BillingCustomer::searchLabel($this->customer->fresh()));
        $this->assertSame('ID 0007 · Rana · 01700000000 · Zisan · LEFT (04 Oct 2026)', BillingCustomer::searchLabel(
            ['customer_id' => '0007', 'name' => 'Rana', 'username' => '', 'mobile' => '01700000000', 'zone' => 'Zisan',
                'subzone' => null, 'box' => ' ', 'is_left' => true, 'left_on' => '2026-10-04']));
        $this->as($this->owner);
        $results = (fn () => static::searchCustomers('en.enamul'))->call(new BillActions);
        $this->assertSame(['3' => 'ID 0003 · Bulbul · en.enamul · 01714080210 · Zisan / এনায়েতনগর / Sudipta'], $results);
    }

    public function test_typing_amounts_and_picking_a_customer_render_only_the_form(): void
    {
        $this->fakeEngine();
        $this->as($this->owner);
        // customer page: amount fields re-render the live total only (throws if the component can't be found)
        Livewire::test(ViewCustomer::class, ['record' => $this->customer->id])
            ->mountAction('receiveBill')->fillForm(['paid' => 300, 'discount' => 25])
            ->assertSchemaStateSet(['paid' => 300, 'discount' => 25], 'mountedActionSchema0');
        // বিল কালেকশন page: picking a customer fills the form and re-renders just its body
        Livewire::test(ListDailyBills::class)
            ->mountAction('receiveBill')->fillForm(['header_id' => '3'])
            ->assertSchemaStateSet(['paid' => 525.0, 'method_id' => '71', 'received_by_id' => '1'], 'mountedActionSchema0')
            ->fillForm(['paid' => 500, 'discount' => 25]);
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
            'customer_header_id' => 3, 'customer_id' => '0003', 'customer_name' => 'Bulbul', 'paid' => 525, 'payable' => 600, 'result' => $result]);
        $a = $mk($this->company, 'saved');
        $b = $mk($this->company, 'blocked');
        $x = $mk($other, 'saved');
        $this->as($this->owner);
        $this->get("/app/{$this->company->slug}/bill-collections")->assertOk()->assertSee('Bulbul');
        Livewire::test(ListBillCollections::class)
            ->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$x])
            ->assertTableColumnFormattedStateSet('paid', '525 টাকা', $a)->assertTableColumnFormattedStateSet('payable', '600', $a)
            ->filterTable('result', 'saved')->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b]);
    }
}
