<?php

namespace Tests\Feature;

use App\Models\BillCollection;
use App\Models\BillingCustomer;
use App\Models\BillingDailyBill;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The Android app's API (/api/v1): token sign-in, company membership, menu permissions, bills and receipts. */
class AppApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Company $other;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->other = Company::create(['name' => 'Other ISP']);
        $this->owner = User::factory()->create(['email' => 'owner@example.com', 'password' => 'secret-pass']);
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 3, 'customer_id' => '0003',
            'name' => 'Bulbul', 'username' => 'en.enamul', 'mobile' => '01714080210', 'mobile_normalized' => '8801714080210',
            'monthly_bill' => 525, 'due' => 525, 'status' => 'Active', 'pppoe_password' => 'never-shown']);
        BillingCustomer::create(['company_id' => $this->other->id, 'header_id' => 9, 'customer_id' => '0003',
            'name' => 'Someone Else', 'username' => 'other.user', 'monthly_bill' => 500, 'due' => 0]);
    }

    private function api(User $u): static
    {
        $this->app['auth']->forgetGuards(); // the guard keeps the last request's user between test requests

        return $this->withToken($u->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path, ?Company $company = null): string
    {
        return '/api/v1/c/'.($company ?? $this->company)->slug.'/'.$path;
    }

    public function test_login_returns_a_token_and_the_companies_with_their_menus(): void
    {
        $res = $this->postJson('/api/v1/login', ['email' => 'Owner@Example.com', 'password' => 'secret-pass', 'device' => 'Pixel'])
            ->assertOk()->assertJsonPath('user.email', 'owner@example.com')
            ->assertJsonPath('companies.0.slug', $this->company->slug)->assertJsonPath('companies.0.role', 'owner');
        $this->assertContains('bills', $res->json('companies.0.menus'));
        $this->assertNotEmpty($res->json('token'));
        $this->assertSame('android:Pixel', $this->owner->tokens()->value('name'));

        $this->withToken($res->json('token'))->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.id', $this->owner->id);
    }

    public function test_wrong_password_and_missing_token_are_refused(): void
    {
        $this->postJson('/api/v1/login', ['email' => 'owner@example.com', 'password' => 'nope'])->assertStatus(422);
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->getJson($this->url('customers'))->assertStatus(401);
    }

    public function test_logout_revokes_only_this_phones_token(): void
    {
        $keep = $this->owner->createToken('other phone');
        $this->api($this->owner)->postJson('/api/v1/logout')->assertOk();
        $this->assertSame(1, $this->owner->tokens()->count());
        $this->assertSame($keep->accessToken->id, $this->owner->tokens()->value('id'));
    }

    public function test_another_companys_data_is_not_reachable(): void
    {
        $this->api($this->owner)->getJson($this->url('customers?q=0003', $this->other))->assertStatus(403);
        $this->api($this->owner)->getJson($this->url('customers/9', $this->other))->assertStatus(403);
        $this->api($this->owner)->getJson($this->url('customers/9'))->assertStatus(404);
    }

    public function test_customer_search_and_page_never_show_the_pppoe_password(): void
    {
        $res = $this->api($this->agent)->getJson($this->url('customers?q=3'))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Bulbul')->assertJsonPath('data.0.due', 525);
        $this->assertStringNotContainsString('never-shown', $res->getContent());

        $page = $this->api($this->agent)->getJson($this->url('customers/3'))->assertOk()
            ->assertJsonPath('customer.username', 'en.enamul')->assertJsonPath('can.bill', false);
        $this->assertStringNotContainsString('never-shown', $page->getContent());
        $this->assertStringNotContainsString('pppoe_password', $page->getContent());
    }

    public function test_agent_without_the_bills_menu_cannot_collect(): void
    {
        $this->api($this->agent)->getJson($this->url('customers/3/bill-form'))->assertStatus(403);
        $this->api($this->agent)->postJson($this->url('customers/3/bills'), ['paid' => 525, 'method_id' => '71', 'received_by_id' => '1'])
            ->assertStatus(403);
        $this->api($this->agent)->getJson($this->url('daily-bills'))->assertStatus(403);
    }

    public function test_bill_collection_goes_through_the_engine_with_the_members_login(): void
    {
        $cid = $this->company->id;
        Http::fake([
            "engine.test/internal/{$cid}/customers/3/bill-form*" => Http::response([
                'username' => 'en.enamul', 'status' => 'Active', 'monthly' => 525, 'payable' => 525, 'period_id' => '394',
                'bill_month' => 'October', 'received_from' => 'Bulbul', 'received_by' => ['1' => 'Zisan Ahammed', '3' => 'Arif'],
                'received_by_default' => '1', 'methods' => ['71' => 'Cash', '72' => 'bKash'], 'method_default' => '71',
                'pending' => [], 'blocked' => null]),
            "engine.test/internal/{$cid}/customers/3/bill" => Http::response(['message' => 'Succeeded...!!!', 'daily_bill_id' => 170239,
                'balance' => 0, 'collection_id' => 41]),
        ]);
        Membership::where('company_id', $cid)->where('user_id', $this->agent->id)->update(['permissions' => json_encode(['customers', 'bills'])]);

        $this->api($this->agent)->getJson($this->url('customers/3/bill-form'))->assertOk()
            ->assertJsonPath('payable', 525)->assertJsonPath('methods.0', ['id' => '71', 'name' => 'Cash'])
            ->assertJsonPath('received_by.1', ['id' => '3', 'name' => 'Arif'])->assertJsonPath('sms_ready', false);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bill-form') && str_contains($r->url(), 'user_id='.$this->agent->id));

        $this->api($this->agent)->postJson($this->url('customers/3/bills'), ['paid' => 525, 'discount' => 0, 'method_id' => '71',
            'received_by_id' => '1', 'expected_payable' => 525, 'next_billing_date' => true])
            ->assertOk()->assertJsonPath('collection_id', 41)->assertJsonPath('balance', 0);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/customers/3/bill') && $r['user_id'] === $this->agent->id
            && $r['paid'] === 525.0 && $r['sms'] === false && $r['method_id'] === '71');
    }

    public function test_engine_refusal_comes_back_as_a_message(): void
    {
        $cid = $this->company->id;
        Http::fake(["engine.test/internal/{$cid}/customers/3/bill" => Http::response(['detail' => 'এই কাস্টমারের একটা বিল approve বাকি'], 400)]);
        $this->api($this->owner)->postJson($this->url('customers/3/bills'), ['paid' => 525, 'method_id' => '71', 'received_by_id' => '1'])
            ->assertStatus(422)->assertJsonPath('message', 'এই কাস্টমারের একটা বিল approve বাকি');
    }

    public function test_daily_bills_are_filtered_to_what_the_member_may_see_with_totals(): void
    {
        $cid = $this->company->id;
        $at = now('Asia/Dhaka')->setTime(11, 0)->utc();
        foreach ([[1, 7, 500, true], [2, 7, 300, false], [3, 8, 200, true]] as [$id, $emp, $amount, $approved]) {
            BillingDailyBill::create(['company_id' => $cid, 'daily_bill_id' => $id, 'emp_header_id' => $emp, 'customer_id' => '000'.$id,
                'customer_name' => 'C'.$id, 'collected_at' => $at, 'receive_amount' => $amount, 'is_approved' => $approved]);
        }
        $this->api($this->owner)->getJson($this->url('daily-bills'))->assertOk()
            ->assertJsonPath('totals.count', 3)->assertJsonPath('totals.paid', 1000)->assertJsonCount(3, 'data');
        $this->api($this->owner)->getJson($this->url('daily-bills?status=pending'))->assertJsonPath('totals.paid', 300);

        Membership::where('company_id', $cid)->where('user_id', $this->agent->id)
            ->update(['permissions' => json_encode(['bills']), 'bill_view_employees' => json_encode([7])]);
        $this->api($this->agent)->getJson($this->url('daily-bills'))->assertJsonPath('totals.count', 2)->assertJsonPath('totals.paid', 800);
        // a bill the agent may not see has no receipt for them either
        $this->api($this->agent)->get($this->url('receipts/bill/3'))->assertStatus(404);
        $this->api($this->agent)->get($this->url('receipts/bill/1?w=58'))->assertOk()->assertSee('B-1')->assertDontSee('window.print');
    }

    public function test_collection_receipt_is_the_panel_receipt_without_the_print_dialog(): void
    {
        $c = BillCollection::create(['company_id' => $this->company->id, 'user_id' => $this->owner->id, 'customer_header_id' => 3,
            'customer_id' => '0003', 'customer_name' => 'Bulbul', 'paid' => 525, 'payable' => 525, 'result' => 'saved']);
        $this->api($this->owner)->get($this->url("receipts/collection/{$c->id}?w=80"))->assertOk()
            ->assertSee('D-'.$c->id)->assertSee('Bulbul')->assertDontSee('window.print');
        $this->api($this->owner)->getJson($this->url('collections'))->assertOk()->assertJsonPath('data.0.receipt_no', 'D-'.$c->id);
        $this->api($this->owner)->getJson($this->url('dashboard'))->assertOk()->assertJsonPath('bills.mine_count', 1)
            ->assertJsonPath('bills.mine_paid', 525);
    }
}
