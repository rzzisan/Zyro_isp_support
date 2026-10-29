<?php

namespace Tests\Feature;

use App\Filament\App\Resources\VoiceFlows\Pages\ManageVoiceFlows;
use App\Models\BillingCustomer;
use App\Models\CallLog;
use App\Models\Company;
use App\Models\SipSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\VoiceFlow;
use App\Models\VoiceReminderCall;
use App\Models\VoiceTemplate;
use App\Support\VoiceCalls;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Voice reminder call flows: defaults, checks, the call package (audio made ahead, SMS texts) and test calls. */
class VoiceFlowTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private BillingCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake([
            'engine.test/internal/*/voice/tts' => fn (Request $r) => Http::response(['file' => explode('/', $r->url())[4].'/'.substr(sha1($r['text']), 0, 20)]),
            '127.0.0.1:8997/*' => Http::response('ok'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-29 06:00:00', 'UTC'));
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->customer = BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 77, 'customer_id' => '0042',
            'name' => 'Alif Hasan', 'mobile' => '01711000001', 'mobile_normalized' => '8801711000001', 'username' => 'alif', 'status' => 'Active', 'due' => 520,
            'monthly_bill' => 520, 'bill_day' => 31, 'disabled' => true]);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function sip(): void
    {
        SipSetting::create(['company_id' => $this->company->id, 'enabled' => true, 'server' => 'pbx.example.test', 'port' => 5060,
            'username' => '106', 'password' => 'secret']);
    }

    public function test_first_visit_gives_two_working_flows(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageVoiceFlows::class)->assertOk()->assertSee('বিল রিমাইন্ডার কল')->assertSee('লাইন বন্ধের কল');
        $flows = VoiceFlow::where('company_id', $this->company->id)->get();
        $this->assertCount(2, $flows);
        foreach ($flows as $f) {
            $this->assertSame([], VoiceFlow::problems($f->steps));
            $this->assertNotEmpty($f->templateIds());
            $this->assertSame(VoiceTemplate::where('company_id', $this->company->id)->whereIn('id', $f->templateIds())->count(), count($f->templateIds()));
        }
        $line = $flows->firstWhere('kind', 'line_off');
        $this->assertSame(['3', '1', '2', '0', '9'], array_column($line->steps[0]['keys'], 'digit'));
        $this->assertSame('পেমেন্টের নিয়ম', SmsTemplate::find($line->smsTemplateIds()[0])->name);
        Livewire::test(ManageVoiceFlows::class);
        $this->assertSame(2, VoiceFlow::where('company_id', $this->company->id)->count());
    }

    public function test_problems_catch_broken_steps(): void
    {
        $this->assertNotEmpty(VoiceFlow::problems([]));
        $p = VoiceFlow::problems([
            ['name' => 'শুরু', 'templates' => [1], 'keys' => [['digit' => '1', 'action' => 'goto', 'next' => 'নেই'], ['digit' => '1', 'action' => 'sms']]],
            ['name' => 'শুরু', 'templates' => []],
        ]);
        $this->assertCount(5, $p);   // dup name, missing step, dup key, sms without template, step without messages
    }

    public function test_package_has_audio_made_ahead_and_call_time_text(): void
    {
        VoiceFlow::seed($this->company->id);
        $flow = VoiceFlow::where('company_id', $this->company->id)->where('kind', 'line_off')->first();
        $p = VoiceCalls::package($flow, $this->customer);
        $ok = VoiceTemplate::where('company_id', $this->company->id)->where('name', 'লাইন চালু হয়েছে')->first();
        $this->assertSame('{reconnect_message}', $p['audio'][$ok->id]['text']);
        $start = VoiceTemplate::where('company_id', $this->company->id)->where('name', 'লাইন বন্ধ')->first();
        $this->assertMatchesRegularExpression('/^\d+\/[0-9a-f]{20}$/', $p['audio'][$start->id]['file']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/voice/tts') && str_contains($r['text'], 'Alif Hasan, বকেয়া ৫২০ টাকার জন্য'));
        $sms = array_values($p['sms'])[0];
        $this->assertStringContainsString('প্রিয় Alif Hasan, আপনার বকেয়া 520 টাকা', $sms['text']);
        $this->assertSame(['header_id' => 77, 'customer_id' => '0042', 'name' => 'Alif Hasan'], $p['customer']);
        $this->assertSame('Alif Hasan (ID 0042)', $p['label']);
    }

    public function test_test_call_queues_and_kicks_the_voice_bot(): void
    {
        $this->sip();
        $this->as($this->owner);
        VoiceFlow::seed($this->company->id);
        $flow = VoiceFlow::where('company_id', $this->company->id)->where('kind', 'reminder')->first();
        Livewire::test(ManageVoiceFlows::class)
            ->callAction(TestAction::make('testCall')->table($flow), ['number' => '01811-000002', 'customer' => '77'])
            ->assertHasNoActionErrors()->assertNotified('কল যাচ্ছে');
        $call = VoiceReminderCall::first();
        $this->assertSame('8801811000002', $call->number);
        $this->assertSame('test', $call->kind);
        $this->assertSame('queued', $call->status);
        $this->assertSame($this->owner->id, $call->user_id);
        $this->assertSame($flow->id, $call->package['flow']['id']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://127.0.0.1:8997/remind') && $r['id'] == $call->id);
    }

    public function test_test_call_needs_the_hotline_and_a_mobile(): void
    {
        $this->as($this->owner);
        VoiceFlow::seed($this->company->id);
        $flow = VoiceFlow::where('company_id', $this->company->id)->first();
        Livewire::test(ManageVoiceFlows::class)->callAction(TestAction::make('testCall')->table($flow), ['number' => '01811000002'])
            ->assertNotified('হটলাইন (SIP) সেটিং চালু নেই, কল করা যাবে না');
        $this->sip();
        Livewire::test(ManageVoiceFlows::class)->callAction(TestAction::make('testCall')->table($flow), ['number' => '12345'])
            ->assertNotified('সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)');
        $this->assertSame(0, VoiceReminderCall::count());
    }

    public function test_saving_a_flow_with_a_dead_end_is_refused(): void
    {
        $this->as($this->owner);
        VoiceFlow::seed($this->company->id);
        $t = VoiceTemplate::where('company_id', $this->company->id)->value('id');
        Livewire::test(ManageVoiceFlows::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'ভাঙা', 'kind' => 'general',
                'steps' => [['name' => 'শুরু', 'templates' => [$t], 'wait' => 7, 'repeats' => 1,
                    'keys' => [['digit' => '1', 'action' => 'goto', 'next' => 'কোথাও না']]]]])
            ->assertHasActionErrors();   // "then go to" offers only the steps there are
        Livewire::test(ManageVoiceFlows::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'ভাঙা', 'kind' => 'general', 'steps' => [
                ['name' => 'শুরু', 'templates' => [$t], 'wait' => 7, 'repeats' => 1, 'keys' => [['digit' => '0', 'action' => 'replay']]],
                ['name' => 'শুরু', 'templates' => [$t], 'wait' => 7, 'repeats' => 1, 'keys' => []],
            ]])
            ->assertNotified('ফ্লো সেভ হয়নি');
        $this->assertNull(VoiceFlow::where('name', 'ভাঙা')->first());
        Livewire::test(ManageVoiceFlows::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'ঠিক', 'kind' => 'general',
                'steps' => [['name' => 'শুরু', 'templates' => [$t], 'wait' => 7, 'repeats' => 1,
                    'keys' => [['digit' => '0', 'action' => 'replay'], ['digit' => '9', 'action' => 'end']]]]])
            ->assertHasNoActionErrors();
        $f = VoiceFlow::where('name', 'ঠিক')->first();
        $this->assertSame(['0', '9'], array_column($f->steps[0]['keys'], 'digit'));
        $this->assertSame([0], array_keys($f->steps));
    }

    public function test_popup_shows_a_reminder_call_only_while_it_rings_for_staff(): void
    {
        $log = CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'rem-'.str_repeat('ab', 16),
            'caller' => '01711000001', 'caller_normalized' => '8801711000001', 'status' => 'ivr', 'started_at' => now(),
            'channel' => 'reminder', 'direction' => 'out']);
        $url = "/calls/{$this->company->slug}/poll";
        $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonCount(0, 'items');
        $log->update(['status' => 'ringing']);
        $this->actingAs($this->owner)->getJson($url)->assertOk()
            ->assertJsonPath('items.0.channel', 'reminder')->assertJsonPath('items.0.ringing', true)
            ->assertJsonPath('items.0.take', str_repeat('ab', 16))->assertJsonPath('items.0.customers.0.name', 'Alif Hasan');
    }
}
