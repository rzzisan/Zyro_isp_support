<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\Tickets\Pages\ListTickets;
use App\Filament\App\Resources\Tickets\TicketResource;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TicketActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        $cid = $this->company->id;
        Http::fake([
            "engine.test/internal/{$cid}/ticket-options" => Http::response([
                'categories' => ['3804' => 'Line Off'], 'priorities' => ['1' => 'Low', '2' => 'Medium', '3' => 'High'],
                'departments' => ['43' => 'Support'], 'employees' => ['3' => 'Arif', '9' => 'Rifat']]),
            "engine.test/internal/{$cid}/customers*" => Http::response([['header_id' => 15630, 'customer_id' => '5629',
                'name' => 'Rezaul', 'mobile' => '01838216754', 'username' => 'rz5629', 'zone' => 'Binodpur']]),
            "engine.test/internal/{$cid}/tickets" => Http::response(['message' => 'Saved', 'complain_id' => '57400']),
            "engine.test/internal/{$cid}/tickets/*/solvers" => Http::response(['DepartmentId' => null, 'EmployeeIds' => [3]]),
            "engine.test/internal/{$cid}/tickets/*/assign" => Http::response(['ok' => true]),
        ]);
    }

    public function test_new_ticket_from_tickets_page_and_dashboard(): void
    {
        $data = ['customer' => '15630|5629|rz5629|01838216754', 'category_id' => '3804', 'priority' => '3',
            'mobile' => '01838216754', 'comment' => 'লাইন বন্ধ', 'employees' => ['9'], 'dept_id' => '43',
            'sms_client' => false, 'sms_employees' => true];
        Livewire::test(ListTickets::class)->callAction('newTicket', $data)->assertHasNoFormErrors();
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/tickets"
            && $r->hasHeader('x-internal-key', 'k') && $r['header_id'] === 15630 && $r['username'] === 'rz5629'
            && $r['employees'] == [9] && $r['priority'] === 3);

        Livewire::test(Dashboard::class)->callAction('newTicket', $data)->assertHasNoFormErrors();
    }

    public function test_assign_open_ticket(): void
    {
        $t = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '57231', 'state' => 'pending', 'opened_at' => now()]);
        Livewire::test(ListTickets::class)
            ->callAction(TestAction::make('assign')->table($t), ['employees' => ['3', '9'], 'sms_employees' => false])
            ->assertHasNoFormErrors();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tickets/57231/assign') && $r['employees'] == [3, 9]
            && $r['sms_employees'] === false);
    }

    public function test_engine_error_is_shown_not_thrown(): void
    {
        config(['services.engine.url' => 'http://broken.test']);
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake([
            'broken.test/*/ticket-options' => Http::response(['categories' => [], 'priorities' => [], 'departments' => [], 'employees' => ['3' => 'Arif']]),
            'broken.test/*' => Http::response(['detail' => 'login failed'], 400),
        ]);
        $t = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '1', 'state' => 'pending']);
        Livewire::test(ListTickets::class)
            ->callAction(TestAction::make('assign')->table($t), ['employees' => ['3']])
            ->assertNotified('Assign করা যায়নি');
    }

    public function test_date_presets(): void
    {
        [$from, $until] = TicketResource::period(['period' => 'yesterday']);
        $this->assertSame(now('Asia/Dhaka')->subDay()->format('Y-m-d'), $from->format('Y-m-d'));
        $this->assertSame(now('Asia/Dhaka')->format('Y-m-d'), $until->format('Y-m-d'));
        $old = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => 'old', 'state' => 'pending', 'opened_at' => now()->subDays(10)]);
        $new = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => 'new', 'state' => 'pending', 'opened_at' => now()]);
        Livewire::test(ListTickets::class)
            ->filterTable('date', ['field' => 'opened_at', 'period' => 'today'])
            ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$old]);
    }
}
