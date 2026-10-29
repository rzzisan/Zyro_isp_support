<?php

namespace Tests\Feature;

use App\Filament\App\Pages\CallLogs;
use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Models\CallLog;
use App\Models\Company;
use App\Models\Membership;
use App\Models\SipSetting;
use App\Models\User;
use App\Support\CallDuty;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Who the app rings for calls (duty hours, the app's "কল নিচ্ছি" switch) and the call-back list. */
class CallDutyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        SipSetting::create(['company_id' => $this->company->id]);
    }

    private function api(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->owner->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path): string
    {
        return '/api/v1/c/'.$this->company->slug.'/'.$path;
    }

    public function test_duty_hours_by_day_and_time_in_dhaka(): void
    {
        $at = fn (string $t) => Carbon::parse($t, 'Asia/Dhaka');   // 2026-10-31 is a Saturday
        $this->assertTrue(CallDuty::inHours(null, $at('2026-10-31 03:00')));
        $h = ['days' => [6, 7, 1, 2, 3, 4], 'from' => '10:00', 'to' => '20:00'];
        $this->assertTrue(CallDuty::inHours($h, $at('2026-10-31 10:00')));
        $this->assertFalse(CallDuty::inHours($h, $at('2026-10-31 20:00')));
        $this->assertFalse(CallDuty::inHours($h, $at('2026-10-30 12:00')));   // Friday off
        $night = ['days' => [5], 'from' => '22:00', 'to' => '06:00'];
        $this->assertTrue(CallDuty::inHours($night, $at('2026-10-30 23:00')));
        $this->assertTrue(CallDuty::inHours($night, $at('2026-10-31 05:00')));   // still Friday's shift
        $this->assertFalse(CallDuty::inHours($night, $at('2026-10-31 23:00')));
        $this->assertSame('শনি, রবি, সোম, মঙ্গল, বুধ, বৃহস্পতি 10:00-20:00', CallDuty::label($h));
        $this->assertSame('সবসময়', CallDuty::label(null));
    }

    public function test_owner_sets_duty_hours_on_the_team_page(): void
    {
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $m = Membership::where('user_id', $agent->id)->first();
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('edit')->table($m), ['role' => 'agent',
                'call_hours' => ['days' => [6, 7], 'from' => '10:00', 'to' => '18:00'], 'calls_paused' => true])
            ->assertHasNoFormErrors();
        $m->refresh();
        $this->assertSame([6, 7], array_map('intval', $m->call_hours['days']));
        $this->assertSame('10:00', substr($m->call_hours['from'], 0, 5));
        $this->assertTrue($m->calls_paused);
    }

    public function test_app_switch_pauses_and_resumes_calls(): void
    {
        $this->api()->getJson($this->url('call-duty'))->assertOk()->assertJson(['taking' => true, 'hours' => 'সবসময়', 'on_duty' => true]);
        $this->api()->postJson($this->url('call-duty'), ['taking' => false])->assertOk()->assertJsonPath('taking', false);
        $m = Membership::where('user_id', $this->owner->id)->first();
        $this->assertTrue($m->calls_paused);
        $this->assertFalse(CallDuty::taking($m));
        $this->api()->postJson($this->url('call-duty'), ['taking' => true])->assertOk()->assertJsonPath('taking', true);
        $this->assertFalse($m->fresh()->calls_paused);
    }

    public function test_call_back_list_and_done(): void
    {
        $log = CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'x1', 'caller' => '01711000001', 'status' => 'ended',
            'started_at' => now(), 'callback_reason' => 'রিমাইন্ডার কলে ভয়েস মেসেজ রেখেছেন', 'ring_note' => 'বকেয়া ৫২০ টাকা']);
        CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'x2', 'caller' => '01711000002', 'status' => 'ended', 'started_at' => now()]);
        $this->assertCount(1, $this->api()->getJson($this->url('calls?callback=1'))->json('data'));
        $this->api()->postJson($this->url("calls/{$log->id}/callback-done"))->assertOk()
            ->assertJsonPath('call.callback_done', true)->assertJsonPath('call.callback_done_by', $this->owner->name);
        $this->assertCount(0, $this->api()->getJson($this->url('calls?callback=1'))->json('data'));

        $log2 = CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'x3', 'caller' => '01711000003', 'status' => 'ended',
            'started_at' => now(), 'callback_reason' => 'কল ব্যাক চান']);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->actingAs($this->owner, 'web');
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        $this->assertSame('1', CallLogs::getNavigationBadge());
        Livewire::test(CallLogs::class)->callAction(TestAction::make('callbackDone')->table($log2))->assertNotified();
        $this->assertNotNull($log2->fresh()->callback_done_at);
        $this->assertNull(CallLogs::getNavigationBadge());
    }

    public function test_popup_carries_the_ring_note(): void
    {
        CallLog::create(['company_id' => $this->company->id, 'call_ref' => 'rem-'.str_repeat('cd', 16), 'caller' => '01711000001',
            'caller_normalized' => '8801711000001', 'status' => 'ringing', 'started_at' => now(), 'channel' => 'reminder', 'direction' => 'out',
            'ring_note' => 'বিল রিমাইন্ডার কল · বকেয়া ৫২০ টাকা']);
        $this->actingAs($this->owner)->getJson("/calls/{$this->company->slug}/poll")->assertOk()
            ->assertJsonPath('items.0.note', 'বিল রিমাইন্ডার কল · বকেয়া ৫২০ টাকা');
    }
}
