<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\MikrotikRouter;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Android app's online monitoring, customer page details and line on/off. */
class AppMonitoringApiTest extends TestCase
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
        $this->owner = User::factory()->create(['name' => 'Zisan']);
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        $router = MikrotikRouter::create(['company_id' => $this->company->id, 'host' => '10.0.0.1', 'api_port' => 8728, 'username' => 'u', 'password' => 'p']);
        $add = fn (int $h, string $server, array $a = []) => BillingCustomer::create($a + ['company_id' => $this->company->id,
            'header_id' => $h, 'customer_id' => sprintf('%04d', $h), 'name' => "C$h", 'username' => "u$h", 'server' => $server,
            'zone' => 'Tilardi', 'due' => 0, 'disabled' => false]);
        $add(1, 'CLN-3');
        $add(2, 'CLN-3');
        $add(3, 'CLNBD');
        $add(4, 'CLNBD', ['disabled' => true]);     // line off: not monitored
        $session = fn (string $user, $seen, bool $blocked = false) => DB::table('ppp_sessions')->insert(['company_id' => $this->company->id,
            'router_id' => $router->id, 'username' => $user, 'address' => '10.0.0.'.substr($user, 1), 'caller_id' => 'AA:BB',
            'uptime' => '1h', 'seen_at' => $seen, 'blocked' => $blocked, 'profile' => $blocked ? 'block' : '10M']);
        $session('u1', now()->subMinute());
        $session('u2', now()->subHours(3));
        $session('u3', now()->subMinute(), true);
    }

    private function api(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($u->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path): string
    {
        return '/api/v1/c/'.$this->company->slug.'/'.$path;
    }

    public function test_monitoring_lists_lines_that_are_on_with_state_and_server_counts(): void
    {
        $res = $this->api($this->agent)->getJson($this->url('monitoring'))->assertOk();
        $rows = collect($res->json('data'))->keyBy('header_id');
        $this->assertEqualsCanonicalizing([1, 2, 3], $rows->keys()->all());
        $this->assertSame('online', $rows[1]['state']);
        $this->assertSame('10.0.0.1', $rows[1]['ip']);
        $this->assertSame('offline', $rows[2]['state']);
        $this->assertNull($rows[2]['ip']);
        $this->assertNotNull($rows[2]['seen_at']);
        $this->assertSame('blocked', $rows[3]['state']);
        $this->assertSame(['server' => '', 'total' => 3, 'online' => 1, 'blocked' => 1], $res->json('servers.0'));
        $this->assertContains(['server' => 'CLN-3', 'total' => 2, 'online' => 1, 'blocked' => 0], $res->json('servers'));

        $ids = fn (string $qs) => collect($this->api($this->agent)->getJson($this->url('monitoring?'.$qs))->json('data'))->pluck('header_id')->all();
        $this->assertSame([2], $ids('state=offline'));
        $this->assertSame([3], $ids('server=CLNBD'));
        $this->assertSame([1], $ids('q=0001'));
    }

    public function test_router_checks_go_through_the_engine(): void
    {
        $cid = $this->company->id;
        Http::fake(["engine.test/internal/{$cid}/monitor/1/ping" => Http::response(['sent' => 4, 'received' => 4, 'avg_rtt' => '3ms'])]);
        $this->api($this->agent)->postJson($this->url('monitoring/1/ping'))->assertOk()->assertJsonPath('result.received', 4);
        $this->api($this->agent)->postJson($this->url('monitoring/1/reboot'))->assertNotFound();
    }

    public function test_members_without_the_menu_or_without_routers_are_refused(): void
    {
        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['customers'])]);
        $this->api($this->agent)->getJson($this->url('monitoring'))->assertForbidden();
        MikrotikRouter::query()->delete();
        $this->api($this->owner)->getJson($this->url('monitoring'))->assertNotFound();
    }

    public function test_line_switch_needs_the_permission_and_goes_through_the_engine(): void
    {
        $cid = $this->company->id;
        Http::fake(["engine.test/internal/{$cid}/customers/4/line" => function ($request) {
            BillingCustomer::where('header_id', 4)->update(['disabled' => false]);

            return Http::response(['ok' => true]);
        }]);
        $this->api($this->agent)->getJson($this->url('customers/4'))->assertOk()->assertJsonPath('can.line', false);
        $this->api($this->agent)->postJson($this->url('customers/4/line'), ['on' => true])->assertForbidden();

        $this->api($this->owner)->getJson($this->url('customers/4'))->assertJsonPath('can.line', true);
        $this->api($this->owner)->postJson($this->url('customers/4/line'), ['on' => true])->assertOk()
            ->assertJsonPath('customer.disabled', false);
        Http::assertSent(fn ($r) => $r['action'] === 'enable' && $r['user_id'] === $this->owner->id);
    }

    public function test_customer_page_has_billing_details_history_and_line_logs(): void
    {
        BillingCustomer::where('header_id', 1)->update(['package' => '10 Mbps', 'bill_day' => 5, 'father_name' => 'Rahim',
            'latitude' => '23.7', 'longitude' => '90.4']);
        DB::table('billing_customer_line_logs')->insert(['company_id' => $this->company->id, 'customer_header_id' => 1,
            'action' => 'disable', 'changed_by' => 'Zisan', 'changed_at' => now(), 'row_hash' => 'x']);
        $res = $this->api($this->agent)->getJson($this->url('customers/1'))->assertOk();
        $rows = collect($res->json('sections'))->flatMap(fn ($s) => collect($s['rows'])->map(fn ($r) => $r + ['tab' => $s['tab']]))->keyBy('label');
        $this->assertSame('10 Mbps', $rows['প্যাকেজ']['value']);
        $this->assertSame('service', $rows['প্যাকেজ']['tab']);
        $this->assertSame('Rahim', $rows['পিতার নাম']['value']);
        $this->assertSame('23.7, 90.4', $rows['লোকেশন']['value']);
        $this->assertTrue($rows['লোকেশন']['map']);
        $res->assertJsonPath('line_logs.0.action', 'disable')->assertJsonPath('line_logs.0.by', 'Zisan');
    }
}
