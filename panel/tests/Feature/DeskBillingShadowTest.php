<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** In-house billing phase 1: বকেয়ার অমিল and মাসিক বিল মিল pages (read only, owner only). */
class DeskBillingShadowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->admin, ['role' => 'admin']);
        $c = $this->company->id;
        $ref = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 7, 'customer_code' => '0104',
            'name' => 'Rahim Mia', 'status' => 'Active', 'monthly_bill' => 600, 'package_name' => '12M']);
        $ok = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 8, 'customer_code' => '0105',
            'name' => 'Karim Mia', 'status' => 'Active', 'monthly_bill' => 500]);
        DB::table('desk_customer_balances')->insert([
            ['customer_ref' => $ref, 'company_id' => $c, 'balance' => 700, 'billing_due' => 650, 'diff' => 50,
                'mismatch_reason' => 'other', 'mismatch_since' => '2026-11-06', 'has_history' => true, 'excluded' => false],
            ['customer_ref' => $ok, 'company_id' => $c, 'balance' => 500, 'billing_due' => 500, 'diff' => null,
                'mismatch_reason' => null, 'mismatch_since' => null, 'has_history' => true, 'excluded' => false],
        ]);
        DB::table('desk_shadow_bills')->insert([
            ['company_id' => $c, 'period_start' => '2026-12-01', 'customer_ref' => $ref, 'desk_amount' => 600, 'desk_reason' => 'billed',
                'customer_status' => 'Active', 'billing_amount' => 700, 'billing_package' => '15M', 'result' => 'amount_diff', 'hint' => 'package_changed'],
            ['company_id' => $c, 'period_start' => '2026-12-01', 'customer_ref' => $ok, 'desk_amount' => 500, 'desk_reason' => 'billed',
                'customer_status' => 'Active', 'billing_amount' => 500, 'billing_package' => '10M', 'result' => 'match', 'hint' => null],
        ]);
    }

    public function test_only_the_owner_sees_the_mismatches(): void
    {
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->admin)->get($base.'/billing-due-match')->assertForbidden();
        $this->actingAs($this->admin)->get($base.'/billing-shadow-bills')->assertForbidden();

        $this->actingAs($this->owner)->get($base.'/billing-due-match')->assertOk()
            ->assertSee('Rahim Mia')->assertDontSee('Karim Mia')->assertSee(__('খুঁজে দেখতে হবে'));
        $this->actingAs($this->owner)->get($base.'/billing-shadow-bills')->assertOk()
            ->assertSee('Rahim Mia')->assertDontSee('Karim Mia')        // only the mismatches by default
            ->assertSee(__('পরে প্যাকেজ বদলেছে'))->assertSee('Dec 2026');
        $this->actingAs($this->owner)->get($base.'/billing-rules')->assertOk()->assertSee(__('বকেয়ার অমিল'))->assertSee(__('মাসিক বিল মিল'));
    }

    public function test_the_owner_asks_the_engine_for_this_months_shadow_bills(): void
    {
        Http::fake(['engine.test/*' => Http::response(['started' => true])]);
        $this->actingAs($this->owner);
        \Filament\Facades\Filament::setCurrentPanel('app');
        \Filament\Facades\Filament::setTenant($this->company);
        \Livewire\Livewire::test(\App\Filament\App\Pages\BillingShadowBills::class)
            ->callAction('make', ['replace' => true])->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => str_contains($r->url(), "/internal/{$this->company->id}/desk-billing/shadow-bills")
            && str_contains($r->url(), 'replace=1') && str_contains($r->url(), 'period='.now('Asia/Dhaka')->format('Y-m')));
    }
}
