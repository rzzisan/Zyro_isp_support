<?php

namespace Tests\Feature;

use App\Filament\App\Widgets\LiveStats;
use App\Filament\App\Widgets\MessagesChart;
use App\Filament\App\Widgets\RecentChats;
use App\Filament\App\Widgets\ReplyShareChart;
use App\Filament\App\Widgets\TicketCategoryChart;
use App\Models\Company;
use App\Models\User;
use App\Models\WaContact;
use App\Models\WaDraft;
use App\Models\WaMessage;
use App\Services\Insights;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function chat(Company $co, string $wa, array $senders): WaContact
    {
        $c = WaContact::create(['company_id' => $co->id, 'wa_number' => $wa, 'last_message_at' => now()]);
        foreach ($senders as $i => $s) {
            $m = WaMessage::create(['company_id' => $co->id, 'contact_id' => $c->id, 'direction' => $s === 'customer' ? 'in' : 'out',
                'sender' => $s, 'body' => "m$i"]);
            $m->forceFill(['created_at' => now()->subMinutes(10 - $i)])->save();
        }

        return $c;
    }

    public function test_numbers_are_per_company(): void
    {
        $answered = $this->chat($this->company, '8801711000001', ['customer', 'bot']);
        $this->chat($this->company, '8801711000002', ['customer', 'bot', 'customer']);
        $other = Company::create(['name' => 'Other ISP']);
        $this->chat($other, '8801711000003', ['customer', 'customer', 'staff']);
        WaDraft::create(['company_id' => $this->company->id, 'contact_id' => $answered->id, 'mode' => 'sent',
            'ticket_note' => 'Line Off | ONU offline → টিকেট খোলা হয়েছে #57300']);
        WaDraft::create(['company_id' => $this->company->id, 'contact_id' => $answered->id, 'mode' => 'dry_run',
            'ticket_note' => 'Speed Slow | slow → (টিকেট খোলা হয়নি: dry run)']);

        $in = new Insights($this->company->id);
        $today = Insights::todayStartUtc()->subDay(); // safe across midnight
        $this->assertSame(['customer' => 3, 'bot' => 2, 'staff' => 0, 'campaign' => 0], $in->messageCounts($today));
        $this->assertSame(1, $in->waiting());
        $this->assertSame([1, 2], $in->tickets($today));
        $this->assertSame(['Line Off' => 1, 'Speed Slow' => 1], $in->ticketCategories($today));

        $hours = $in->series('hour', 24);
        $this->assertCount(24, $hours['labels']);
        $this->assertSame(3, array_sum($hours['customer']));
        $this->assertSame(2, array_sum($hours['bot']));
        $this->assertSame(0, array_sum($hours['staff']));
    }

    public function test_agent_sees_live_dashboard_and_widgets_render(): void
    {
        $this->chat($this->company, '8801711000001', ['customer', 'bot']);
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}")->assertOk();
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(LiveStats::class)->assertSee('কাস্টমারের মেসেজ')->assertSee('উত্তরের অপেক্ষায়');
        foreach ([MessagesChart::class, ReplyShareChart::class, TicketCategoryChart::class] as $w) {
            Livewire::test($w)->assertOk();
        }
        Livewire::test(MessagesChart::class)->set('filter', '7d')->assertOk();
        Livewire::test(RecentChats::class)->assertSee('m1');
    }
}
