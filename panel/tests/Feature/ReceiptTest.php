<?php

namespace Tests\Feature;

use App\Filament\App\Pages\ReceiptSettings;
use App\Filament\App\Resources\BillCollections\Pages\ListBillCollections;
use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\ReceiptSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private BillCollection $bill;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 15605, 'customer_id' => '5604',
            'name' => 'Sharmin', 'username' => 'gn.sharmin', 'mobile' => '01924256498', 'monthly_bill' => 520, 'due' => 0]);
        $this->bill = BillCollection::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id,
            'customer_header_id' => 15605, 'customer_id' => '5604', 'customer_name' => 'Sharmin', 'username' => 'gn.sharmin',
            'payable' => 520, 'paid' => 520, 'discount' => 0, 'payment_method' => 'Cash', 'received_by' => 'Zisan Ahammed',
            'result' => 'saved', 'created_at' => '2026-10-11 14:00:09']);
        ReceiptSetting::create(['company_id' => $this->company->id, 'company_name' => 'Century Link Network',
            'address' => 'Rikabibazar, Mirkadim, Munshiganj Sadar', 'mobile' => '09613009900,01777858289',
            'qr_link' => 'https://qr.bka.sh/2810140566fjB6jm', 'qr_note' => ReceiptSetting::QR_NOTE,
            'footer' => "WhatsApp helpline number: 01777858289\nবিকাশ পেমেন্ট নাম্বার 01777858289", 'paper_width' => 80]);
    }

    private function url(BillCollection $b, string $q = ''): string
    {
        return "/print/{$this->company->slug}/receipt/{$b->id}".$q;
    }

    private function fakeReceiptNo(?string $no): void
    {
        Http::fake(["engine.test/internal/{$this->company->id}/bill-collections/*" => Http::response(['receipt_no' => $no])]);
    }

    public function test_owner_prints_receipt_with_billing_number(): void
    {
        $this->fakeReceiptNo('5604-202610-1119-b39ceae1RV');
        $this->actingAs($this->owner)->get($this->url($this->bill))->assertOk()
            ->assertSee('MONEY RECEIPT')->assertSee('Century Link Network')->assertSee('Mobile: 09613009900,01777858289')
            ->assertSee('5604')->assertSee('Sharmin')->assertSee('gn.sharmin')->assertSee('01924256498')
            ->assertSee('5604-202610-1119-b39ceae1RV')->assertSee('11 Oct 2026, 08:00 PM')->assertSee('Cash')
            ->assertSee('520.00')->assertSee('Zisan Ahammed')->assertSee('WhatsApp helpline number: 01777858289')
            ->assertSee('<svg', false)->assertSee('window.print', false)->assertDontSee('approve বাকি');
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/bill-collections/{$this->bill->id}/receipt-no");
        // a number already kept is used without asking the engine again
        $this->bill->update(['money_receipt_no' => 'KEPT-1RV']);
        Http::fake(['engine.test/*' => Http::response(['detail' => 'should not be called'], 500)]);
        $this->actingAs($this->owner)->get($this->url($this->bill))->assertOk()->assertSee('KEPT-1RV');
    }

    public function test_before_approval_it_prints_desk_number(): void
    {
        $this->fakeReceiptNo(null);
        $this->actingAs($this->owner)->get($this->url($this->bill, '?w=58&print=0'))->assertOk()
            ->assertSee('D-'.$this->bill->id)->assertDontSee('approve বাকি')->assertSee('size: 58mm auto', false)
            ->assertDontSee('window.print', false);
    }

    public function test_engine_down_still_prints(): void
    {
        Http::fake(['engine.test/*' => Http::response(['detail' => 'down'], 500)]);
        $this->actingAs($this->owner)->get($this->url($this->bill))->assertOk()->assertSee('D-'.$this->bill->id);
    }

    public function test_access_is_limited(): void
    {
        $this->fakeReceiptNo(null);
        $this->get($this->url($this->bill))->assertForbidden();
        $this->actingAs($this->agent)->get($this->url($this->bill))->assertForbidden(); // agent without bills
        $other = Company::create(['name' => 'Other ISP']);
        $foreign = BillCollection::create(['company_id' => $other->id, 'customer_header_id' => 1, 'paid' => 10, 'result' => 'saved']);
        $this->actingAs($this->owner)->get($this->url($foreign))->assertNotFound();
        $blocked = BillCollection::create(['company_id' => $this->company->id, 'customer_header_id' => 15605, 'paid' => 10, 'result' => 'blocked']);
        $this->actingAs($this->owner)->get($this->url($blocked))->assertNotFound();
    }

    public function test_list_has_receipt_action_for_saved_rows(): void
    {
        $blocked = BillCollection::create(['company_id' => $this->company->id, 'customer_header_id' => 15605, 'paid' => 10, 'result' => 'blocked']);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ListBillCollections::class)
            ->assertTableActionVisible('receipt', $this->bill)->assertTableActionHidden('receipt', $blocked);
    }

    public function test_owner_edits_receipt_settings(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ReceiptSettings::class)
            ->assertSchemaStateSet(['company_name' => 'Century Link Network', 'paper_width' => 80], 'form')
            ->fillForm(['address' => 'Mirkadim', 'paper_width' => 58], 'form')->call('save')->assertHasNoErrors();
        $s = ReceiptSetting::where('company_id', $this->company->id)->first();
        $this->assertSame('Mirkadim', $s->address);
        $this->assertSame(58, $s->paper_width);
    }
}
