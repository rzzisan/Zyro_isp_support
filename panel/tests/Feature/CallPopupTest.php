<?php

namespace Tests\Feature;

use App\Filament\App\Pages\SipSettings;
use App\Models\BillingCustomer;
use App\Models\CallLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\SipSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class CallPopupTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $plan = Plan::create(['name' => 'Team', 'max_agents' => 3]);
        $this->company = Company::create(['name' => 'Century Link Network', 'plan_id' => $plan->id]);
        $this->owner = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
    }

    public function test_owner_saves_sip_settings_password_encrypted_and_kept_when_blank(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        Livewire::test(SipSettings::class)
            ->fillForm(['server' => 'pbx.example.net', 'port' => 5060, 'transport' => 'udp', 'username' => '106', 'password' => 'Sip-Pass_1', 'enabled' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNotSame('Sip-Pass_1', DB::table('sip_settings')->value('password'));
        $this->assertSame('Sip-Pass_1', SipSetting::first()->password);

        Livewire::test(SipSettings::class)->fillForm(['port' => 5080, 'password' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Sip-Pass_1', SipSetting::first()->password);
        $this->assertSame(5080, SipSetting::first()->port);

        Livewire::test(SipSettings::class)->fillForm(['password' => 'bad;pass'])->call('save')->assertHasFormErrors(['password']);
    }

    public function test_agent_cannot_open_sip_settings(): void
    {
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent)->get("/app/{$this->company->slug}/hotline-sip")->assertForbidden();
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/hotline-sip")->assertOk();
    }

    public function test_poll_shows_ringing_caller_with_customer(): void
    {
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 77, 'customer_id' => 'C-77', 'name' => 'Rahim',
            'mobile_normalized' => '8801712345678', 'zone' => 'Rikabi Bazar', 'due' => 500]);
        CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'a1', 'caller' => '01712345678',
            'caller_normalized' => '8801712345678', 'status' => 'ringing', 'started_at' => now()]);
        CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'old', 'caller' => '01800000000',
            'status' => 'ended', 'started_at' => now()->subMinutes(10)]);

        $r = $this->actingAs($this->owner)->getJson("/calls/{$this->company->slug}/poll")->assertOk();
        $r->assertJsonCount(1, 'items');
        $r->assertJsonPath('items.0.caller', '01712345678');
        $r->assertJsonPath('items.0.ringing', true);
        $r->assertJsonPath('items.0.customers.0.name', 'Rahim');

        $other = Company::create(['name' => 'Other ISP', 'plan_id' => $this->company->plan_id]);
        $this->actingAs($this->owner)->getJson("/calls/{$other->slug}/poll")->assertForbidden();
    }

    public function test_voice_bot_settings_keep_test_numbers_in_one_form(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        Livewire::test(SipSettings::class)
            ->fillForm(['server' => 'pbx.example.net', 'port' => 8090, 'transport' => 'udp', 'username' => '106', 'password' => 'Sip-Pass_1',
                'enabled' => true, 'bot_mode' => 'test', 'bot_delay' => 20, 'bot_test_numbers' => "+8801712345678, 01812-345678\njunk"])
            ->call('save')->assertHasNoFormErrors();
        $s = SipSetting::first();
        $this->assertSame('test', $s->bot_mode);
        $this->assertSame("01712345678\n01812345678", $s->bot_test_numbers);

        Livewire::test(SipSettings::class)->fillForm(['bot_delay' => 40])->call('save')->assertHasFormErrors(['bot_delay']);
    }

    public function test_call_log_page_and_recording_are_company_only(): void
    {
        $slug = $this->company->slug;
        $this->actingAs($this->owner)->get("/app/{$slug}/calls")->assertForbidden();   // no hotline set up yet

        SipSetting::create(['company_id' => $this->company->id, 'server' => 'pbx.example.net', 'username' => '106', 'password' => 'x', 'enabled' => true]);
        $call = CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'b1', 'caller' => '01712345678', 'status' => 'ended',
            'started_at' => now(), 'bot_answered' => true, 'bot_seconds' => 42, 'recording_path' => "{$this->company->id}/test.mp3",
            'transcript' => [['who' => 'bot', 'text' => 'আসসালামু আলাইকুম', 'at' => 0]], 'callback_reason' => 'লাইন ঠিক হয়নি']);
        $this->actingAs($this->owner)->get("/app/{$slug}/calls")->assertOk()->assertSee('লাইন ঠিক হয়নি');

        $dir = storage_path("app/call-recordings/{$this->company->id}");
        @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/test.mp3", 'ID3fake');
        try {
            $this->actingAs($this->owner)->get("/calls/{$slug}/recording/{$call->id}")->assertOk();
            $other = Company::create(['name' => 'Other ISP', 'plan_id' => $this->company->plan_id]);
            $this->actingAs($this->owner)->get("/calls/{$other->slug}/recording/{$call->id}")->assertForbidden();
        } finally {
            @unlink("{$dir}/test.mp3");
            @rmdir($dir);
        }
    }

    public function test_popup_script_only_when_sip_is_on(): void
    {
        $page = "/app/{$this->company->slug}";
        $this->actingAs($this->owner)->get($page)->assertDontSee('zyro-calls.js');
        SipSetting::create(['company_id' => $this->company->id, 'server' => 'pbx.example.net', 'username' => '106', 'password' => 'x', 'enabled' => true]);
        $this->actingAs($this->owner)->get($page)->assertSee('zyro-calls.js');
    }
}
