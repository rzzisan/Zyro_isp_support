<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingLineActions;
use App\Filament\App\Pages\BillingRules;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** In-house billing phase 3: লাইন বন্ধ/চালু page (the Desk switches lines on MikroTik itself), owner only. */
class DeskBillingLineActionsTest extends TestCase
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
        BillingSetting::forCompany($this->company)->fill(['line_control' => 'test', 'line_test_codes' => ['0001']])->save();
        DB::table('desk_line_actions')->insert(['company_id' => $this->company->id, 'customer_code' => '0001', 'username' => 'zisan_home',
            'action' => 'disable', 'reason' => 'test', 'status' => 'done', 'router_name' => 'CLN_4', 'profile_before' => '15Mbps',
            'profile_after' => 'BLOCKED-PROFILE', 'attempts' => 1, 'sessions_killed' => 1, 'session_after' => '2s', 'created_at' => now()]);
    }

    public function test_only_the_owner_sees_the_actions(): void
    {
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->admin)->get($base.'/billing-line-actions')->assertForbidden();
        $this->actingAs($this->owner)->get($base.'/billing-line-actions')->assertOk()
            ->assertSee('zisan_home')->assertSee('15Mbps → BLOCKED-PROFILE')->assertSee(__('শুধু পরীক্ষার অ্যাকাউন্টে'));
        $this->actingAs($this->owner)->get($base.'/billing-rules')->assertOk()->assertSee(__('লাইন বন্ধ/চালু (MikroTik)'))
            ->assertSee(__('পরীক্ষার অ্যাকাউন্ট (কাস্টমার ID বা PPPoE ID)'));
    }

    public function test_the_owner_switches_a_line_through_the_engine(): void
    {
        Http::fake(['engine.test/*' => Http::response(['status' => 'done', 'profile_before' => 'BLOCKED-PROFILE',
            'profile_after' => '15Mbps', 'attempts' => 1, 'sessions_killed' => 1])]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(BillingLineActions::class)
            ->callAction('run', ['customer' => ' 0001 ', 'action' => 'enable', 'note' => 'test'])->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => str_contains($r->url(), "/internal/{$this->company->id}/desk-line")
            && $r['customer'] === '0001' && $r['action'] === 'enable' && $r['user_id'] === $this->owner->id);
    }

    public function test_all_customers_needs_the_desk_as_main_billing(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(BillingRules::class)
            ->fillForm(['line_control' => 'all'])->call('save');
        $this->assertSame('test', BillingSetting::where('company_id', $this->company->id)->value('line_control'));
    }
}
