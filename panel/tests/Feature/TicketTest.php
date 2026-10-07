<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Conversations\Pages\ViewConversation;
use App\Filament\App\Resources\Tickets\Pages\ListTickets;
use App\Filament\App\Widgets\TicketBreakdownChart;
use App\Filament\App\Widgets\TicketStats;
use App\Filament\App\Widgets\TicketTrendChart;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\User;
use App\Models\WaContact;
use App\Services\TicketInsights;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
    }

    private function ticket(Company $co, string $id, string $state, array $extra = []): BillingTicket
    {
        return BillingTicket::create([
            'company_id' => $co->id, 'complain_id' => $id, 'state' => $state, 'customer_id' => '5629',
            'customer_name' => "Customer $id", 'zone' => 'Binodpur', 'category' => 'Line Off',
            'opened_at' => now()->subHours(30), 'solved_at' => $state === 'solved' ? now()->subHours(28) : null,
            'solved_by' => $state === 'solved' ? 'Rifat' : null, ...$extra,
        ]);
    }

    private function as(): void
    {
        $this->actingAs($this->agent);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_tickets_page_lists_own_company_with_tabs(): void
    {
        $open = $this->ticket($this->company, '57231', 'pending');
        $solved = $this->ticket($this->company, '57159', 'solved');
        $other = $this->ticket(Company::create(['name' => 'Other ISP']), '9001', 'pending');
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/tickets")->assertOk();
        $this->as();
        Livewire::test(ListTickets::class)
            ->assertCanSeeTableRecords([$open])->assertCanNotSeeTableRecords([$solved, $other])
            ->set('activeTab', 'solved')->assertCanSeeTableRecords([$solved])->assertCanNotSeeTableRecords([$open])
            ->set('activeTab', 'all')->assertCanSeeTableRecords([$open, $solved])->assertCanNotSeeTableRecords([$other])
            ->searchTable('57159')->assertCanSeeTableRecords([$solved])->assertCanNotSeeTableRecords([$open]);
    }

    public function test_ticket_numbers(): void
    {
        $this->ticket($this->company, '1', 'pending');
        $this->ticket($this->company, '2', 'processing', ['opened_at' => now()->subHour(), 'zone' => 'Minto']);
        $this->ticket($this->company, '3', 'solved');
        $t = new TicketInsights($this->company->id);
        $this->assertSame(2, $t->openCount());
        $this->assertSame(1, $t->overdue(24));
        $this->assertSame(120, $t->avgSolveMinutes(CarbonImmutable::now()->subDays(7)));
        $this->assertSame(['Binodpur' => 1, 'Minto' => 1], $t->top('zone', CarbonImmutable::now()->subDays(30), openOnly: true));
        $this->assertSame(['Rifat' => 1], $t->top('solved_by', CarbonImmutable::now()->subDays(30)));
        $this->assertSame(1, array_sum($t->daily(7)['solved']));
    }

    public function test_widgets_and_chat_show_tickets(): void
    {
        $this->ticket($this->company, '57231', 'pending');
        $c = WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801838216754', 'customer_id' => '5629']);
        $this->as();
        Livewire::test(TicketStats::class)->assertSee('এখন খোলা');
        Livewire::test(TicketTrendChart::class)->assertOk()->set('filter', '90')->assertOk();
        foreach (['open_zone', 'zone', 'category', 'solved_by'] as $f) {
            Livewire::test(TicketBreakdownChart::class)->set('filter', $f)->assertOk();
        }
        Livewire::test(ViewConversation::class, ['record' => $c->id])->assertSee('#57231')->assertSee('অপেক্ষমাণ');
    }
}
