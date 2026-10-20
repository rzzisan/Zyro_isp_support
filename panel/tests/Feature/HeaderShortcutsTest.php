<?php

namespace Tests\Feature;

use App\Filament\App\Resources\DailyBills\Pages\ListDailyBills;
use App\Filament\App\Resources\Tickets\Pages\ListTickets;
use App\Models\Company;
use App\Models\MikrotikRouter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Topbar shortcuts next to the search box (resources/views/filament/header-shortcuts.blade.php). */
class HeaderShortcutsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private function member(string $role, ?array $permissions = null): User
    {
        $this->company ??= Company::create(['name' => 'Century Link Network']);
        $user = User::factory()->create();
        $this->company->users()->attach($user, ['role' => $role, 'permissions' => $permissions === null ? null : json_encode($permissions)]);

        return $user;
    }

    public function test_owner_gets_inbox_bill_and_ticket_shortcuts(): void
    {
        $html = $this->actingAs($this->member('owner'))->get('/app/'.$this->company->slug)->assertOk()->getContent();
        $this->assertStringContainsString('class="z-shortcuts"', $html);
        foreach (['/inbox"', '/daily-bills?action=receiveBill"', '/tickets?action=newTicket"'] as $path) {
            $this->assertMatchesRegularExpression('#href="[^"]*'.preg_quote($path, '#').'#', $html);
        }
    }

    public function test_monitoring_shortcut_only_with_a_mikrotik(): void
    {
        $owner = $this->member('owner');
        $this->assertStringNotContainsString('/monitoring"', $this->actingAs($owner)->get('/app/'.$this->company->slug)->getContent());
        MikrotikRouter::create(['company_id' => $this->company->id, 'host' => 'h', 'api_port' => 8728, 'username' => 'u', 'password' => 'p']);
        $html = $this->actingAs($owner)->get('/app/'.$this->company->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/monitoring" class="z-shortcut"#', $html);
    }

    public function test_shortcut_follows_menu_permission(): void
    {
        $html = $this->actingAs($this->member('agent', ['inbox']))->get('/app/'.$this->company->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="[^"]*/inbox"#', $html);
        $this->assertStringNotContainsString('action=receiveBill', $html);
        $this->assertStringNotContainsString('action=newTicket', $html);
    }

    public function test_links_open_the_bill_and_ticket_forms(): void
    {
        $owner = $this->member('owner');
        \App\Models\Membership::where('user_id', $owner->id)->update(['billing_username' => 'zisan54', 'billing_password' => encrypt('pw', false)]);
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        // the page opens ?action=<name> on load; here: the action exists on that page and opens
        Livewire::test(ListDailyBills::class)->mountAction('receiveBill')->assertActionMounted('receiveBill');
        Livewire::test(ListTickets::class)->mountAction('newTicket')->assertActionMounted('newTicket');
    }
}
