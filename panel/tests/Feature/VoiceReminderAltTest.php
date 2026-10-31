<?php

namespace Tests\Feature;

use App\Filament\App\Pages\VoiceReminderReport;
use App\Filament\App\Resources\CustomerAltNumbers\Pages\ManageCustomerAltNumbers;
use App\Filament\App\Resources\Customers\History\VoiceCallsTable;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\CustomerAltNumber;
use App\Models\SipSetting;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Models\VoiceFlow;
use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
use App\Support\AltNumbers;
use App\Support\VoiceReminders;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Voice reminder step 4: other numbers when the own one is not answered, SMS only as a backup for regular answerers, the report. */
class VoiceReminderAltTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake([
            'engine.test/internal/*/voice/tts' => fn (Request $r) => Http::response(['file' => explode('/', $r->url())[4].'/'.substr(sha1($r['text']), 0, 20)]),
            'engine.test/*' => Http::response(['started' => true]),
            '127.0.0.1:8997/*' => Http::response('ok'),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-29 11:00', 'Asia/Dhaka'));
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        SipSetting::create(['company_id' => $this->company->id, 'enabled' => true, 'password' => 'x']);
        VoiceFlow::seed($this->company->id);
    }

    private function settings(array $a = []): VoiceReminderSetting
    {
        $s = VoiceReminderSetting::forCompany($this->company);
        $s->fill($a + ['enabled' => true, 'test_mode' => false])->save();

        return $s->fresh();
    }

    private function customer(int $header, array $a = []): BillingCustomer
    {
        return BillingCustomer::create($a + ['company_id' => $this->company->id, 'header_id' => $header, 'customer_id' => (string) (1000 + $header),
            'name' => "Customer {$header}", 'mobile' => '0171100'.str_pad((string) $header, 4, '0', STR_PAD_LEFT),
            'mobile_normalized' => '880171100'.str_pad((string) $header, 4, '0', STR_PAD_LEFT), 'status' => 'Active',
            'due' => 500, 'monthly_bill' => 500, 'bill_day' => 31, 'disabled' => false]);
    }

    private function ticket(int $header, string $mobile): void
    {
        DB::table('billing_tickets')->insert(['company_id' => $this->company->id, 'complain_id' => (string) random_int(1, 999999),
            'customer_header_id' => $header, 'mobile' => $mobile, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A main call that rang out every try. */
    private function unanswered(BillingCustomer $c, array $a = []): VoiceReminderCall
    {
        return VoiceReminderCall::create($a + ['company_id' => $this->company->id, 'kind' => 'reminder',
            'voice_flow_id' => VoiceReminderSetting::forCompany($this->company)->reminder_flow_id, 'customer_header_id' => $c->header_id,
            'customer_id' => $c->customer_id, 'name' => $c->name, 'number' => $c->mobile_normalized, 'status' => 'no_answer', 'tries' => 2,
            'dedupe' => "bill:{$c->header_id}:2026-10-31:2", 'ref_date' => '2026-10-29', 'ended_at' => now()]);
    }

    private function smsReady(): void
    {
        $cid = $this->company->id;
        $tpl = array_key_first(SmsTemplate::optionsFor($cid, 'bill_reminder'));
        SmsTemplate::whereKey($tpl)->update(['body' => '{name}: মেয়াদ {expire_date}, বকেয়া {due}']);
        $this->smsGateway($cid);
        SmsSetting::forCompany($cid)->fill(['enabled' => true, 'reminder_sms' => true, 'reminder_template_id' => $tpl,
            'reminder_days' => '2', 'reminder_hour' => 10, 'reminder_due_only' => true])->save();
    }

    public function test_other_numbers_come_from_the_history_without_shared_or_opted_out_ones(): void
    {
        $c = $this->customer(1, ['phone' => '01811-000001']);
        $this->ticket(1, '01911000001');                       // a ticket opened from another number
        $this->ticket(1, '01711000001');                       // their own number: not "other"
        DB::table('wa_contacts')->insert(['company_id' => $this->company->id, 'wa_number' => '8801611000001', 'customer_id' => '1001',
            'created_at' => now(), 'updated_at' => now()]);
        // the technician's number: on tickets of two other customers too
        $this->customer(2);
        $this->customer(3);
        foreach ([1, 2, 3] as $h) {
            $this->ticket($h, '01511999999');
        }
        DB::table('voice_optouts')->insert(['company_id' => $this->company->id, 'number' => '8801911000001', 'source' => 'call',
            'created_at' => now(), 'updated_at' => now()]);

        $alts = AltNumbers::for($c);
        $this->assertSame(['8801811000001', '8801611000001'], $alts->pluck('number')->all());   // profile first, then WhatsApp
        $this->assertSame(['profile'], $alts[0]['sources']);
        $this->assertSame(3, DB::table('customer_alt_numbers')->where('customer_header_id', 1)->count());   // the opted-out one is kept, only calls skip it
    }

    public function test_unanswered_call_tries_the_other_numbers_one_by_one_then_settles(): void
    {
        $this->settings(['alt_max' => 2]);
        $c = $this->customer(1, ['phone' => '01811000001']);
        $this->ticket(1, '01911000001');
        $this->ticket(1, '01611000001');
        $main = $this->unanswered($c);

        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $alt = VoiceReminderCall::where('alt_of', $main->id)->sole();
        $this->assertSame(['8801811000001', '8801811000001', 'profile', 'planned'], [$alt->number, $alt->alt_number, $alt->number_source, $alt->status]);
        $this->assertNull($main->fresh()->settled_at);

        // it goes out with "{called_for}" filled: whose connection the call is about
        VoiceReminders::dispatch($this->company->id, VoiceReminderSetting::forCompany($this->company), now());
        $this->assertSame('queued', $alt->fresh()->status);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/voice/tts') && str_contains((string) $r['text'], 'Customer 1-এর ইন্টারনেট সংযোগের ব্যাপারে'));

        // nobody there either: the next number, then the day's limit (2) ends it
        $alt->update(['status' => 'no_answer', 'tries' => 1]);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $this->assertSame(2, VoiceReminderCall::where('alt_of', $main->id)->count());
        VoiceReminderCall::where('alt_of', $main->id)->update(['status' => 'no_answer', 'tries' => 1]);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $this->assertSame(2, VoiceReminderCall::where('alt_of', $main->id)->count());
        $this->assertFalse($main->fresh()->reached);
        $this->assertNotNull($main->fresh()->settled_at);
    }

    public function test_answer_on_another_number_settles_reached_and_is_remembered(): void
    {
        $this->settings();
        $c = $this->customer(1, ['phone' => '01811000001']);
        $main = $this->unanswered($c);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        VoiceReminderCall::where('alt_of', $main->id)->update(['status' => 'done', 'answered_at' => now(), 'seconds' => 50, 'tries' => 1]);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $this->assertTrue($main->fresh()->reached);
        $this->assertNotNull(DB::table('customer_alt_numbers')->where('number', '8801811000001')->value('reached_at'));
        // a retry is never planned for the other-number call
        $this->assertSame(0, VoiceReminders::retry($this->company->id, VoiceReminderSetting::forCompany($this->company), now()->addHours(3)));
    }

    public function test_retry_left_or_other_numbers_off_wait_or_settle(): void
    {
        $this->settings(['alt_on' => false]);
        $c = $this->customer(1, ['phone' => '01811000001']);
        $main = $this->unanswered($c, ['tries' => 1]);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $this->assertNull($main->fresh()->settled_at);                     // one more try left today
        $main->update(['tries' => 2]);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $this->assertSame(0, VoiceReminderCall::whereNotNull('alt_of')->count());
        $this->assertFalse($main->fresh()->reached);
    }

    public function test_regular_answerers_get_the_sms_only_as_a_backup(): void
    {
        $this->smsReady();
        $s = $this->settings(['reminder_days' => '2']);
        $c = $this->customer(1);          // expires on the 31st
        $other = $this->customer(2);
        // customer 1 answered 2 of the last 3 calls, customer 2 only 1
        foreach ([[1, true], [1, true], [1, false], [2, true], [2, false], [2, false]] as $i => [$h, $reached]) {
            VoiceReminderCall::create(['company_id' => $this->company->id, 'kind' => 'reminder', 'customer_header_id' => $h, 'number' => '8801711000000',
                'status' => $reached ? 'done' : 'no_answer', 'dedupe' => "old:{$i}", 'settled_at' => now()->subDays(30 - $i), 'reached' => $reached]);
        }
        $this->assertSame([1 => true], VoiceReminders::regulars($this->company->id, [1, 2]));
        $covered = VoiceReminders::coversSms($this->company->id, collect([$c, $other]), Carbon::parse('2026-10-31', 'Asia/Dhaka'), 2);
        $this->assertSame([1 => true], $covered);

        // the call is planned as the SMS's stand-in; unreached → the reminder SMS goes now, once
        VoiceReminders::plan($this->company->id, $s, now());
        $main = VoiceReminderCall::where('dedupe', 'bill:1:2026-10-31:2')->sole();
        $this->assertTrue($main->sms_backup);
        $this->assertFalse(VoiceReminderCall::where('dedupe', 'bill:2:2026-10-31:2')->sole()->sms_backup);
        $main->update(['status' => 'no_answer', 'tries' => 2, 'ended_at' => now()]);
        VoiceReminderSetting::where('company_id', $this->company->id)->update(['alt_on' => false]);
        VoiceReminders::settle($this->company->id, VoiceReminderSetting::forCompany($this->company), now(), true);
        $this->assertSame(1, VoiceReminders::backup($this->company->id));
        $this->assertSame(['8801711000001' => 'Customer 1: মেয়াদ 31/10/2026, বকেয়া 500'], SmsMessage::pluck('text', 'mobile')->all());
        $this->assertNotNull($main->fresh()->backup_sms_id);
        $this->assertSame(0, VoiceReminders::backup($this->company->id));

        // in test mode the SMS goes as before
        $s->update(['test_mode' => true]);
        $this->assertSame([], VoiceReminders::coversSms($this->company->id, collect([$c]), Carbon::parse('2026-10-31', 'Asia/Dhaka'), 2));
    }

    public function test_next_call_goes_as_soon_as_a_line_is_free(): void
    {
        $this->settings(['max_calls' => 1, 'reminder_days' => '2']);
        $this->customer(1);
        $this->customer(2);
        $out = VoiceReminders::run($this->company->id);
        $this->assertSame([2, 1], [$out['planned'], $out['queued']]);
        // a quick pass while the first call rings: nothing more; once it ended, the next one at once
        $this->assertSame(0, VoiceReminders::run($this->company->id, null, false)['queued']);
        VoiceReminderCall::where('status', 'queued')->update(['status' => 'done', 'answered_at' => now(), 'ended_at' => now(), 'tries' => 1]);
        $quick = VoiceReminders::run($this->company->id, null, false);
        $this->assertArrayNotHasKey('planned', $quick);
        $this->assertSame(1, $quick['queued']);
        $this->artisan('voice:reminders', ['--for' => 0])->assertSuccessful();
    }

    public function test_nightly_list_keeps_staff_numbers_and_never_brings_back_a_removed_one(): void
    {
        $this->customer(1, ['phone' => '01811000001']);
        $this->customer(2);
        $this->ticket(2, '01911000002');
        $this->assertSame(2, AltNumbers::rebuild($this->company->id));
        $this->assertSame(0, AltNumbers::rebuild($this->company->id));   // again: nothing doubles

        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ManageCustomerAltNumbers::class)
            ->callAction(TestAction::make('create')->table(), ['customer_header_id' => '1', 'number' => '01611 000001'])
            ->assertHasNoErrors()
            ->assertCanSeeTableRecords(CustomerAltNumber::all());
        $staff = CustomerAltNumber::where('number', '8801611000001')->sole();
        $this->assertSame(['staff'], $staff->sourceList());
        $wrong = CustomerAltNumber::where('number', '8801911000002')->sole();
        Livewire::test(ManageCustomerAltNumbers::class)->callAction(TestAction::make('remove')->table($wrong));
        $this->assertNotNull($wrong->fresh()->removed_at);

        AltNumbers::rebuild($this->company->id);
        $this->assertNotNull($wrong->fresh()->removed_at);                // stays off
        $this->assertTrue(CustomerAltNumber::whereKey($staff->id)->exists());   // staff's stays though no history has it
        $this->assertSame([], AltNumbers::list(BillingCustomer::where('header_id', 2)->first())->all());
        $this->assertSame(['8801811000001', '8801611000001'],
            AltNumbers::list(BillingCustomer::where('header_id', 1)->first())->pluck('number')->sort()->reverse()->values()->all());
        $this->artisan('customers:alt-numbers')->assertSuccessful();
    }

    public function test_the_new_pages_open_for_the_owner(): void
    {
        $this->customer(1, ['phone' => '01811000001']);
        AltNumbers::rebuild($this->company->id);
        // over HTTP, so the panel's tenant scoping runs too (a model without company() breaks only there)
        foreach (['customer-alt-numbers', 'voice-reminder-report', 'voice-reminder-settings', 'voice-reminder-calls', 'voice-optouts'] as $page) {
            $this->actingAs($this->owner)->get('/app/'.$this->company->slug.'/'.$page)->assertOk();
        }
    }

    public function test_a_reminder_call_recording_can_be_heard_from_the_reminder_calls_page(): void
    {
        $this->settings();
        $c = $this->customer(1, ['phone' => '01811000001']);
        $log = \App\Models\CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'r1', 'caller' => '01811000001', 'status' => 'ended',
            'started_at' => now(), 'recording_path' => $this->company->id.'/rec-test-missing.mp3',
            'actions' => [['at' => '16:50:03', 'kind' => 'sms', 'text' => 'SMS', 'ok' => true]]]);
        $call = $this->unanswered($c, ['status' => 'done', 'answered_at' => now(), 'call_log_id' => $log->id]);
        $this->actingAs($this->owner)->get('/app/'.$this->company->slug.'/voice-reminder-calls')->assertOk();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(\App\Filament\App\Resources\VoiceReminderCalls\Pages\ListVoiceReminderCalls::class)
            ->assertTableActionVisible('listen', $call)->mountTableAction('listen', $call)->assertHasNoErrors();
        $html = view('filament.app.call-log-detail', ['call' => $log, 'audio' => route('calls.recording', [$this->company, $log])])->render();
        $this->assertStringContainsString('16:50:03', $html);
        // allowed (the file itself is not there in the test)
        $this->get('/calls/'.$this->company->slug.'/recording/'.$log->id)->assertNotFound();
    }

    public function test_line_off_packages_are_made_ahead_for_callers(): void
    {
        $off = $this->customer(1, ['disabled' => true]);
        $this->customer(2, ['disabled' => true, 'due' => 0]);   // off, nothing due: not ours
        $this->customer(3);                                       // on
        $s = $this->settings(['enabled' => false, 'inbound_on' => true]);
        $ready = fn () => DB::table('voice_inbound_ready')->where('company_id', $this->company->id)->orderBy('customer_header_id')->pluck('customer_header_id')->all();

        $this->assertSame(1, VoiceReminders::run($this->company->id)['inbound_ready'] ?? null);   // runs while the calls are off
        $this->assertSame([1], $ready());
        $p = json_decode(DB::table('voice_inbound_ready')->where('customer_header_id', 1)->value('package'), true);
        $this->assertSame($s->line_off_flow_id, $p['flow']['id']);
        $this->assertSame('1001', $p['customer']['customer_id']);
        $this->assertNotEmpty($p['audio']);

        $this->assertSame(0, VoiceReminders::prepareInbound($this->company->id, $s));   // nothing changed: not made again
        Carbon::setTestNow(now()->addDay());                                              // a new day alone changes nothing
        $this->assertSame(0, VoiceReminders::prepareInbound($this->company->id, $s));
        $off->update(['due' => 700]);
        $this->assertSame(1, VoiceReminders::prepareInbound($this->company->id, $s));
        $off->update(['disabled' => false]);                                              // back on: removed
        VoiceReminders::prepareInbound($this->company->id, $s);
        $this->assertSame([], $ready());

        // test mode: only the example, for the test number
        $off->update(['disabled' => true]);
        $s->update(['test_mode' => true, 'test_number' => '8801811000009']);
        VoiceReminders::prepareInbound($this->company->id, $s->fresh());
        $this->assertSame([0], $ready());

        $s->update(['inbound_on' => false]);
        VoiceReminders::prepareInbound($this->company->id, $s->fresh());
        $this->assertSame([], $ready());
        $this->actingAs($this->owner)->get('/app/'.$this->company->slug.'/voice-reminder-settings')->assertOk()->assertSee('লাইন বন্ধ কাস্টমার নিজে কল দিলে');
    }

    public function test_line_off_packages_rest_when_no_audio_can_be_made(): void
    {
        $this->customer(1, ['disabled' => true]);
        $s = $this->settings(['enabled' => false, 'inbound_on' => true]);
        $tts = 0;
        Http::swap(new \Illuminate\Http\Client\Factory);
        // messages the same for everyone come from the engine's cache; a customer's own text fails (quota used up)
        Http::fake(['engine.test/internal/*/voice/tts' => function (Request $r) use (&$tts) {
            if (str_contains($r['text'], 'Customer 1') || str_contains($r['text'], '৫০০')) {
                $tts++;

                return Http::response(['detail' => 'Gemini 429'], 502);
            }

            return Http::response(['file' => '1/'.substr(sha1($r['text']), 0, 20)]);
        }]);
        $this->assertSame(0, VoiceReminders::prepareInbound($this->company->id, $s));
        $asked = $tts;
        $this->assertGreaterThan(0, $asked);
        $this->assertSame(0, VoiceReminders::prepareInbound($this->company->id, $s));   // resting: nothing asked
        $this->assertSame($asked, $tts);
        Carbon::setTestNow(now()->addMinutes(VoiceReminders::INBOUND_PAUSE + 1));
        VoiceReminders::prepareInbound($this->company->id, $s);
        $this->assertGreaterThan($asked, $tts);
    }

    public function test_report_and_customer_tab_render(): void
    {
        $this->settings();
        $c = $this->customer(1, ['phone' => '01811000001', 'last_payment_date' => '2026-10-30']);
        $this->unanswered($c, ['status' => 'done', 'answered_at' => now(), 'seconds' => 70, 'keys' => '1 0', 'result' => 'sms', 'tries' => 1]);
        $this->unanswered($c, ['dedupe' => 'x', 'status' => 'no_answer']);
        $this->unanswered($c, ['dedupe' => 'y:test', 'status' => 'done', 'answered_at' => now()]);   // test: not counted
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);

        $page = Livewire::test(VoiceReminderReport::class);
        $r = $page->instance()->report();
        $this->assertSame(['calls' => 2, 'answered' => 1, 'customers' => 1, 'reached' => 1, 'alt' => 0, 'alt_answered' => 0, 'paid' => 1,
            'minutes' => 2, 'cost' => 1.0, 'sms_saved' => 0, 'sms_backup' => 0], $r['total']);
        $this->assertSame(['0' => 1, '1' => 1], $r['keys']);
        $page->assertSee('৩ দিনের মধ্যে পেমেন্ট');

        AltNumbers::rebuild($this->company->id);
        Livewire::test(VoiceCallsTable::class, ['record' => $c])->assertOk()
            ->assertSee('বিল রিমাইন্ডার SMS-এ জানানো হয়')->assertSee('01811000001');
    }
}
