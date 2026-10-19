<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Android app's ticket API: list and tabs, details with the line, new / assign / solve through the engine. */
class AppTicketApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create(['name' => 'Zisan']);
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 15630, 'customer_id' => '5629',
            'name' => 'Bulbul', 'username' => 'tld.bulbul', 'mobile' => '01838216754', 'monthly_bill' => 525, 'due' => 0]);
        $cid = $this->company->id;
        Http::fake([
            "engine.test/internal/{$cid}/ticket-options" => Http::response([
                'categories' => ['7' => 'Line Off'], 'priorities' => ['2' => 'Medium'], 'departments' => [], 'employees' => ['3' => 'Rafiq']]),
            "engine.test/internal/{$cid}/tickets" => Http::response(['message' => 'Saved', 'complain_id' => '57400']),
            "engine.test/internal/{$cid}/tickets/*/assign" => Http::response(['ok' => true]),
            "engine.test/internal/{$cid}/tickets/57231/solve" => Http::response(['ok' => true]),
            "engine.test/internal/{$cid}/tickets/57232/solve" => Http::response(['detail' => 'কাস্টমার অফলাইন'], 400),
            "engine.test/internal/{$cid}/customers/15630/ticket-info" => Http::response(['mikrotik' => ['online' => true]]),
        ]);
    }

    private function api(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($u->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path, ?Company $company = null): string
    {
        return '/api/v1/c/'.($company ?? $this->company)->slug.'/'.$path;
    }

    private function ticket(string $no, string $state): BillingTicket
    {
        return BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => $no, 'state' => $state,
            'customer_header_id' => 15630, 'customer_id' => '5629', 'customer_name' => 'Bulbul', 'mobile' => '01838216754',
            'category' => 'Line Off', 'opened_at' => now()->subHours(2)]);
    }

    public function test_list_tabs_and_counts(): void
    {
        $this->ticket('57231', 'processing');
        $this->ticket('57232', 'pending');
        $this->ticket('57100', 'solved');

        $res = $this->api($this->owner)->getJson($this->url('tickets'))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('counts.open', 2)->assertJsonPath('counts.pending', 1);
        $this->assertSame('Line Off', $res->json('data.0.category'));
        $this->api($this->owner)->getJson($this->url('tickets?tab=solved'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.complain_id', '57100');
    }

    public function test_details_carry_the_customer_line(): void
    {
        $t = $this->ticket('57231', 'processing');
        $this->api($this->owner)->getJson($this->url('tickets/'.$t->id))->assertOk()
            ->assertJsonPath('ticket.complain_id', '57231')->assertJsonPath('live.mikrotik.online', true);
    }

    public function test_options_and_a_new_ticket_without_billing_sms(): void
    {
        $this->ticket('57231', 'pending');
        $this->api($this->owner)->getJson($this->url('ticket-options?header_id=15630'))->assertOk()
            ->assertJsonPath('categories.0.name', 'Line Off')->assertJsonPath('employees.0.id', '3')
            ->assertJsonPath('open_ticket', '57231')->assertJsonPath('customer.username', 'tld.bulbul');

        $this->api($this->owner)->postJson($this->url('tickets'), ['header_id' => 15630, 'category_id' => '7', 'priority' => 2,
            'mobile' => '01838216754', 'comment' => 'লাল বাতি', 'employees' => ['3']])
            ->assertOk()->assertJsonPath('complain_id', '57400');
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/tickets"
            && $r['username'] === 'tld.bulbul' && $r['sms_client'] === false && $r['user_id'] === $this->owner->id);
    }

    public function test_assign_and_solve(): void
    {
        $open = $this->ticket('57231', 'processing');
        $offline = $this->ticket('57232', 'processing');
        $done = $this->ticket('57100', 'solved');

        $this->api($this->owner)->postJson($this->url('tickets/'.$open->id.'/assign'), ['employees' => ['3']])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tickets/57231/assign'));
        $this->api($this->owner)->postJson($this->url('tickets/'.$open->id.'/solve'), ['remark' => 'ঠিক হয়েছে'])->assertOk();
        $this->api($this->owner)->postJson($this->url('tickets/'.$offline->id.'/solve'), [])
            ->assertStatus(422)->assertJsonPath('message', 'কাস্টমার অফলাইন');
        $this->api($this->owner)->postJson($this->url('tickets/'.$done->id.'/solve'), [])->assertStatus(422);
    }

    public function test_other_companies_and_members_without_the_tickets_menu_are_refused(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        $theirs = BillingTicket::create(['company_id' => $other->id, 'complain_id' => '1', 'state' => 'pending', 'opened_at' => now()]);
        $this->api($this->owner)->getJson($this->url('tickets/'.$theirs->id))->assertNotFound();

        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        Membership::where('user_id', $agent->id)->update(['permissions' => json_encode(['customers'])]);
        $this->api($agent)->getJson($this->url('tickets'))->assertForbidden();
    }
}
