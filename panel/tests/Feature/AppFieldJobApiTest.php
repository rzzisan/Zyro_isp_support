<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\BillingOption;
use App\Models\BillingTicket;
use App\Models\BotSetting;
use App\Models\Company;
use App\Models\FieldJob;
use App\Models\Membership;
use App\Models\User;
use App\Models\WaContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** "আমার কাজ" in the app: a technician's own tickets and customers, start / pause / resume, line check, solve rules. */
class AppFieldJobApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $tech;

    private bool $online = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $cid = $this->company->id;
        $this->tech = User::factory()->create(['name' => 'Rifat']);
        $this->company->users()->attach($this->tech, ['role' => 'agent']);
        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['field_jobs']), 'billing_employee_id' => '3']);
        BillingOption::create(['company_id' => $cid, 'kind' => 'employees', 'option_id' => '3', 'name' => 'Rifat']);
        BillingOption::create(['company_id' => $cid, 'kind' => 'employees', 'option_id' => '4', 'name' => 'Maruf']);
        BillingOption::create(['company_id' => $cid, 'kind' => 'categories', 'option_id' => '7', 'name' => 'Line Off']);
        BillingOption::create(['company_id' => $cid, 'kind' => 'categories', 'option_id' => '8', 'name' => 'Router Collect']);
        BotSetting::create(['company_id' => $cid, 'field_job_minutes' => 60,
            'field_job_targets' => [['category_id' => '8', 'minutes' => 30, 'line_check' => false]]]);
        BillingCustomer::create(['company_id' => $cid, 'header_id' => 15630, 'customer_id' => '5629', 'name' => 'Bulbul',
            'username' => 'tld.bulbul', 'mobile' => '01838216754', 'mobile_normalized' => '8801838216754']);
        BillingCustomer::create(['company_id' => $cid, 'header_id' => 15700, 'customer_id' => '5700', 'name' => 'Other',
            'username' => 'tld.other', 'mobile' => '01711111111']);
        Http::fake(function ($request) use ($cid) {
            $url = $request->url();

            return match (true) {
                str_contains($url, "/internal/{$cid}/monitor/") => Http::response(['online' => $this->online, 'uptime' => '5m']),
                str_ends_with($url, '/solve') => Http::response(['ok' => true]),
                str_contains($url, '/ticket-info') => Http::response(['mikrotik' => ['online' => $this->online]]),
                default => Http::response([], 404),
            };
        });
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

    private function ticket(string $no, string $assigned, string $category = 'Line Off', int $header = 15630): BillingTicket
    {
        return BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => $no, 'state' => 'processing',
            'customer_header_id' => $header, 'customer_id' => '5629', 'customer_name' => 'Bulbul', 'mobile' => '01838216754',
            'category' => $category, 'assigned_to' => $assigned, 'opened_at' => now()->subHour()]);
    }

    public function test_only_own_tickets_and_their_customers(): void
    {
        $mine = $this->ticket('1', 'Maruf (10-07-26), Rifat');
        $theirs = $this->ticket('2', 'Maruf', header: 15700);

        $this->api($this->tech)->getJson($this->url('my-jobs'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.complain_id', '1')->assertJsonPath('linked', true);
        $this->api($this->tech)->getJson($this->url('my-jobs/'.$theirs->id))->assertNotFound();
        $this->api($this->tech)->getJson($this->url('my-jobs/'.$mine->id))->assertOk()
            ->assertJsonPath('target_minutes', 60)->assertJsonPath('line_check', true)->assertJsonPath('customer.name', 'Bulbul');

        // their customer's page and chat open; anyone else's do not, nor the customer list or tickets
        $this->api($this->tech)->getJson($this->url('customers/15630'))->assertOk();
        $this->api($this->tech)->getJson($this->url('customers/15700'))->assertForbidden();
        $this->api($this->tech)->getJson($this->url('customers'))->assertForbidden();
        $this->api($this->tech)->getJson($this->url('tickets'))->assertForbidden();
        $chat = WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801838216754', 'name' => 'Bulbul']);
        $other = WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801711111111', 'name' => 'Other']);
        // the chat opens only once the work has started, and never someone else's
        $this->api($this->tech)->getJson($this->url('chats/'.$chat->id))->assertForbidden();
        $this->api($this->tech)->getJson($this->url('my-jobs/'.$mine->id))->assertJsonPath('customer.chat_id', null);
        $this->api($this->tech)->postJson($this->url("my-jobs/{$mine->id}/start"))->assertOk();
        $this->api($this->tech)->getJson($this->url('chats/'.$chat->id))->assertOk();
        $this->api($this->tech)->getJson($this->url('my-jobs/'.$mine->id))->assertJsonPath('customer.chat_id', $chat->id);
        $this->api($this->tech)->getJson($this->url('chats/'.$other->id))->assertForbidden();
    }

    public function test_not_linked_or_without_the_menu(): void
    {
        $this->ticket('1', 'Rifat');
        Membership::where('user_id', $this->tech->id)->update(['billing_employee_id' => null]);
        $this->api($this->tech)->getJson($this->url('my-jobs'))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('linked', false);
        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['inbox']), 'billing_employee_id' => '3']);
        $this->api($this->tech)->getJson($this->url('my-jobs'))->assertForbidden();
    }

    public function test_start_pause_resume_and_solve_when_online(): void
    {
        $t = $this->ticket('1', 'Rifat');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), [])->assertStatus(422);   // not started

        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/start"))->assertOk()
            ->assertJsonPath('job.status', 'running')->assertJsonPath('job.target_minutes', 60)->assertJsonPath('job.started_by', 'Rifat');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/pause"), ['reason' => 'মাল লাগবে'])->assertOk()
            ->assertJsonPath('job.status', 'paused')->assertJsonPath('job.pause_reason', 'মাল লাগবে');
        $this->travel(10)->minutes();
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/resume"))->assertOk()->assertJsonPath('job.status', 'running');
        $this->assertGreaterThanOrEqual(600, FieldJob::first()->paused_seconds);

        $this->api($this->tech)->getJson($this->url("my-jobs/{$t->id}/line"))->assertOk()->assertJsonPath('online', true);
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), ['remark' => 'ঠিক'])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tickets/1/solve') && $r['skip_line_check'] === false);
        $job = FieldJob::first();
        $this->assertSame('done', $job->status);
        $this->assertTrue($job->line_online);
        $this->assertSame(['start', 'pause', 'resume', 'solve'], $job->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_offline_line_blocks_solve_unless_a_member_may_force(): void
    {
        $this->online = false;
        $t = $this->ticket('1', 'Rifat');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/start"))->assertOk();
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), [])->assertStatus(422);
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), ['force_reason' => 'কাস্টমার রাউটার বন্ধ রেখেছে'])
            ->assertStatus(422);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/solve'));

        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['field_jobs', 'force_solve'])]);
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), ['force_reason' => 'কাস্টমার রাউটার বন্ধ রেখেছে'])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tickets/1/solve') && $r['skip_line_check'] === true);
        $job = FieldJob::first();
        $this->assertSame('done', $job->status);
        $this->assertSame('কাস্টমার রাউটার বন্ধ রেখেছে', $job->force_reason);
    }

    public function test_category_without_line_check(): void
    {
        $this->online = false;
        $t = $this->ticket('1', 'Rifat', 'Router Collect');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/start"))->assertOk()
            ->assertJsonPath('job.target_minutes', 30)->assertJsonPath('job.line_check', false);
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), [])->assertOk();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tickets/1/solve') && $r['skip_line_check'] === true);
    }

    public function test_a_ticket_solved_elsewhere_closes_the_job(): void
    {
        $t = $this->ticket('1', 'Rifat');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/start"))->assertOk();
        $t->update(['state' => 'solved', 'solved_at' => now()]);
        $this->api($this->tech)->getJson($this->url('my-jobs'))->assertOk()->assertJsonPath('data.0.job.status', 'done');
    }

    public function test_a_technician_with_the_inbox_menu_sees_only_running_work_chats(): void
    {
        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['field_jobs', 'inbox'])]);
        $mine = $this->ticket('1', 'Rifat');
        $chat = WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801838216754', 'name' => 'Bulbul', 'last_message_at' => now()]);
        $other = WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801711111111', 'name' => 'Other', 'last_message_at' => now()]);

        $this->api($this->tech)->getJson($this->url('chats'))->assertOk()->assertJsonCount(0, 'data');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$mine->id}/start"))->assertOk();
        $this->api($this->tech)->getJson($this->url('chats'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $chat->id);
        $this->api($this->tech)->getJson($this->url('chats/'.$other->id))->assertForbidden();
        $this->api($this->tech)->postJson($this->url('chats/'.$other->id.'/reply'), ['body' => 'hi'])->assertForbidden();

        // finished work closes the chat again; an admin with the same menus is not limited
        FieldJob::first()->update(['status' => 'done']);
        $this->api($this->tech)->getJson($this->url('chats'))->assertOk()->assertJsonCount(0, 'data');
        Membership::where('user_id', $this->tech->id)->update(['role' => 'admin']);
        $this->api($this->tech)->getJson($this->url('chats/'.$other->id))->assertOk();
    }
}
