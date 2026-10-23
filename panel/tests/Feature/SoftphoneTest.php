<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\BillingTicket;
use App\Models\CallLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\SipAgent;
use App\Models\User;
use App\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SoftphoneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->company = Company::create(['name' => 'Century Link Network']);
        WaAccount::create(['company_id' => $this->company->id, 'waba_id' => '1', 'phone_number_id' => '2', 'access_token' => 'x',
            'calling_mode' => 'live']);
    }

    private function member(string $role, ?array $permissions = null): User
    {
        $user = User::factory()->create();
        $this->company->users()->attach($user, ['role' => $role]);
        if ($permissions !== null) {
            Membership::where('user_id', $user->id)->update(['permissions' => json_encode($permissions)]);
        }

        return $user;
    }

    public function test_softphone_login_needs_the_phone_permission_and_is_made_once(): void
    {
        $url = '/api/v1/c/'.$this->company->slug.'/softphone';
        $agent = $this->member('agent');
        $this->actingAs($agent, 'sanctum')->getJson($url)->assertForbidden();

        $admin = $this->member('admin');
        $first = $this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('ws', 'wss://localhost/sip-ws')->assertJsonPath('can_call_out', false)->json();
        $this->assertSame('a'.SipAgent::first()->id, $first['user']);
        $this->assertSame($first['password'], $this->actingAs($admin, 'sanctum')->getJson($url)->json('password'));
        $this->assertSame(1, SipAgent::count());

        $phone = $this->member('agent', ['customers', 'phone']);
        $this->actingAs($phone, 'sanctum')->getJson($url)->assertOk();
        $this->assertContains('phone', $this->actingAs($phone, 'sanctum')->getJson('/api/v1/me')->json('companies.0.menus'));
    }

    public function test_popup_offers_a_ringing_whatsapp_call_to_phone_members_only(): void
    {
        CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'wa-abc-123', 'caller' => '8801577077629',
            'caller_normalized' => '8801577077629', 'status' => 'ringing', 'started_at' => now(), 'channel' => 'whatsapp']);
        $poll = '/calls/'.$this->company->slug.'/poll';

        $this->actingAs($this->member('agent'))->getJson($poll)->assertOk()
            ->assertJsonPath('items.0.channel', 'whatsapp')->assertJsonPath('items.0.take', null);
        $this->actingAs($this->member('agent', ['customers', 'phone']))->getJson($poll)->assertOk()
            ->assertJsonPath('items.0.take', 'abc-123');
    }

    public function test_call_screen_finds_the_callers_lines_and_open_tickets(): void
    {
        $cid = $this->company->id;
        BillingCustomer::create(['company_id' => $cid, 'header_id' => 11, 'customer_id' => '5629', 'name' => 'Rahim',
            'mobile' => '01577077629', 'mobile_normalized' => '8801577077629', 'status' => 'Active', 'due' => 500]);
        BillingCustomer::create(['company_id' => $cid, 'header_id' => 12, 'customer_id' => '7001', 'name' => 'Other',
            'mobile' => '01711000000', 'mobile_normalized' => '8801711000000', 'status' => 'Active']);
        BillingTicket::create(['company_id' => $cid, 'complain_id' => '900', 'state' => 'pending', 'customer_id' => '5629',
            'customer_header_id' => 11, 'category' => 'Line Off', 'opened_at' => now()]);
        BillingTicket::create(['company_id' => $cid, 'complain_id' => '901', 'state' => 'solved', 'customer_id' => '5629',
            'customer_header_id' => 11, 'category' => 'Slow', 'opened_at' => now()]);
        $url = '/api/v1/c/'.$this->company->slug.'/call-info?number=01577077629';

        $this->actingAs($this->member('agent', ['inbox']), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->member('agent', ['phone']), 'sanctum')->getJson($url)->assertOk()
            ->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.customer.name', 'Rahim')
            ->assertJsonCount(1, 'lines.0.tickets')->assertJsonPath('lines.0.tickets.0.complain_id', '900')
            ->assertJsonPath('can.ticket', false);
    }
}
