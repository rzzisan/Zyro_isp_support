<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Customers\History\BillsTable;
use App\Filament\App\Resources\Customers\History\ChangesTable;
use App\Filament\App\Resources\Customers\History\ComplainsTable;
use App\Filament\App\Resources\Customers\History\PaymentsTable;
use App\Filament\App\Resources\Customers\History\SmsTable;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Filament\App\Resources\Customers\Widgets\CustomerHistoryStatus;
use App\Models\BillingCustomer;
use App\Models\BillingCustomerBill;
use App\Models\BillingCustomerChangeLog;
use App\Models\BillingCustomerPayment;
use App\Models\BillingCustomerSms;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The customer page's billing tabs (docs/customer-profile-plan.md). */
class CustomerHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private BillingCustomer $c;

    private bool $engineRunning = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']); // agents don't get বিল কালেকশন by default
        $this->c = BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 15, 'customer_id' => '0015',
            'name' => 'Asaduzzaman', 'username' => 'en.asaduzzaman', 'status' => 'Active', 'monthly_bill' => 400,
            'mikrotik_profile' => 'P-1_400', 'father_name' => 'Abdul', 'details' => ['installation' => ['fee' => 2300.0]]]);
        Http::fake(fn ($request) => Http::response(str_ends_with($request->url(), '/history/status')
            ? ['running' => false] : ['running' => $this->engineRunning, 'started' => $this->engineRunning]));
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function row(string $model, array $data): mixed
    {
        return $model::create(['company_id' => $this->company->id, 'customer_header_id' => 15, ...$data]);
    }

    public function test_tabs_show_billing_details_and_bill_tabs_only_with_bill_collection_menu(): void
    {
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->c->id])
            ->assertSee('সার্ভিস')->assertSee('ব্যক্তিগত তথ্য')->assertSee('পরিবর্তনের ইতিহাস')
            ->assertSee('বিল')->assertSee('পেমেন্ট')
            ->assertSee('P-1_400')->assertSee('Abdul')->assertSee('2,300.00 ৳');

        $this->assertStringContainsString('PaymentsTable', Livewire::test(ViewCustomer::class, ['record' => $this->c->id])->html(false));

        $this->as($this->agent);
        $page = Livewire::test(ViewCustomer::class, ['record' => $this->c->id])->assertSee('লাইন চালু/বন্ধ');
        $this->assertStringNotContainsString('PaymentsTable', $page->html(false));
        $this->assertStringNotContainsString('BillsTable', $page->html(false));
        $this->assertStringContainsString('LineLogsTable', $page->html(false));
    }

    public function test_lists_read_only_this_customers_rows(): void
    {
        $other = BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 16, 'customer_id' => '0016']);
        $mine = $this->row(BillingCustomerBill::class, ['bill_master_id' => 1, 'month_name' => 'Oct-26', 'bill_amount' => 400]);
        $theirs = BillingCustomerBill::create(['company_id' => $this->company->id, 'customer_header_id' => 16, 'bill_master_id' => 2]);
        $gone = $this->row(BillingCustomerBill::class, ['bill_master_id' => 3, 'removed_at' => now()]);
        $this->as($this->owner);
        Livewire::test(BillsTable::class, ['record' => $this->c])
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs, $gone]);

        $p = $this->row(BillingCustomerPayment::class, ['bill_header_id' => 9, 'received_on' => '2026-09-27', 'paid' => 400,
            'discount' => 0, 'method' => 'bKash', 'is_online' => true]);
        Livewire::test(PaymentsTable::class, ['record' => $this->c])->assertCanSeeTableRecords([$p])
            ->assertSee('মোট বিল 400.00')->assertSee('মোট জমা 400.00');

        $t = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '52215', 'customer_header_id' => 15,
            'state' => 'solved', 'category' => 'Line Off', 'source' => 'history']);
        Livewire::test(ComplainsTable::class, ['record' => $this->c])->assertCanSeeTableRecords([$t]);

        $s = $this->row(BillingCustomerSms::class, ['sms_log_id' => 5, 'type_name' => 'Due', 'text' => 'bill dao', 'sent_at' => now()]);
        Livewire::test(SmsTable::class, ['record' => $this->c])->assertCanSeeTableRecords([$s]);
        Livewire::test(SmsTable::class, ['record' => $other])->assertCanNotSeeTableRecords([$s]);
    }

    public function test_change_log_shows_what_changed_and_a_password_change_without_any_password(): void
    {
        $this->row(BillingCustomerChangeLog::class, ['changed_at' => now()->subDay(), 'changed_by' => 'zisan54', 'row_hash' => 'a',
            'snapshot' => ['Package' => 'P-2_500'], 'changed' => ['Package' => ['P-2_500', '15 Mbps 525'], 'MonthlyBill' => [500, 520]],
            'password_changed' => true]);
        $this->row(BillingCustomerChangeLog::class, ['changed_at' => now(), 'changed_by' => 'System', 'row_hash' => 'b',
            'snapshot' => ['Package' => '15 Mbps 525'], 'changed' => [], 'password_changed' => false]);
        $this->as($this->owner);
        Livewire::test(ChangesTable::class, ['record' => $this->c])
            ->assertSee('P-2_500 → 15 Mbps 525')->assertSee('মাসিক বিল')->assertSee('PPPoE পাসওয়ার্ড</b> বদলেছে', false)
            ->assertDontSee('System'); // "only where something changed" is on by default
    }

    public function test_status_asks_the_engine_and_tells_the_tabs_when_billing_was_read(): void
    {
        $this->engineRunning = true;
        $this->as($this->agent);
        Livewire::test(CustomerHistoryStatus::class, ['record' => $this->c])
            ->assertSet('running', true)->assertSee('আপডেট হচ্ছে')
            ->call('check')->assertSet('running', false)->assertDispatched('customer-history-updated');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), "/internal/{$this->company->id}/customers/15/history/refresh"));
    }
}
