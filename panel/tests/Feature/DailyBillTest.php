<?php

namespace Tests\Feature;

use App\Filament\App\Resources\DailyBills\Pages\ListDailyBills;
use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Filament\App\Resources\PermissionGroups\Pages\ManagePermissionGroups;
use App\Filament\App\Widgets\DailyBillStats;
use App\Models\BillingDailyBill;
use App\Models\Company;
use App\Models\Membership;
use App\Models\PermissionGroup;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class DailyBillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    /** @var array<string, BillingDailyBill> */
    private array $b = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 06:00:00', 'UTC'));
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['bills']), 'billing_username' => 'Jobbaar']);
        $mk = fn (int $id, int $emp, string $by, string $creator, string $at, float $paid, float $disc = 0, float $due = 0, bool $approved = true, bool $deleted = false) => BillingDailyBill::create([
            'company_id' => $this->company->id, 'daily_bill_id' => $id, 'customer_header_id' => $id, 'customer_id' => (string) $id,
            'customer_name' => "C{$id}", 'username' => "u{$id}", 'emp_header_id' => $emp, 'received_by' => $by, 'created_by' => $creator,
            'collected_at' => Carbon::parse($at, 'Asia/Dhaka')->utc(), 'receive_amount' => $paid, 'discount_amount' => $disc,
            'balance_due' => $due, 'is_approved' => $approved, 'is_deleted' => $deleted, 'payment_method' => 'Cash']);
        $this->b['zisan'] = $mk(1, 1, 'Zisan Ahammed', 'zisan54', '2026-10-11 19:16', 525);
        $this->b['jobbar'] = $mk(2, 24, 'Abdul Jobbar', 'jobbaar', '2026-10-11 21:58', 500, 25, 0, false);
        $this->b['minto'] = $mk(3, 5, 'Minto Ahammed', 'minto06', '2026-10-05 09:30', 520, 0, 20);
        $this->b['deleted'] = $mk(4, 5, 'Minto Ahammed', 'minto06', '2026-10-06 10:00', 300, 0, 0, false, true);
        $this->b['september'] = $mk(5, 1, 'Zisan Ahammed', 'zisan54', '2026-09-30 23:30', 700);
    }

    private function panel(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_owner_sees_this_months_bills_without_deleted(): void
    {
        $this->panel($this->owner);
        Livewire::test(ListDailyBills::class)
            ->assertCanSeeTableRecords([$this->b['zisan'], $this->b['jobbar'], $this->b['minto']])
            ->assertCanNotSeeTableRecords([$this->b['deleted'], $this->b['september']])
            ->filterTable('status', 'deleted')->assertCanSeeTableRecords([$this->b['deleted']])->assertCanNotSeeTableRecords([$this->b['zisan']])
            ->filterTable('status', 'pending')->assertCanSeeTableRecords([$this->b['jobbar']])->assertCanNotSeeTableRecords([$this->b['zisan']])
            ->resetTableFilters()->filterTable('month', '2026-09')->assertCanSeeTableRecords([$this->b['september']])
            ->assertCanNotSeeTableRecords([$this->b['zisan']]);
    }

    public function test_date_time_and_user_filters(): void
    {
        $this->panel($this->owner);
        Livewire::test(ListDailyBills::class)
            ->filterTable('date', ['from' => '2026-10-11', 'until' => '2026-10-11'])
            ->assertCanSeeTableRecords([$this->b['zisan'], $this->b['jobbar']])->assertCanNotSeeTableRecords([$this->b['minto']])
            ->filterTable('time', ['from' => '20:00', 'until' => '23:59'])
            ->assertCanSeeTableRecords([$this->b['jobbar']])->assertCanNotSeeTableRecords([$this->b['zisan']])
            ->resetTableFilters()->filterTable('emp_header_id', [5])
            ->assertCanSeeTableRecords([$this->b['minto']])->assertCanNotSeeTableRecords([$this->b['zisan'], $this->b['jobbar']])
            ->resetTableFilters()->filterTable('created_by', ['zisan54'])
            ->assertCanSeeTableRecords([$this->b['zisan']])->assertCanNotSeeTableRecords([$this->b['minto']]);
    }

    public function test_totals_follow_the_query(): void
    {
        $base = BillingDailyBill::where('company_id', $this->company->id)->where('is_deleted', false)
            ->where('collected_at', '>=', Carbon::parse('2026-10-01', 'Asia/Dhaka')->utc());
        $this->assertSame(['count' => 3, 'paid' => 1545.0, 'discount' => 25.0, 'due' => 20.0], DailyBillStats::totals(clone $base));
        $this->assertSame(['count' => 1, 'paid' => 520.0, 'discount' => 0.0, 'due' => 20.0], DailyBillStats::totals((clone $base)->where('emp_header_id', 5)));
        $this->panel($this->owner);
        Livewire::test(DailyBillStats::class, ['tableClass' => ListDailyBills::class])->assertSee('1,545 ৳')->assertSee('3টি');
    }

    public function test_agent_sees_own_bills_then_the_collectors_given(): void
    {
        $this->panel($this->agent);
        // nothing picked: only what they entered with their own billing username (case-insensitive)
        Livewire::test(ListDailyBills::class)
            ->assertCanSeeTableRecords([$this->b['jobbar']])->assertCanNotSeeTableRecords([$this->b['zisan'], $this->b['minto']]);

        $this->panel($this->owner);
        $m = Membership::where('user_id', $this->agent->id)->sole();
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('edit')->table($m), ['role' => 'agent', 'permissions' => ['bills'], 'bill_view_employees' => ['5']])
            ->assertHasNoFormErrors();
        $this->assertSame([5], array_map('intval', $m->fresh()->bill_view_employees));

        $this->panel($this->agent);
        Livewire::test(ListDailyBills::class)
            ->assertCanSeeTableRecords([$this->b['jobbar'], $this->b['minto']])->assertCanNotSeeTableRecords([$this->b['zisan']]);
    }

    public function test_group_gives_its_members_the_collectors_bills(): void
    {
        $this->panel($this->owner);
        $m = Membership::where('user_id', $this->agent->id)->sole();
        Livewire::test(ManagePermissionGroups::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'কালেক্টর', 'permissions' => ['bills'], 'bill_view_employees' => ['5'], 'memberships' => [$m->id]])
            ->assertHasNoFormErrors();
        $this->assertSame([5], PermissionGroup::sole()->bill_view_employees);

        // group's collectors plus their own entries
        $this->panel($this->agent);
        Livewire::test(ListDailyBills::class)
            ->assertCanSeeTableRecords([$this->b['jobbar'], $this->b['minto']])->assertCanNotSeeTableRecords([$this->b['zisan']]);
    }

    public function test_live_check_returns_the_engine_version(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake(["engine.test/internal/{$this->company->id}/daily-bills/live" => Http::sequence()
            ->push(['ran' => true, 'version' => '170300:5:4:1'])->push(['detail' => 'down'], 500)]);
        $url = "/live/{$this->company->slug}/daily-bills";
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertExactJson(['version' => '170300:5:4:1']);
        // engine down: no version, no error
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertExactJson(['version' => null]);
        // only members with the bills menu
        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['inbox'])]);
        $this->actingAs($this->agent)->getJson($url)->assertForbidden();
    }

    public function test_page_and_cards_refresh_on_the_live_event(): void
    {
        $this->panel($this->owner);
        Livewire::test(ListDailyBills::class)->dispatch('daily-bills-changed')->assertOk()
            ->assertSee('daily-bills', false)->assertCanSeeTableRecords([$this->b['zisan']]);
        Livewire::test(DailyBillStats::class, ['tableClass' => ListDailyBills::class])->dispatch('daily-bills-changed')->assertOk();
    }

    public function test_agent_without_bills_menu_is_refused(): void
    {
        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['inbox'])]);
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/daily-bills")->assertForbidden();
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/daily-bills")->assertOk()->assertSee('বিল কালেকশন');
    }

    public function test_every_bill_but_deleted_has_a_receipt(): void
    {
        $this->panel($this->owner);
        Livewire::test(ListDailyBills::class)
            ->assertTableActionVisible('receipt', $this->b['zisan'])->assertTableActionVisible('receipt', $this->b['jobbar'])
            ->filterTable('status', 'deleted')->assertTableActionHidden('receipt', $this->b['deleted']);
    }

    public function test_receipt_of_a_billing_bill(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake(["engine.test/internal/{$this->company->id}/daily-bills/1/receipt-no" => Http::response(['receipt_no' => '0001-202610-1119-abcRV'])]);
        $slug = $this->company->slug;
        // approved: billing's number, total = paid + discount + due
        $this->actingAs($this->owner)->get("/print/{$slug}/bill/1?print=0")->assertOk()
            ->assertSee('MONEY RECEIPT')->assertSee('0001-202610-1119-abcRV')->assertSee('C1')->assertSee('u1')
            ->assertSee('11 Oct 2026, 07:16 PM')->assertSee('525.00')->assertSee('Zisan Ahammed')->assertDontSee('approve বাকি');
        // pending: its own number, no engine call needed
        $this->actingAs($this->owner)->get("/print/{$slug}/bill/2?print=0")->assertOk()
            ->assertSee('B-2')->assertDontSee('approve বাকি')->assertSee('500.00')->assertSee('25.00')->assertSee('Abdul Jobbar');
        Http::assertSentCount(1);
        // deleted and other companies' bills don't print
        $this->actingAs($this->owner)->get("/print/{$slug}/bill/4")->assertNotFound();
        // an agent only prints bills they may see
        $this->actingAs($this->agent)->get("/print/{$slug}/bill/2?print=0")->assertOk();
        $this->actingAs($this->agent)->get("/print/{$slug}/bill/1")->assertNotFound();
    }
}
