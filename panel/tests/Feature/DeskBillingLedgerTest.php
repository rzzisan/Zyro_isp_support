<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingLedger;
use App\Filament\App\Pages\BillingLedgerBills;
use App\Filament\App\Pages\BillingRules;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\DeskBill;
use App\Models\DeskCustomer;
use App\Models\DeskPayment;
use App\Models\Membership;
use App\Models\User;
use App\Support\Ledger;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/** In-house billing phase 4b: the Desk writes its own ledger (payments from the bill form, cancel, transfer, bill edit). */
class DeskBillingLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private int $a;

    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        Membership::where('company_id', $this->company->id)->where('user_id', $this->agent->id)
            ->update(['permissions' => json_encode(['customers', 'bills'])]);
        BillingSetting::forCompany($this->company)->fill(['ledger_control' => 'test', 'ledger_test_codes' => ['0001', '0002']])->save();
        $c = $this->company->id;
        foreach ([[1, '0001', 'Zisan Home'], [2, '0002', 'Office'], [3, '0003', 'Rahim']] as [$h, $code, $name]) {
            BillingCustomer::create(['company_id' => $c, 'header_id' => $h, 'customer_id' => $code, 'name' => $name, 'username' => 'u'.$code,
                'status' => 'Active', 'monthly_bill' => 500]);
            $ref = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => $h, 'customer_code' => $code,
                'username' => 'u'.$code, 'name' => $name, 'status' => 'Active', 'monthly_bill' => 500]);
            DB::table('desk_customer_balances')->insert(['customer_ref' => $ref, 'company_id' => $c, 'balance' => 500, 'has_history' => true]);
            DB::table('desk_bills')->insert(['company_id' => $c, 'customer_ref' => $ref, 'period_start' => now('Asia/Dhaka')->startOfMonth()->toDateString(),
                'period_end' => now('Asia/Dhaka')->endOfMonth()->toDateString(), 'amount' => 500, 'status' => 'open', 'source' => 'ispdigital', 'ext_id' => $h * 10]);
        }
        $this->a = (int) DB::table('desk_customers')->where('customer_code', '0001')->value('id');
        $this->b = (int) DB::table('desk_customers')->where('customer_code', '0002')->value('id');
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function balance(string $code): float
    {
        return (float) DB::table('desk_customer_balances')->join('desk_customers', 'desk_customers.id', '=', 'desk_customer_balances.customer_ref')
            ->where('customer_code', $code)->value('balance');
    }

    private function collect(string $code, float $paid = 500, float $discount = 0): ?DeskPayment
    {
        $b = BillingCustomer::where('customer_id', $code)->first();

        return Ledger::recordCollection($b, ['paid' => $paid, 'discount' => $discount, 'method' => 'Cash', 'received_by' => 'Arju'],
            ['daily_bill_id' => 9000 + (int) $b->header_id, 'collection_id' => 5], $this->owner);
    }

    public function test_the_bill_form_money_goes_into_the_ledger_only_for_test_accounts(): void
    {
        $p = $this->collect('0001', 450, 50);
        $this->assertSame(['desk', 'approved', 9001], [$p->source, $p->status, (int) $p->daily_bill_id]);
        $this->assertSame(0.0, $this->balance('0001'));
        $this->assertSame(['payment' => -450.0, 'discount' => -50.0], DB::table('desk_ledger_entries')->where('customer_ref', $this->a)
            ->pluck('amount', 'kind')->map(fn ($v) => (float) $v)->all());
        $this->assertNull($this->collect('0003'));          // not a test account: nothing in the Desk's ledger
        $this->assertSame(500.0, $this->balance('0003'));

        BillingSetting::where('company_id', $this->company->id)->update(['ledger_control' => 'off']);
        $this->assertNull($this->collect('0002'));
    }

    public function test_pending_when_the_desk_does_not_approve_at_once(): void
    {
        BillingSetting::where('company_id', $this->company->id)->update(['desk_auto_approve' => false]);
        $p = $this->collect('0001');
        $this->assertSame('pending', $p->status);
        $this->assertSame(500.0, $this->balance('0001'));
    }

    public function test_cancel_a_cash_payment_with_a_reason(): void
    {
        $p = $this->collect('0001');
        $this->as($this->agent);
        $this->assertFalse(Ledger::canCancel($p, $this->agent));               // no payment_delete
        $this->actingAs($this->agent)->get('/app/'.$this->company->slug.'/billing-ledger')->assertForbidden();

        $this->as($this->owner);
        $this->get('/app/'.$this->company->slug.'/billing-ledger?customer=0001')->assertOk()->assertSee('Zisan Home')->assertSee('Daily Bill #9001');
        Livewire::test(BillingLedger::class)
            ->callAction(TestAction::make('cancel')->table($p), ['reason' => 'ভুল কাস্টমারে নেওয়া'])->assertHasNoActionErrors();
        $p->refresh();
        $this->assertSame(['canceled', 'ভুল কাস্টমারে নেওয়া'], [$p->status, $p->cancel_reason]);
        $this->assertSame(500.0, $this->balance('0001'));
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'billing.payment_cancel')->count());
    }

    public function test_billing_copies_and_online_payments_cannot_be_canceled_before_the_cutover(): void
    {
        $copy = DeskPayment::create(['company_id' => $this->company->id, 'customer_ref' => $this->a, 'paid_on' => now()->toDateString(),
            'amount' => 500, 'status' => 'approved', 'source' => 'ispdigital', 'ext_id' => 1]);
        $online = DeskPayment::create(['company_id' => $this->company->id, 'customer_ref' => $this->a, 'paid_on' => now()->toDateString(),
            'amount' => 500, 'status' => 'approved', 'source' => 'desk', 'is_online' => true, 'trx_id' => 'BK1']);
        $this->assertFalse(Ledger::canCancel($copy, $this->owner));
        $this->assertFalse(Ledger::canTransfer($copy, $this->owner));
        $this->assertFalse(Ledger::canCancel($online, $this->owner));
        $this->assertTrue(Ledger::canTransfer($online, $this->owner));
        $this->expectExceptionMessage('বিলিং-এই ঠিক করুন');
        Ledger::transfer($copy, DeskCustomer::find($this->b), 'x', $this->owner);
    }

    public function test_transfer_moves_the_money_to_the_right_id(): void
    {
        $p = $this->collect('0001');
        $this->as($this->owner);
        Livewire::test(BillingLedger::class)
            ->callAction(TestAction::make('transfer')->table($p), ['to' => $this->b, 'reason' => 'ভুল ID'])->assertHasNoActionErrors();
        $this->assertSame([500.0, 0.0], [$this->balance('0001'), $this->balance('0002')]);
        $new = DeskPayment::where('transfer_of', $p->id)->sole();
        $this->assertSame([$this->b, 'approved'], [(int) $new->customer_ref, $new->status]);
        $this->assertSame(['transferred', $this->b], [$p->fresh()->status, (int) $p->fresh()->transfer_to_ref]);
        // not to a customer outside the test list
        $c = DeskCustomer::where('customer_code', '0003')->first();
        $this->expectExceptionMessage('পরীক্ষার তালিকায় নেই');
        Ledger::transfer($new, $c, 'x', $this->owner);
    }

    public function test_bill_edit_and_void_keep_the_old_amount(): void
    {
        $bill = DeskBill::where('customer_ref', $this->a)->sole();
        $this->as($this->owner);
        Livewire::test(BillingLedgerBills::class)
            ->callAction(TestAction::make('edit')->table($bill), ['void' => false, 'amount' => 300, 'reason' => 'অর্ধেক মাস'])->assertHasNoActionErrors();
        $this->assertSame(300.0, $this->balance('0001'));
        $this->assertSame(300.0, (float) $bill->fresh()->amount);
        $v = DB::table('desk_bill_versions')->where('bill_ref', $bill->id)->sole();
        $this->assertSame([500.0, 300.0, 'অর্ধেক মাস'], [(float) $v->old_amount, (float) $v->new_amount, $v->reason]);

        Livewire::test(BillingLedgerBills::class)
            ->callAction(TestAction::make('edit')->table($bill->fresh()), ['void' => true, 'reason' => 'সংযোগ ছিল না'])->assertHasNoActionErrors();
        $this->assertSame(['void', 0.0], [$bill->fresh()->status, $this->balance('0001')]);

        $other = DeskBill::where('customer_ref', '<>', $this->a)->where('customer_ref', '<>', $this->b)->sole();
        $this->assertFalse(Ledger::canEditBill($other, $this->owner));        // 0003 is not a test account
    }

    public function test_the_setting_needs_the_desk_as_main_for_everyone(): void
    {
        $this->as($this->owner);
        Livewire::test(BillingRules::class)->fillForm(['ledger_control' => 'all'])->call('save');
        $this->assertSame('test', BillingSetting::where('company_id', $this->company->id)->value('ledger_control'));
    }
}
