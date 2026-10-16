<?php

namespace Tests\Feature;

use App\Filament\App\Resources\NewConnections\Pages\ManageNewConnections;
use App\Filament\App\Resources\OnuAssignments\Pages\ManageOnuAssignments;
use App\Filament\App\Resources\OnuBrands\Pages\ManageOnuBrands;
use App\Filament\App\Resources\StockProducts\Pages\ManageStockProducts;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\NewConnection;
use App\Models\OnuAssignment;
use App\Models\OnuBrand;
use App\Models\OnuStockEntry;
use App\Models\ReceiptSetting;
use App\Models\StockMovement;
use App\Models\StockProduct;
use App\Models\Technician;
use App\Models\User;
use App\Support\Onu;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** ONU stock module (moved from zisan.me/onu): access, ONU form + New Connection, due, other stock, stickers, import. */
class OnuStockTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network', 'modules' => ['onu']]);
        $this->owner = User::factory()->create(['name' => 'Zisan']);
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function brand(string $name = 'Aurora', int $qty = 2): OnuBrand
    {
        $b = OnuBrand::create(['company_id' => $this->company->id, 'name' => $name, 'price' => 850]);
        OnuStockEntry::create(['company_id' => $this->company->id, 'brand_id' => $b->id, 'quantity' => $qty, 'purchase_date' => '2026-10-01']);

        return $b;
    }

    private function customer(string $id = '0976'): BillingCustomer
    {
        return BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => random_int(1000, 99999), 'customer_id' => $id,
            'name' => 'Bulbul', 'mobile' => '01711000111', 'address' => 'Tilardi']);
    }

    public function test_module_and_menu_gate_the_pages(): void
    {
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/onu-assignments")->assertOk();
        $this->company->update(['modules' => []]);
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/onu-assignments")->assertForbidden();
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/new-connections")->assertForbidden();

        $this->company->update(['modules' => ['onu']]);
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent)->get("/app/{$this->company->slug}/stock-products")->assertForbidden(); // agents: off until cutover
        \App\Models\Membership::where('user_id', $agent->id)->update(['permissions' => json_encode(['inbox', 'onu_stock'])]);
        $this->actingAs($agent)->get("/app/{$this->company->slug}/stock-products")->assertOk();
    }

    public function test_mac_and_customer_ids_are_normalized(): void
    {
        $this->assertSame('A28F06053740', Onu::normalizeMac('a2:8f.06-05 37:40'));
        $this->assertSame('A2:8F:06:05:37:40', Onu::displayMac('A28F06053740'));
        $this->assertFalse(Onu::validMac(Onu::normalizeMac('868606023AB')));
        $c = $this->customer('0976');
        $this->assertSame(['0976', $c->id], Onu::resolveCustomer($this->company->id, '976'));
        $this->assertSame(['5555', null], Onu::resolveCustomer($this->company->id, '5555'));
    }

    public function test_new_connection_assignment_saves_the_connection_and_uses_stock(): void
    {
        $brand = $this->brand('Aurora', 1);
        $this->brand('Venus', 0);
        $c = $this->customer('0976');
        $arif = Technician::create(['company_id' => $this->company->id, 'name' => 'Arif', 'wa_number' => null, 'active' => false]);
        $papon = Technician::create(['company_id' => $this->company->id, 'name' => 'Papon', 'wa_number' => '8801711000222']);
        $this->as($this->owner);
        $this->assertSame([$brand->id], array_keys(Onu::brandOptions($this->company->id))); // Venus has no stock

        Livewire::test(ManageOnuAssignments::class)
            ->callAction(TestAction::make('create')->table(), [
                'assigned_at' => '2026-10-16 10:23:00', 'customer_kind' => 'customer', 'customer_code' => '976', 'brand_id' => $brand->id,
                'mac_raw' => '70:30:3b:2f:45:4b', 'purpose' => 'New Connection', 'technician_ids' => [$arif->id, $papon->id],
                'nc_connected_on' => '2026-10-16', 'nc_type' => 'নতুন লাইন', 'nc_name' => 'Bulbul', 'nc_mobile' => '01711000111',
                'nc_address' => 'Tilardi', 'nc_materials' => ['অনু', 'ফাইবার'], 'nc_total' => 2000, 'nc_deposit' => 1500,
                'nc_order_taker_id' => $arif->id, 'nc_money_with_id' => $papon->id,
            ])->assertHasNoFormErrors();

        $a = OnuAssignment::sole();
        $this->assertSame(['0976', $c->id, '70303B2F454B', $this->owner->id], [$a->customer_code, $a->billing_customer_id, $a->mac_norm, $a->user_id]);
        $this->assertSame('2026-10-16 04:23:00', $a->assigned_at->format('Y-m-d H:i:s')); // Dhaka 10:23 stored as UTC
        $this->assertEqualsCanonicalizing([$arif->id, $papon->id], $a->technicians->modelKeys());
        $nc = NewConnection::sole();
        $this->assertSame([$nc->id, '0976', '500.00', ['অনু', 'ফাইবার']], [$a->connection_id, $nc->customer_code, $nc->due, $nc->materials]);
        $this->assertSame(0, Onu::stock($this->company->id)[$brand->id]['left']);

        // same customer + same MAC again is refused
        OnuStockEntry::create(['company_id' => $this->company->id, 'brand_id' => $brand->id, 'quantity' => 5, 'purchase_date' => '2026-10-02']);
        Livewire::test(ManageOnuAssignments::class)
            ->callAction(TestAction::make('create')->table(), ['assigned_at' => now(), 'customer_kind' => 'customer', 'customer_code' => '0976',
                'brand_id' => $brand->id, 'mac_raw' => '70303B2F454B', 'purpose' => 'Warranty'])
            ->assertHasFormErrors(['mac_raw']);

        // "other": no customer ID kept
        Livewire::test(ManageOnuAssignments::class)
            ->callAction(TestAction::make('create')->table(), ['assigned_at' => now(), 'customer_kind' => 'other', 'customer_code' => '0976',
                'note' => 'অফিস', 'brand_id' => $brand->id, 'mac_raw' => 'C0252F6CABD3', 'purpose' => 'বিক্রয় করা হয়েছে'])
            ->assertHasNoFormErrors();
        $o = OnuAssignment::where('purpose', 'বিক্রয় করা হয়েছে')->sole();
        $this->assertSame([null, null, 'অফিস'], [$o->customer_code, $o->billing_customer_id, $o->note]);

        Livewire::test(ManageOnuAssignments::class)->assertSee('ID 0976')->assertSee('70:30:3B:2F:45:4B')->assertSee('অন্যান্য');
    }

    public function test_due_collection_and_connection_form_with_onu(): void
    {
        $brand = $this->brand('Aurora', 3);
        $t = Technician::create(['company_id' => $this->company->id, 'name' => 'Arzu', 'wa_number' => '8801711000333']);
        $this->as($this->owner);
        Livewire::test(ManageNewConnections::class)
            ->callAction(TestAction::make('create')->table(), ['connected_on' => '2026-10-15', 'connection_type' => 'ফাইবার কনভার্ট',
                'customer_kind' => 'customer', 'customer_code' => '6011', 'name' => 'Rafi', 'mobile' => '01711000444', 'address' => 'Binodpur',
                'materials' => ['অনু'], 'total' => 2000, 'deposit' => 1000, 'order_taker_id' => $t->id, 'money_with_id' => $t->id,
                'onu_brand_id' => $brand->id, 'onu_mac' => '78303b2f89f1'])
            ->assertHasNoFormErrors();
        $nc = NewConnection::sole();
        $a = OnuAssignment::sole();
        $this->assertSame(['6011', null, '1000.00'], [$nc->customer_code, $nc->billing_customer_id, $nc->due]);  // not in billing yet
        $this->assertSame(['Convert to ONU', $nc->id, [$t->id]], [$a->purpose, $a->connection_id, $a->technicians->modelKeys()]);

        Livewire::test(ManageNewConnections::class)
            ->callAction(TestAction::make('collect')->table($nc), ['paid_on' => '2026-10-16', 'paid' => 600, 'discount' => 100, 'collected_by_id' => $t->id])
            ->assertHasNoFormErrors();
        $nc->refresh();
        $this->assertSame(['1600.00', '300.00'], [$nc->deposit, $nc->due]);
        $this->assertSame(1, $nc->payments()->count());
    }

    public function test_other_stock_in_out_and_undo(): void
    {
        $t = Technician::create(['company_id' => $this->company->id, 'name' => 'Rifat', 'active' => false]);
        $p = StockProduct::create(['company_id' => $this->company->id, 'name' => 'splitter 1:8']);
        $this->as($this->owner);
        Livewire::test(ManageStockProducts::class)
            ->callAction(TestAction::make('stockIn')->table($p), ['moved_on' => '2026-10-16', 'quantity' => 5, 'unit_price' => 120]);
        Livewire::test(ManageStockProducts::class)
            ->callAction(TestAction::make('stockOut')->table($p), ['moved_on' => '2026-10-16', 'quantity' => 9, 'technician_id' => $t->id]);
        $this->assertSame(5, $p->fresh()->current_stock); // refused: only 5
        Livewire::test(ManageStockProducts::class)
            ->callAction(TestAction::make('stockOut')->table($p), ['moved_on' => '2026-10-16', 'quantity' => 2, 'technician_id' => $t->id, 'reference' => '0976']);
        $this->assertSame(3, $p->fresh()->current_stock);
        $this->assertSame('600.00', StockMovement::where('type', 'in')->sole()->total_price);
        StockMovement::where('type', 'out')->sole()->undo();
        $this->assertSame(5, $p->fresh()->current_stock);
    }

    public function test_brand_page_shows_stock(): void
    {
        $b = $this->brand('DN-MAX', 10);
        OnuAssignment::create(['company_id' => $this->company->id, 'assigned_at' => now(), 'brand_id' => $b->id, 'mac_raw' => 'x', 'purpose' => 'Warranty', 'customer_kind' => 'other']);
        $this->as($this->owner);
        Livewire::test(ManageOnuBrands::class)->assertCanSeeTableRecords([$b])->assertTableColumnStateSet('left_qty', 9, $b);
    }

    public function test_sticker_uses_the_receipt_qr(): void
    {
        $b = $this->brand();
        ReceiptSetting::create(['company_id' => $this->company->id, 'company_name' => 'Century Link Network', 'mobile' => '09613009900,01777858289',
            'qr_link' => 'https://wa.link/n6zs1b', 'qr_note' => '২৪ ঘণ্টা AI সাপোর্ট', 'paper_width' => 80]);
        $a = OnuAssignment::create(['company_id' => $this->company->id, 'assigned_at' => '2026-10-16 04:23:00', 'customer_kind' => 'customer',
            'customer_code' => '0976', 'brand_id' => $b->id, 'mac_raw' => '70303b2f454b', 'purpose' => 'New Connection']);
        $this->actingAs($this->owner)->get("/print/{$this->company->slug}/onu-stickers?ids={$a->id}")->assertOk()
            ->assertSee('Customer ID: 0976')->assertSee('70:30:3B:2F:45:4B')->assertSee('16 Oct 2026, 10:23 AM')
            ->assertSee('২৪ ঘণ্টা AI সাপোর্ট')->assertSee('09613009900 | 01777858289')->assertSee('<svg', false)->assertSee('size: 50mm 75mm', false);
        $this->company->update(['modules' => []]);
        $this->actingAs($this->owner)->get("/print/{$this->company->slug}/onu-stickers?ids={$a->id}")->assertForbidden();
    }

    public function test_technician_without_number_does_not_break_staff_lists(): void
    {
        Technician::create(['company_id' => $this->company->id, 'name' => 'Old', 'wa_number' => null, 'active' => false]);
        $cust = \App\Models\WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801711000999', 'last_message_at' => now()]);
        \App\Models\WaMessage::create(['company_id' => $this->company->id, 'contact_id' => $cust->id, 'direction' => 'in', 'sender' => 'customer', 'body' => 'net nai']);
        $this->assertSame(1, (new \App\Services\Insights($this->company->id))->waiting());
        $this->as($this->owner);
        Livewire::test(\App\Filament\App\Resources\Technicians\Pages\ManageTechnicians::class)->assertSee('নম্বর নেই')
            ->callAction(TestAction::make('create')->table(), ['name' => 'NoNumber', 'active' => false])->assertHasNoFormErrors()
            ->callAction(TestAction::make('create')->table(), ['name' => 'Active', 'active' => true])->assertHasFormErrors(['wa_number']);
    }

    public function test_import_from_the_old_app(): void
    {
        $this->customer('0976');
        $alif = User::factory()->create(['email' => 'alif@cln.com']);
        $this->company->users()->attach($alif, ['role' => 'agent']);
        Technician::create(['company_id' => $this->company->id, 'name' => 'Arzu', 'wa_number' => '8801711000333']);
        $rows = [
            ['employees', ['id' => 2, 'full_name' => 'Yea Alif', 'username' => 'alif']],
            ['employees', ['id' => 8, 'full_name' => 'Arzu', 'username' => 'arzu']],
            ['employees', ['id' => 7, 'full_name' => 'Arif', 'username' => 'arif']],
            ['brands', ['id' => 15, 'name' => 'Aurora', 'price' => '850.00', 'created_at' => '2025-08-11 10:00:00']],
            ['stock_entries', ['id' => 1, 'brand_id' => 15, 'quantity' => 5, 'purchase_date' => '2025-08-11', 'created_at' => '2025-08-11 10:00:00']],
            ['new_connections', ['id' => 700, 'customer_id_code' => '976', 'customer_name' => 'Bulbul', 'mobile_number' => '01711000111',
                'address' => 'Tilardi', 'connection_date' => '2026-10-16', 'connection_type' => 'নতুন লাইন', 'materials_used' => 'অনু,ফাইবার',
                'total_price' => '2000.00', 'deposit_amount' => '1500.00', 'due_amount' => '500.00', 'order_taker_id' => 7, 'money_with_id' => 8,
                'created_at' => '2026-10-16 10:26:39']],
            ['new_connections', ['id' => 701, 'customer_id_code' => '19991', 'customer_name' => 'X', 'mobile_number' => '0', 'address' => '-',
                'connection_date' => '2026-10-16', 'connection_type' => 'নতুন লাইন', 'materials_used' => '', 'total_price' => '0.00',
                'deposit_amount' => '0.00', 'due_amount' => '0.00', 'order_taker_id' => 8, 'money_with_id' => 8, 'created_at' => '2026-10-16 10:26:39']],
            ['due_payments', ['id' => 1, 'connection_id' => 700, 'payment_date' => '2026-10-16', 'paid_amount' => '100.00', 'discount_amount' => '0.00',
                'collected_by_id' => 8, 'created_at' => '2026-10-16 11:00:00']],
            ['onu_assignments', ['id' => 1343, 'assignment_date' => '2026-10-16 10:23:00', 'customer_id' => '976', 'mac_address' => 'a2.8f.06.06.53.b0',
                'brand_name' => 'Aurora', 'purpose' => 'New Connection', 'assigned_to' => 'Arif, Arzu', 'created_by' => 2, 'created_at' => '2026-10-16 10:26:39']],
            ['onu_assignments', ['id' => 1344, 'assignment_date' => '2026-10-16 11:00:00', 'customer_id' => '9999', 'mac_address' => '868606023AB',
                'brand_name' => 'Aurora', 'purpose' => 'Warranty', 'assigned_to' => '', 'created_by' => 8, 'created_at' => '2026-10-16 11:00:00']],
            ['products', ['id' => 3, 'name' => 'splitter 1:4', 'company' => '', 'current_stock' => 13, 'created_at' => '2025-09-08 12:38:01']],
            ['product_stock_logs', ['id' => 9, 'product_id' => 3, 'log_date' => '2025-09-08', 'log_type' => 'in', 'quantity' => 13, 'unit_price' => '50.00',
                'total_price' => '650.00', 'employee_id' => null, 'reference_customer_id' => null, 'created_at' => '2025-09-08 12:38:01']],
        ];
        $file = tempnam(sys_get_temp_dir(), 'onu');
        file_put_contents($file, collect($rows)->map(fn ($r) => json_encode(['t' => $r[0], 'r' => $r[1]], JSON_UNESCAPED_UNICODE))->join("\n"));
        $map = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($map, json_encode(['users' => ['2' => 'alif@cln.com'],
            'staff' => ['Arzu' => ['technician' => 'Arzu'], 'Arif' => ['new' => true, 'billing_employee_id' => '3', 'billing_employee_name' => 'Arif']],
            'not_ids' => ['9999', '19991']]));
        $args = ['file' => $file, '--company' => $this->company->slug, '--map' => $map];

        $this->artisan('onu:import', [...$args, '--dry-run' => true])->expectsOutputToContain('ALL CHECKS')->assertSuccessful();
        $this->assertSame(0, OnuAssignment::count());

        $this->artisan('onu:import', $args)->expectsOutputToContain('UNMAPPED entry user')->assertSuccessful(); // Arzu (8) has no login in the map
        $this->artisan('onu:import', $args)->assertSuccessful(); // again: no duplicates
        $this->assertSame(2, OnuAssignment::count());
        $a = OnuAssignment::where('legacy_id', 1343)->sole();
        $nc = NewConnection::where('legacy_id', 700)->sole();
        $this->assertSame(['0976', 'A28F060653B0', '2026-10-16 04:23:00', $alif->id, $nc->id],
            [$a->customer_code, $a->mac_norm, $a->assigned_at->format('Y-m-d H:i:s'), $a->user_id, $a->connection_id]);
        $this->assertEqualsCanonicalizing(['Arif', 'Arzu'], $a->technicians->pluck('name')->all());
        $arif = Technician::where('name', 'Arif')->sole();
        $this->assertSame([null, false, '3'], [$arif->wa_number, $arif->active, $arif->billing_employee_id]);
        $w = OnuAssignment::where('legacy_id', 1344)->sole();
        $this->assertSame(['other', null, '9999'], [$w->customer_kind, $w->customer_code, $w->legacy_customer_code]);
        $this->assertSame(['other', null, '19991'],
            array_values(NewConnection::where('legacy_id', 701)->sole()->only(['customer_kind', 'customer_code', 'legacy_customer_code'])));
        $this->assertSame(['অনু', 'ফাইবার'], $nc->materials);
        $this->assertSame(13, StockProduct::sole()->current_stock);
        $this->assertSame(3, Onu::stock($this->company->id)[OnuBrand::sole()->id]['left']);

        // a row deleted in the old app goes here too
        file_put_contents($file, collect($rows)->reject(fn ($r) => $r[0] === 'onu_assignments' && $r[1]['id'] === 1344)
            ->map(fn ($r) => json_encode(['t' => $r[0], 'r' => $r[1]], JSON_UNESCAPED_UNICODE))->join("\n"));
        $this->artisan('onu:import', $args)->assertSuccessful();
        $this->assertSame(1, OnuAssignment::count());
    }
}
