<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\BillReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Bill reminder SMS from the Desk: N days before the expire date (bill_day), at the set hour, once per reminder. */
class BillReminderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake(['engine.test/*' => Http::response(['started' => true])]);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $cid = $this->company->id;
        $tpl = array_key_first(SmsTemplate::optionsFor($cid, 'bill_reminder'));
        SmsTemplate::whereKey($tpl)->update(['body' => '{name}: মেয়াদ {expire_date}, আর {days_left} দিন, বকেয়া {due}']);
        SmsSetting::forCompany($cid)->fill(['api_key' => 'a', 'secret_key' => 'b', 'caller_id' => 'CLN', 'enabled' => true,
            'reminder_sms' => true, 'reminder_template_id' => $tpl, 'reminder_days' => '3,0', 'reminder_hour' => 10,
            'reminder_due_only' => true])->save();
        $c = fn (int $h, string $name, int $day, float $due, array $x = []) => BillingCustomer::create(['company_id' => $cid,
            'header_id' => $h, 'customer_id' => (string) $h, 'name' => $name, 'mobile' => '0171100'.str_pad((string) $h, 4, '0', STR_PAD_LEFT),
            'bill_day' => $day, 'due' => $due, 'status' => 'Active', ...$x]);
        $c(1, 'Alif', 18, 525);                       // 3 days after 15 Oct
        $c(2, 'Bulbul', 15, 600);                     // expires today (0 days)
        $c(3, 'Chitra', 18, 0);                       // paid: no reminder
        $c(4, 'Dipu', 18, 500, ['is_left' => true]);  // Left
        $c(5, 'Emon', 18, 500, ['disabled' => true]); // line off
        $c(6, 'Faruk', 20, 500);                      // 5 days: not today
    }

    public function test_days_and_expire_dates(): void
    {
        $this->assertSame([3, 1, 0], BillReminders::days(' 1,3 , 0,3,99'));
        $d = fn (string $s) => Carbon::parse($s, 'Asia/Dhaka');
        $this->assertSame('2026-10-25', BillReminders::expireDate(25, $d('2026-10-15'))->toDateString());
        $this->assertSame('2026-11-25', BillReminders::expireDate(25, $d('2026-10-26'))->toDateString());
        $this->assertSame('2026-11-30', BillReminders::expireDate(31, $d('2026-11-02'))->toDateString());
        $this->assertSame('2026-11-05', BillReminders::expireDate(5, $d('2026-10-31'))->toDateString());
        $this->assertSame('2026-10-15', BillReminders::expireDate(15, $d('2026-10-15'))->toDateString());
    }

    public function test_reminders_go_once_at_the_set_hour(): void
    {
        $cid = $this->company->id;
        $this->assertSame([], BillReminders::run($cid, Carbon::parse('2026-10-15 09:30', 'Asia/Dhaka')));   // before 10 AM
        $this->assertSame(0, SmsMessage::count());

        $this->assertSame([3 => 1, 0 => 1], BillReminders::run($cid, Carbon::parse('2026-10-15 10:05', 'Asia/Dhaka')));
        $this->assertSame([
            '8801711000001' => 'Alif: মেয়াদ 18/10/2026, আর 3 দিন, বকেয়া 525',
            '8801711000002' => 'Bulbul: মেয়াদ 15/10/2026, আর 0 দিন, বকেয়া 600',
        ], SmsMessage::orderBy('mobile')->pluck('text', 'mobile')->all());
        $this->assertSame(['reminder'], SmsMessage::with('campaign')->get()->pluck('campaign.kind')->unique()->values()->all());

        // the next hour (and a manual run) send nothing again
        $this->assertSame([], BillReminders::run($cid, Carbon::parse('2026-10-15 11:05', 'Asia/Dhaka')));
        $this->assertSame(2, SmsMessage::count());
        // the next day Alif is 2 days away (not a reminder day); nothing new
        $this->assertSame([], BillReminders::run($cid, Carbon::parse('2026-10-16 10:05', 'Asia/Dhaka')));
    }

    public function test_paid_customers_too_when_asked_and_off_when_switched_off(): void
    {
        $cid = $this->company->id;
        SmsSetting::where('company_id', $cid)->update(['reminder_due_only' => false]);
        $this->assertSame([3 => 2, 0 => 1], BillReminders::run($cid, Carbon::parse('2026-10-15 10:05', 'Asia/Dhaka')));
        SmsMessage::query()->delete();
        DB::table('sms_reminders')->delete();
        SmsSetting::where('company_id', $cid)->update(['reminder_sms' => false]);
        $this->assertSame([], BillReminders::run($cid, Carbon::parse('2026-10-15 10:05', 'Asia/Dhaka')));
    }

    public function test_preview_and_command(): void
    {
        $cid = $this->company->id;
        $s = SmsSetting::forCompany($cid);
        // on 15 Oct: 3 days ahead = Alif (Chitra paid, Dipu Left, Emon off), that day = Bulbul
        $this->assertSame([3 => 1, 0 => 1], BillReminders::preview($cid, $s, Carbon::parse('2026-10-15', 'Asia/Dhaka')));
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:30', 'Asia/Dhaka'));
        $this->artisan('sms:bill-reminders')->assertSuccessful();
        Carbon::setTestNow();
        $this->assertSame(2, SmsMessage::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), "/internal/{$cid}/sms/kick"));

        $owner = User::factory()->create();
        $this->company->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->get("/app/{$this->company->slug}/sms-gateway")->assertOk()->assertSee('আগামীকাল কতজন পাবেন');
    }
}
