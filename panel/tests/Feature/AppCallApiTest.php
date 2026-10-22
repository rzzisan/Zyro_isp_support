<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\CallLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\SipSetting;
use App\Models\User;
use App\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppCallApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 7, 'customer_id' => '3967', 'name' => 'Piyas Saha',
            'mobile' => '01577077629', 'mobile_normalized' => '8801577077629', 'due' => 0]);
    }

    private function api(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->agent->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path): string
    {
        return '/api/v1/c/'.$this->company->slug.'/'.$path;
    }

    private function callLog(array $a): CallLog
    {
        return CallLog::create($a + ['company_id' => $this->company->id, 'call_ref' => uniqid(), 'caller' => '+8801577077629',
            'caller_normalized' => '8801577077629', 'status' => 'ended', 'started_at' => now()]);
    }

    public function test_call_log_needs_a_hotline_and_the_calls_menu(): void
    {
        $menus = fn () => $this->api()->getJson('/api/v1/me')->json('companies.0.menus');
        $this->assertNotContains('calls', $menus());
        $this->api()->getJson($this->url('calls'))->assertNotFound();

        SipSetting::create(['company_id' => $this->company->id]);
        $this->assertContains('calls', $menus());
        $this->api()->getJson($this->url('calls'))->assertOk();

        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['customers'])]);
        $this->api()->getJson($this->url('calls'))->assertForbidden();
    }

    public function test_whatsapp_calls_open_the_call_log_without_a_hotline(): void
    {
        $wa = WaAccount::create(['company_id' => $this->company->id, 'waba_id' => '1', 'phone_number_id' => '2', 'access_token' => 'x']);
        $this->api()->getJson($this->url('calls'))->assertNotFound();

        $wa->update(['calling_mode' => 'test']);
        $this->assertContains('calls', $this->api()->getJson('/api/v1/me')->json('companies.0.menus'));
        $this->callLog(['channel' => 'whatsapp', 'bot_answered' => true]);
        $this->api()->getJson($this->url('calls'))->assertOk()->assertJsonPath('data.0.channel', 'whatsapp');
    }

    public function test_calls_list_detail_and_recording(): void
    {
        SipSetting::create(['company_id' => $this->company->id]);
        $this->callLog(['started_at' => now()->subHour()]);
        $bot = $this->callLog(['bot_answered' => true, 'bot_seconds' => 75, 'bot_flag' => 'wants_person', 'callback_reason' => 'বিল নিয়ে কথা',
            'recording_path' => 'test-call.mp3', 'transcript' => [['who' => 'bot', 'text' => 'আসসালামু আলাইকুম', 'at' => 0],
                ['who' => 'caller', 'text' => 'লাইন নেই', 'at' => 4]]]);

        $res = $this->api()->getJson($this->url('calls'))->assertOk();
        $this->assertCount(2, $res->json('data'));
        $res->assertJsonPath('data.0.id', $bot->id)->assertJsonPath('data.0.caller', '01577077629')
            ->assertJsonPath('data.0.flag', 'মানুষ চেয়েছেন')->assertJsonPath('data.0.has_recording', true)
            ->assertJsonPath('data.0.customer.customer_id', '3967')->assertJsonPath('data.1.bot_answered', false);
        $this->assertCount(1, $this->api()->getJson($this->url('calls?who=bot'))->json('data'));
        $this->assertCount(1, $this->api()->getJson($this->url('calls?callback=1'))->json('data'));
        $this->assertCount(2, $this->api()->getJson($this->url('calls?q=01577'))->json('data'));
        $this->assertCount(0, $this->api()->getJson($this->url('calls?q=01999'))->json('data'));

        $this->api()->getJson($this->url('calls/'.$bot->id))->assertOk()
            ->assertJsonPath('call.transcript.1.who', 'caller')->assertJsonPath('call.transcript.1.text', 'লাইন নেই');

        $this->api()->get($this->url('calls/'.$bot->id.'/recording'))->assertNotFound();
        @mkdir(storage_path('app/call-recordings'), 0777, true);
        file_put_contents($file = storage_path('app/call-recordings/test-call.mp3'), 'ID3');
        try {
            $this->api()->get($this->url('calls/'.$bot->id.'/recording'))->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        } finally {
            @unlink($file);
        }
    }
}
