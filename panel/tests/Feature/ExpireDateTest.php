<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingSettings;
use App\Filament\App\Pages\ExpireDates;
use App\Models\BillingConnection;
use App\Models\Company;
use App\Models\ExpireDateChange;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ExpireDateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $admin;

    private User $agent;

    /** @var array<string, ExpireDateChange> */
    private array $r = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        BillingConnection::create(['company_id' => $this->company->id, 'provider' => 'ispdigital',
            'base_url' => 'https://x.ispdigital.cloud', 'username' => 'bot', 'password' => 'p']);
        foreach (['owner', 'admin', 'agent'] as $role) {
            $this->{$role} = User::factory()->create();
            $this->company->users()->attach($this->{$role}, ['role' => $role]);
        }
        $mk = fn (int $h, string $type, string $status, int $old, int $new, string $rule = 'pay_day') => ExpireDateChange::create([
            'company_id' => $this->company->id, 'customer_header_id' => $h, 'customer_id' => (string) $h, 'name' => "C{$h}",
            'customer_type' => $type, 'kind' => 'bulk', 'status' => $status, 'rule' => $rule, 'old_day' => $old,
            'new_day' => $new, 'off_at' => Carbon::parse('2026-09-24 03:00'), 'on_at' => Carbon::parse('2026-09-26 04:00'),
            'off_hours' => 49, 'paid_on' => '2026-09-26', 'paid' => 525, 'payable' => 525, 'paid_pct' => 100, 'method' => 'Cash']);
        $this->r['student'] = $mk(12558, 'Student', 'preview', 23, 26);
        $this->r['home'] = $mk(200, 'Home', 'preview', 5, 15, 'home_plus5');
        $this->r['done'] = $mk(300, 'Student', 'done', 10, 24);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_owner_and_admin_see_the_list_agent_does_not(): void
    {
        $this->as($this->owner);
        Livewire::test(ExpireDates::class)->assertOk()
            ->assertCanSeeTableRecords([$this->r['student'], $this->r['home'], $this->r['done']])
            ->assertSee('23 → 26');
        $this->as($this->admin);
        $this->assertTrue(ExpireDates::canAccess());
        $this->as($this->agent);
        $this->assertFalse(ExpireDates::canAccess());
    }

    public function test_ticking_out_and_back_in(): void
    {
        $this->as($this->owner);
        Livewire::test(ExpireDates::class)
            ->callTableBulkAction('untick', [$this->r['student'], $this->r['done']]);
        $this->assertSame('skipped', $this->r['student']->fresh()->status);
        $this->assertSame('done', $this->r['done']->fresh()->status, 'a written row never changes from the list');
        Livewire::test(ExpireDates::class)->callTableBulkAction('tick', [$this->r['student']]);
        $this->assertSame('preview', $this->r['student']->fresh()->status);
    }

    public function test_type_filter(): void
    {
        $this->as($this->owner);
        Livewire::test(ExpireDates::class)->filterTable('type', 'home')
            ->assertCanSeeTableRecords([$this->r['home']])->assertCanNotSeeTableRecords([$this->r['student']]);
    }

    public function test_only_the_owner_runs_and_the_engine_gets_the_request(): void
    {
        Http::fake(['*/expire-dates/start' => Http::response(['started' => true])]);
        $this->as($this->admin);
        Livewire::test(ExpireDates::class)->assertActionHidden('run');
        $this->as($this->owner);
        Livewire::test(ExpireDates::class)->callAction('run')->assertNotified();
        Http::assertSent(fn ($req) => str_contains($req->url(), "/internal/{$this->company->id}/expire-dates/start")
            && $req['mode'] === 'run' && $req['user_id'] === $this->owner->id);
        Livewire::test(ExpireDates::class)->callTableAction('rollback', $this->r['done']);
        Http::assertSent(fn ($req) => $req['mode'] === 'rollback' && $req['ids'] === [$this->r['done']->id]);
    }

    public function test_auto_switch_is_saved_on_the_billing_page(): void
    {
        $this->as($this->owner);
        Livewire::test(BillingSettings::class)
            ->fillForm(['expire_auto' => true, 'expire_min_off_hours' => 24, 'expire_min_paid_pct' => 85, 'expire_max_day' => 28])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue(BillingConnection::where('company_id', $this->company->id)->value('expire_auto'));
    }
}
