<?php

namespace Tests\Feature;

use App\Filament\App\Pages\MyBillingLogin;
use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Filament\App\Resources\Tickets\Pages\ListTickets;
use App\Models\BillingConnection;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MemberBillingLoginTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        BillingConnection::create(['company_id' => $this->company->id, 'provider' => 'ispdigital',
            'base_url' => 'https://isp.example', 'username' => 'bot', 'password' => 'botpass']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_agent_saves_own_login_encrypted_and_it_is_checked(): void
    {
        Http::fake([
            'isp.example/Account/Login' => Http::response('<input name="__RequestVerificationToken" type="hidden" value="tok" />'),
            'isp.example/Account/LoginChecker' => Http::response('', 302, ['Location' => '/EmployeeDashboard/Index']),
            'isp.example/EmployeeDashboard/Index' => Http::response('ok'),
        ]);
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/my-billing-login")->assertOk();
        $this->as($this->agent);
        Livewire::test(MyBillingLogin::class)
            ->fillForm(['billing_username' => 'rezaul', 'billing_password' => 's3cret'])
            ->call('save')->assertHasNoFormErrors();
        $m = Membership::where('user_id', $this->agent->id)->sole();
        $this->assertSame('rezaul', $m->billing_username);
        $this->assertSame('s3cret', $m->billing_password);
        $this->assertNotSame('s3cret', DB::table('company_user')->where('user_id', $this->agent->id)->value('billing_password'));
        $this->assertTrue($m->fresh()->billing_check_ok);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/Account/LoginChecker') && $r['Username'] === 'rezaul');

        // another member's login is untouched
        $this->assertNull(Membership::where('user_id', $this->owner->id)->value('billing_username'));
    }

    public function test_owner_sets_member_login_on_team_page(): void
    {
        $this->as($this->owner);
        $m = Membership::where('user_id', $this->agent->id)->sole();
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('edit')->table($m), ['role' => 'agent', 'billing_username' => 'arif', 'billing_password' => 'pw1'])
            ->assertHasNoFormErrors();
        $this->assertSame('pw1', $m->fresh()->billing_password);
        // a blank password keeps the old one
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('edit')->table($m), ['role' => 'agent', 'billing_username' => 'arif2', 'billing_password' => ''])
            ->assertHasNoFormErrors();
        $this->assertSame('pw1', $m->fresh()->billing_password);
        $this->assertSame('arif2', $m->fresh()->billing_username);
    }

    public function test_ticket_actions_send_the_signed_in_user(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $cid = $this->company->id;
        Http::fake([
            "engine.test/internal/{$cid}/ticket-options" => Http::response(['categories' => [], 'priorities' => [], 'departments' => [], 'employees' => ['3' => 'Arif']]),
            "engine.test/internal/{$cid}/tickets/*/solvers" => Http::response([]),
            "engine.test/internal/{$cid}/tickets/*/assign" => Http::response(['ok' => true]),
        ]);
        $t = BillingTicket::create(['company_id' => $cid, 'complain_id' => '57231', 'state' => 'pending']);
        $this->as($this->agent);
        Livewire::test(ListTickets::class)->callAction(TestAction::make('assign')->table($t), ['employees' => ['3']]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/assign') && $r['user_id'] === $this->agent->id);
    }
}
