<?php

namespace Tests\Feature;

use App\Filament\App\Resources\OnlinePayments\OnlinePaymentResource;
use App\Filament\App\Resources\OnlinePayments\Pages\ListOnlinePayments;
use App\Filament\App\Widgets\OnlinePaymentStats;
use App\Models\BillingCustomer;
use App\Models\BillingOnlinePayment;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    /** @var array<string, BillingOnlinePayment> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 6798, 'customer_id' => '0904', 'name' => 'Alif', 'username' => 'ng.alif']);
        $mk = fn (string $source, int $id, string $gw, float $amount, int $status, string $at, ?string $ref, ?int $header = null, ?string $by = null) => BillingOnlinePayment::create([
            'company_id' => $this->company->id, 'source' => $source, 'billing_row_id' => $id, 'gateway' => $gw,
            'type_for' => strtoupper($gw).'_'.strtoupper($source), 'trx_id' => "TRX{$id}", 'amount' => $amount,
            'reference' => $ref, 'customer_header_id' => $header, 'customer_id' => $header ? '0904' : null,
            'status_id' => $status, 'paid_at' => Carbon::parse($at, 'Asia/Dhaka')->utc(), 'updated_by' => $by]);
        $this->p['auto'] = $mk('webhook', 1, 'bKash', 525, 2, '2026-10-24 10:20', '0904', 6798);
        $this->p['paybill'] = $mk('paybill', 2, 'bKash', 400, 2, '2026-10-23 21:55', '1203');
        $this->p['requested'] = $mk('webhook', 3, 'bKash', 520, 1, '2026-10-24 09:00', 'bill');
        $this->p['manual'] = $mk('webhook', 4, 'Nagad', 300, 3, '2026-10-22 08:00', 'Alif', null, 'arju11');
        $this->p['september'] = $mk('paybill', 5, 'bKash', 700, 2, '2026-09-30 22:00', '0015');
    }

    private function panel(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_owner_sees_this_months_payments_and_filters(): void
    {
        $this->panel($this->owner);
        Livewire::test(ListOnlinePayments::class)
            ->assertCanSeeTableRecords([$this->p['auto'], $this->p['paybill'], $this->p['requested'], $this->p['manual']])
            ->assertCanNotSeeTableRecords([$this->p['september']])
            ->filterTable('status', 1)->assertCanSeeTableRecords([$this->p['requested']])->assertCanNotSeeTableRecords([$this->p['auto']])
            ->resetTableFilters()->filterTable('source', 'paybill')->assertCanSeeTableRecords([$this->p['paybill']])
            ->assertCanNotSeeTableRecords([$this->p['auto']])
            ->resetTableFilters()->filterTable('matched', false)->assertCanNotSeeTableRecords([$this->p['auto']])
            ->assertCanSeeTableRecords([$this->p['requested']])
            ->resetTableFilters()->searchTable('TRX4')->assertCanSeeTableRecords([$this->p['manual']])
            ->assertCanNotSeeTableRecords([$this->p['auto']]);
    }

    public function test_matched_customer_links_to_the_customer_page(): void
    {
        $this->panel($this->owner);
        Livewire::test(ListOnlinePayments::class)->assertSee('Alif')->assertSee('ng.alif');
    }

    public function test_stats_follow_the_filters(): void
    {
        $this->panel($this->owner);
        $t = OnlinePaymentStats::totals(OnlinePaymentResource::getEloquentQuery()
            ->where('paid_at', '>=', Carbon::parse('2026-10-01', 'Asia/Dhaka')->utc()));
        $this->assertSame(4, $t['count']);
        $this->assertEqualsWithDelta(1745.0, $t['amount'], 0.001);
        $this->assertSame([3, 1345.0], $t['webhook']);
        $this->assertSame([1, 400.0], $t['paybill']);
        $this->assertSame([1, 520.0], $t['requested']);
    }

    public function test_unsettled_badge_and_menu_permission(): void
    {
        $this->panel($this->owner);
        $this->assertSame('1', OnlinePaymentResource::getNavigationBadge());
        $this->get('/app/'.$this->company->slug.'/online-payments')->assertOk()->assertSee('অনলাইন পেমেন্ট');

        $this->actingAs($this->agent)->get('/app/'.$this->company->slug.'/online-payments')->assertForbidden();
        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['bills'])]);
        $this->actingAs($this->agent)->get('/app/'.$this->company->slug.'/online-payments')->assertOk();
    }

    public function test_other_companies_payments_are_hidden(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        $theirs = BillingOnlinePayment::create(['company_id' => $other->id, 'source' => 'webhook', 'billing_row_id' => 1,
            'gateway' => 'bKash', 'amount' => 100, 'status_id' => 2, 'paid_at' => now()]);
        $this->panel($this->owner);
        Livewire::test(ListOnlinePayments::class)->assertCanNotSeeTableRecords([$theirs]);
    }
}
