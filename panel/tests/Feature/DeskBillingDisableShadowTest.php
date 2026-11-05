<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** In-house billing phase 2: বন্ধের তালিকা মিল page (read only, owner only). */
class DeskBillingDisableShadowTest extends TestCase
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
        $a = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 7, 'customer_code' => '0201',
            'name' => 'Rahim Mia', 'status' => 'Active', 'monthly_bill' => 500]);
        $b = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 8, 'customer_code' => '0202',
            'name' => 'Karim Mia', 'status' => 'Active', 'monthly_bill' => 500]);
        DB::table('desk_disable_shadow')->insert([
            ['company_id' => $c, 'day' => '2026-11-06', 'customer_ref' => $a, 'decision' => 'disable', 'reason' => 'due_today',
                'due' => 500, 'expire_on' => '2026-11-05', 'deadline' => '2026-11-06', 'result' => 'desk_only', 'hint' => 'paid_later'],
            ['company_id' => $c, 'day' => '2026-11-06', 'customer_ref' => $b, 'decision' => 'disable', 'reason' => 'due_today',
                'due' => 500, 'expire_on' => '2026-11-05', 'deadline' => '2026-11-06', 'result' => 'match', 'hint' => null],
        ]);
    }

    public function test_only_the_owner_sees_the_list(): void
    {
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->admin)->get($base.'/billing-disable-shadow')->assertForbidden();
        $this->actingAs($this->owner)->get($base.'/billing-disable-shadow')->assertOk()
            ->assertSee('Rahim Mia')->assertDontSee('Karim Mia')        // only the mismatches by default
            ->assertSee(__('তালিকার পর বিল দিয়েছেন'))->assertSee('06 Nov 2026');
        $this->actingAs($this->owner)->get($base.'/billing-rules')->assertOk()->assertSee(__('বন্ধের তালিকা মিল'))
            ->assertSee(__('এর চেয়ে কম বকেয়ায় বন্ধ নয়'));
    }

    public function test_the_owner_asks_the_engine_for_todays_list(): void
    {
        Http::fake(['engine.test/*' => Http::response(['started' => true])]);
        $this->actingAs($this->owner);
        \Filament\Facades\Filament::setCurrentPanel('app');
        \Filament\Facades\Filament::setTenant($this->company);
        \Livewire\Livewire::test(\App\Filament\App\Pages\BillingDisableShadow::class)
            ->callAction('make', ['replace' => true])->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => str_contains($r->url(), "/internal/{$this->company->id}/desk-billing/disable-list")
            && str_contains($r->url(), 'replace=1') && str_contains($r->url(), 'day='.now('Asia/Dhaka')->toDateString()));
    }
}
