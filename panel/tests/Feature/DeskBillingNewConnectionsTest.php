<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingNewConnections;
use App\Filament\App\Pages\BillingRules;
use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** In-house billing phase 5: নতুন সংযোগ page (the Desk sends a new connection to billing), owner and admins. */
class DeskBillingNewConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        BillingSetting::forCompany($this->company)->fill(['connection_control' => 'test', 'connection_test_prefix' => 'zyrotest'])->save();
        DB::table('desk_connections')->insert(['company_id' => $this->company->id, 'status' => 'done', 'name' => 'Zyro Phase5 Test',
            'mobile' => '01777858289', 'username' => 'zyrotest5.old', 'server_name' => 'CLN-3', 'profile' => 'P-5_525',
            'package_name' => '20 Mbps_1260', 'monthly_bill' => 1260, 'status_name' => 'Active', 'header_id' => 16063,
            'customer_code' => '6061', 'secret_profile' => 'P-5_525', 'secret_disabled' => false, 'created_at' => now()]);
    }

    private function fakeEngine(): void
    {
        $lists = [
            'ServerId' => [['id' => '4', 'name' => 'CLN-3']], 'ZoneId' => [['id' => '84', 'name' => 'Zisan']],
            'ConnectionTypeId' => [['id' => '74', 'name' => 'Cat 5'], ['id' => '76', 'name' => 'Fiber']],
            'PackageId' => [['id' => '3737', 'name' => '20 Mbps_1260']],
            'CustomerTypeId' => [['id' => '5', 'name' => 'Home']],
            'Status' => [['id' => '1', 'name' => 'Active'], ['id' => '4', 'name' => 'Free']],
            'DistrictId' => [], 'ThanaId' => [],
        ];
        Http::fake(function (Request $r) use ($lists) {
            if (str_contains($r->url(), '/new-connection/lists')) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

                return Http::response(match ($q['kind']) {
                    'options' => $lists,
                    'profiles' => ['profiles' => ['default', 'P-5_525']],
                    'bill' => ['bill' => 1260],
                    'username' => ['taken' => false],
                    default => ['items' => []],
                });
            }

            return Http::response(['status' => 'done', 'customer_code' => '6070', 'header_id' => 16100,
                'secret_profile' => 'P-5_525', 'secret_disabled' => false]);
        });
    }

    public function test_owner_sees_the_list_agent_does_not(): void
    {
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->agent)->get($base.'/billing-new-connections')->assertForbidden();
        $this->actingAs($this->owner)->get($base.'/billing-new-connections')->assertOk()
            ->assertSee('zyrotest5.old')->assertSee('6061')->assertSee(__('শুধু পরীক্ষার username'));
        $this->actingAs($this->owner)->get($base.'/billing-rules')->assertOk()
            ->assertSee(__('Desk থেকে নতুন সংযোগ (বিলিং-এও তৈরি)'));
    }

    public function test_the_form_goes_to_the_engine_with_billing_names(): void
    {
        $this->fakeEngine();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(BillingNewConnections::class)
            ->callAction('create', [
                'name' => 'Zyro Phase5 Test', 'mobile' => '01777858289', 'zone_id' => '84', 'server_id' => '4',
                'package_id' => '3737', 'profile' => 'P-5_525', 'monthly_bill' => 1260, 'status_id' => '1',
                'connection_type_id' => '76', 'customer_type_id' => '5', 'username' => 'zyrotest5.t2',
                'password' => bin2hex(random_bytes(5)), 'joined' => '2026-11-07', 'bill_start' => '12/2026', 'expire_day' => 9,
            ])
            ->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), "/internal/{$this->company->id}/new-connection")
            && $r['username'] === 'zyrotest5.t2' && $r['server_name'] === 'CLN-3' && $r['package_name'] === '20 Mbps_1260'
            && $r['status_name'] === 'Active' && $r['joined'] === '2026-11-07' && $r['user_id'] === $this->owner->id);
    }

    public function test_customer_list_button_only_with_the_permission(): void
    {
        $this->fakeEngine();
        $this->actingAs($this->agent);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ListCustomers::class)->assertActionHidden('newCustomer');

        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->agent->id)
            ->update(['permissions' => json_encode(['customers', 'customer_create'])]);
        $this->actingAs($this->agent);
        Livewire::test(ListCustomers::class)->assertActionVisible('newCustomer')
            ->callAction('newCustomer', [
                'name' => 'Agent Test', 'mobile' => '01777858289', 'zone_id' => '84', 'server_id' => '4',
                'package_id' => '3737', 'profile' => 'P-5_525', 'monthly_bill' => 1260, 'status_id' => '1',
                'connection_type_id' => '76', 'customer_type_id' => '5', 'username' => 'zyrotest5.t3',
                'password' => bin2hex(random_bytes(5)), 'joined' => '2026-11-07', 'bill_start' => '12/2026', 'expire_day' => 9,
            ])
            ->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), "/internal/{$this->company->id}/new-connection")
            && $r['username'] === 'zyrotest5.t3' && $r['user_id'] === $this->agent->id);
        $this->get('/app/'.$this->company->slug.'/billing-new-connections')->assertOk();

        $this->actingAs($this->owner);
        Livewire::test(ListCustomers::class)->assertActionVisible('newCustomer');
    }

    public function test_off_switch_disables_the_button(): void
    {
        BillingSetting::where('company_id', $this->company->id)->update(['connection_control' => 'off']);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(BillingNewConnections::class)->assertActionDisabled('create');
    }

    public function test_rules_page_saves_the_switch(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(BillingRules::class)->fillForm(['connection_control' => 'test', 'connection_test_prefix' => ' zyro '])->call('save');
        $s = BillingSetting::where('company_id', $this->company->id)->first();
        $this->assertSame('test', $s->connection_control);
        $this->assertSame('zyro', $s->connection_test_prefix);
    }
}
