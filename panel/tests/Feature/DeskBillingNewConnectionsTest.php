<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingNewConnections;
use App\Filament\App\Pages\BillingRules;
use App\Filament\App\Pages\BillingSecretCheck;
use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\User;
use App\Support\NewConnectionForm;
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

            if (str_contains($r->url(), '/customer-change')) {
                return Http::response(['status' => 'done', 'note' => 'ok', 'kind' => $r['kind']]);
            }
            if (str_contains($r->url(), '/secret-audit')) {
                return Http::response(['routers' => [['id' => 1, 'identity' => 'CLN-3', 'ok' => true, 'error' => null, 'secrets' => 2]],
                    'missing' => [], 'extra' => [['username' => 'stray', 'router' => 'CLN-3', 'profile' => 'default', 'disabled' => false]],
                    'left_present' => [['header_id' => 16056, 'code' => '6054', 'username' => 'zyrotest01', 'name' => 'T', 'router' => 'CLN-3', 'profile' => 'P-5_525']],
                    'profile_mismatch' => []]);
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

    private function customer(): BillingCustomer
    {
        return BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 16063, 'customer_id' => '6061',
            'username' => 'zyrotest5.phase5', 'name' => 'Zyro Phase5 Test', 'status' => 'Active', 'server' => 'CLN-3',
            'speed' => 'P-5_525', 'package' => '20 Mbps_1260', 'package_id' => 3737, 'monthly_bill' => 1260]);
    }

    public function test_change_package_and_password_go_to_the_engine(): void
    {
        $this->fakeEngine();
        $c = $this->customer();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ViewCustomer::class, ['record' => $c->getRouteKey()])
            ->assertActionVisible('changePackage')
            ->callAction('changePackage', ['package_id' => '3737', 'profile' => 'P-5_525', 'monthly_bill' => 1260])
            ->assertHasNoActionErrors();
        Http::assertSent(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), "/internal/{$this->company->id}/customer-change")
            && $r['kind'] === 'package' && $r['header_id'] === 16063 && $r['params']['profile'] === 'P-5_525');

        $this->actingAs($this->agent);
        Livewire::test(ViewCustomer::class, ['record' => $c->getRouteKey()])->assertActionHidden('changePackage');
    }

    public function test_secret_check_page_owner_only(): void
    {
        $this->fakeEngine();
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->agent)->get($base.'/billing-secret-check')->assertForbidden();
        $this->actingAs($this->owner)->get($base.'/billing-secret-check')->assertOk();
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(BillingSecretCheck::class)->call('runAudit')->assertSee('zyrotest01')->assertSee('stray')
            ->call('removeSecret', 16056);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/customer-change') && $r['kind'] === 'remove' && $r['header_id'] === 16056);
    }

    public function test_username_suggestion_from_the_zone_prefix(): void
    {
        $this->assertSame('bp.rahim6071', NewConnectionForm::usernameSuggestion('bp.', 'Rahim Uddin', 6071));
        $this->assertSame('bp.7', NewConnectionForm::usernameSuggestion('bp.', 'মোঃ রহিম', 7));
        $this->fakeEngine();
        $this->customer();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        $this->assertSame(6062, NewConnectionForm::nextCustomerId());
        Livewire::test(BillingRules::class)->fillForm(['connection_control' => 'test', 'connection_test_prefix' => 'zyro',
            'username_prefixes' => [['zone_id' => '84', 'prefix' => 'bp.']]])->call('save')->assertHasNoFormErrors();
        $this->assertSame([['zone_id' => '84', 'prefix' => 'bp.']], BillingSetting::where('company_id', $this->company->id)->first()->username_prefixes);
        $this->assertSame('bp.', NewConnectionForm::zonePrefix('84'));
    }
}
