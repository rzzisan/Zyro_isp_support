<?php

namespace Tests\Feature;

use App\Filament\App\Pages\VoiceReminderSettings;
use App\Filament\App\Resources\VoiceOptouts\Pages\ManageVoiceOptouts;
use App\Filament\App\Resources\VoiceReminderCalls\Pages\ListVoiceReminderCalls;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\SipSetting;
use App\Models\User;
use App\Models\VoiceFlow;
use App\Models\VoiceOptout;
use App\Models\VoiceReminderCall;
use App\Models\VoiceReminderSetting;
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

/** Voice reminder calls by themselves: who is planned when, checked again before the call, retries, line off, test mode. */
class VoiceRemindersTest extends TestCase
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
            '127.0.0.1:8997/*' => Http::response('ok'),
        ]);
        // Thursday 2026-10-29, 11:00 in Dhaka
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

    public function test_calling_hours_and_friday_prayer(): void
    {
        $s = VoiceReminderSetting::forCompany($this->company);
        $at = fn (string $t) => Carbon::parse($t, 'Asia/Dhaka');
        $this->assertTrue(VoiceReminders::inWindow($s, $at('2026-10-29 10:00')));
        $this->assertFalse(VoiceReminders::inWindow($s, $at('2026-10-29 09:59')));
        $this->assertFalse(VoiceReminders::inWindow($s, $at('2026-10-29 20:00')));
        $this->assertFalse(VoiceReminders::inWindow($s, $at('2026-10-30 13:00')));   // Friday prayer
        $this->assertTrue(VoiceReminders::inWindow($s, $at('2026-10-30 15:00')));
    }

    public function test_bill_reminder_is_planned_once_and_queued_within_the_line_limit(): void
    {
        $this->settings();
        $this->customer(1);                        // expires on the 31st: 2 days from the 29th
        $this->customer(2);
        $this->customer(3, ['due' => 0]);          // paid: not called
        $this->customer(4, ['bill_day' => 15]);    // another day
        $out = VoiceReminders::run($this->company->id);
        $this->assertSame(2, $out['planned']);
        $this->assertSame(1, $out['queued']);      // one line at a time
        VoiceReminders::run($this->company->id);   // again: nothing doubles
        $this->assertSame(2, VoiceReminderCall::count());
        $q = VoiceReminderCall::where('status', 'queued')->first();
        $this->assertSame('reminder', $q->kind);
        $this->assertSame('8801711000001', $q->number);
        $this->assertSame('bill:1:2026-10-31:2', $q->dedupe);
        $this->assertSame('বিল রিমাইন্ডার কল', $q->package['flow']['name']);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://127.0.0.1:8997/remind') && $r['id'] == $q->id);
        $this->assertSame(1, VoiceReminderCall::where('status', 'planned')->count());
    }

    public function test_checked_again_right_before_the_call(): void
    {
        $this->settings(['max_calls' => 5]);
        $this->customer(1);
        $this->customer(2);
        $this->customer(3);
        $s = VoiceReminderSetting::forCompany($this->company);
        VoiceReminders::plan($this->company->id, $s, now());
        BillingCustomer::where('header_id', 1)->update(['due' => 0]);
        VoiceOptout::create(['company_id' => $this->company->id, 'number' => '8801711000002', 'source' => 'call']);
        BillingCustomer::where('header_id', 3)->update(['is_left' => true]);
        VoiceReminders::dispatch($this->company->id, $s, now());
        $this->assertSame(['বিল দেওয়া হয়ে গেছে', 'আর কল চান না', 'কাস্টমার নেই বা Left'],
            VoiceReminderCall::orderBy('customer_header_id')->pluck('reason')->all());
        $this->assertSame(3, VoiceReminderCall::where('status', 'skipped')->count());
    }

    public function test_nothing_goes_outside_calling_hours_or_when_off(): void
    {
        $this->customer(1);
        $this->settings(['enabled' => false]);
        $this->assertSame([], VoiceReminders::run($this->company->id));
        $this->settings();
        Carbon::setTestNow(Carbon::parse('2026-10-29 21:00', 'Asia/Dhaka'));
        $out = VoiceReminders::run($this->company->id);
        $this->assertSame(0, $out['planned'] + $out['queued']);
    }

    public function test_test_mode_calls_the_test_number_a_few_times_a_day(): void
    {
        $this->settings(['test_mode' => true, 'test_number' => '8801811000009', 'test_daily_max' => 2, 'max_calls' => 5]);
        foreach (range(1, 4) as $h) {
            $this->customer($h);
        }
        VoiceReminders::run($this->company->id);
        VoiceReminders::run($this->company->id);
        $this->assertSame(2, VoiceReminderCall::count());
        $this->assertSame(['8801811000009'], VoiceReminderCall::distinct()->pluck('number')->all());
        $this->assertStringEndsWith(':test', VoiceReminderCall::first()->dedupe);
        $this->assertSame('Customer 1', VoiceReminderCall::orderBy('id')->first()->package['customer']['name']);
    }

    public function test_line_off_calls_only_lines_that_go_off_after_watching_started(): void
    {
        $this->settings(['reminder_on' => false, 'max_calls' => 5]);
        $this->customer(1, ['disabled' => true]);          // off before
        VoiceReminders::run($this->company->id);
        $this->assertSame(0, VoiceReminderCall::count());
        $this->customer(2, ['disabled' => true]);          // goes off now
        $this->customer(3, ['disabled' => true, 'due' => 0]);   // off, but not for the bill
        VoiceReminders::run($this->company->id);
        $call = VoiceReminderCall::sole();
        $this->assertSame(['line_off', 2, 'planned'], [$call->kind, $call->customer_header_id, $call->status]);
        $this->assertTrue($call->scheduled_at->eq(now()->addMinutes(60)));
        Carbon::setTestNow(now()->addMinutes(61));
        VoiceReminders::run($this->company->id);
        $this->assertSame('queued', $call->fresh()->status);
        $this->assertSame('লাইন বন্ধের কল', $call->fresh()->package['flow']['name']);
        $call->update(['status' => 'no_answer', 'tries' => 2, 'ended_at' => now()]);
        // next day, still off and nobody reached: once more
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:05', 'Asia/Dhaka'));
        VoiceReminders::run($this->company->id);
        $this->assertSame(2, VoiceReminderCall::where('customer_header_id', 2)->count());
        // back on: forgotten
        BillingCustomer::where('header_id', 2)->update(['disabled' => false]);
        VoiceReminders::run($this->company->id);
        $this->assertFalse(DB::table('voice_line_watch')->where('customer_header_id', 2)->exists());
        $this->assertTrue((bool) DB::table('voice_line_watch')->where('customer_header_id', 1)->value('baseline'));
    }

    public function test_retry_after_no_answer_and_planned_calls_expire_next_day(): void
    {
        $s = $this->settings();
        $c = $this->customer(1);
        $row = VoiceReminderCall::create(['company_id' => $this->company->id, 'kind' => 'reminder', 'voice_flow_id' => $s->reminder_flow_id,
            'customer_header_id' => 1, 'number' => $c->mobile_normalized, 'status' => 'no_answer', 'tries' => 1, 'ref_date' => '2026-10-29',
            'ended_at' => now()->subHours(3), 'dedupe' => 'x1']);
        $this->assertSame(1, VoiceReminders::retry($this->company->id, $s, now()));
        $this->assertSame('planned', $row->fresh()->status);
        $row->update(['status' => 'no_answer', 'tries' => 2]);
        $this->assertSame(0, VoiceReminders::retry($this->company->id, $s, now()));
        $row->update(['status' => 'planned']);
        $this->assertSame(1, VoiceReminders::expire($this->company->id, now()->addDay()));
        $this->assertSame('expired', $row->fresh()->status);
    }

    public function test_settings_page_saves_and_the_lists_open(): void
    {
        $this->customer(1);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(VoiceReminderSettings::class)->assertOk()->assertSee('আগামীকাল')
            ->fillForm(['enabled' => true, 'test_mode' => true, 'test_number' => '01811-000009', 'window_from' => '09:00', 'window_to' => '21:00'])
            ->call('save')->assertHasNoFormErrors();
        $s = VoiceReminderSetting::where('company_id', $this->company->id)->first();
        $this->assertTrue($s->enabled);
        $this->assertSame('8801811000009', $s->test_number);
        $this->assertSame('09:00', $s->window_from);
        $this->assertSame(VoiceFlow::where('kind', 'reminder')->value('id'), $s->reminder_flow_id);
        Livewire::test(VoiceReminderSettings::class)->fillForm(['test_number' => '123'])->call('save')->assertHasErrors();
        VoiceReminders::run($this->company->id);
        Livewire::test(ListVoiceReminderCalls::class)->assertOk()->assertSee('Customer 1');
        Livewire::test(ManageVoiceOptouts::class)->assertOk()
            ->callAction(TestAction::make('create')->table(), ['number' => '01711000001'])->assertHasNoActionErrors();
        $this->assertSame('staff', VoiceOptout::sole()->source);
        $this->assertSame(1, VoiceOptout::sole()->customer_header_id);
        $this->artisan('voice:reminders')->assertSuccessful();
    }
}
