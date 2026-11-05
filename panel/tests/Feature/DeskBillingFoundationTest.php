<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingRules;
use App\Filament\App\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Support\Activity;
use App\Support\Menu;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** In-house billing phase 0: বিলিং নিয়ম page, কাজের লগ (activity log), new billing permissions. */
class DeskBillingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create(['name' => 'Zisan']);
        $this->admin = User::factory()->create(['name' => 'Admin']);
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->admin, ['role' => 'admin']);
    }

    private function panel(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_only_the_owner_opens_the_billing_rules_and_the_activity_log(): void
    {
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->admin)->get($base.'/billing-rules')->assertForbidden();
        $this->actingAs($this->admin)->get($base.'/activity-logs')->assertForbidden();
        $this->actingAs($this->owner)->get($base.'/billing-rules')->assertOk()->assertSee(__('এখনো কপি হয়নি। প্রতিদিন সকাল ৭:১৫-এ নিজে হয়, বা উপরের "এখনই কপি" চাপুন।'));
        $this->actingAs($this->owner)->get($base.'/activity-logs')->assertOk();
    }

    public function test_rules_start_at_todays_billing_and_every_change_is_kept(): void
    {
        $this->panel($this->owner);
        Livewire::test(BillingRules::class)
            ->assertSet('data.enable_min_percent', 90)->assertSet('data.disable_when', 'next_day')->assertSet('data.disable_hour', 11)
            ->fillForm(['enable_min_percent' => 80, 'disable_hour' => 10])
            ->call('save')->assertHasNoFormErrors();
        $s = BillingSetting::where('company_id', $this->company->id)->first();
        $this->assertSame('ispdigital', $s->billing_mode);
        $this->assertSame(80, (int) $s->enable_min_percent);
        $this->assertSame(40, (int) $s->partial_redisable_percent);

        Livewire::test(BillingRules::class)->fillForm(['enable_min_percent' => 85])->call('save')->assertHasNoFormErrors();
        $log = DB::table('billing_settings_log')->where('company_id', $this->company->id)->where('field', 'enable_min_percent')->orderBy('id')->get();
        $this->assertSame(['80', '85'], $log->pluck('new_value')->map(fn ($v) => (string) $v)->all());
        $this->assertSame('80', (string) $log->last()->old_value);
        $this->assertTrue(ActivityLog::where('company_id', $this->company->id)->where('action', 'billing_setting.updated')
            ->where('user_id', $this->owner->id)->exists());
    }

    public function test_percent_out_of_range_is_refused(): void
    {
        $this->panel($this->owner);
        Livewire::test(BillingRules::class)->fillForm(['enable_min_percent' => 150])->call('save')->assertHasFormErrors(['enable_min_percent']);
    }

    public function test_billing_mode_is_not_changed_from_the_form(): void
    {
        $this->panel($this->owner);
        Livewire::test(BillingRules::class)->set('data.billing_mode', 'desk')->call('save')->assertHasNoFormErrors();
        $this->assertSame('ispdigital', BillingSetting::where('company_id', $this->company->id)->value('billing_mode'));
    }

    public function test_copy_now_asks_the_engine(): void
    {
        Http::fake(['engine.test/*' => Http::response(['started' => true])]);
        $this->panel($this->owner);
        Livewire::test(BillingRules::class)->callAction('import');
        Http::assertSent(fn ($r) => str_contains($r->url(), "/internal/{$this->company->id}/desk-billing/import") && str_contains($r->url(), 'user_id='.$this->owner->id));
    }

    public function test_writes_are_logged_with_before_and_after_but_never_secrets(): void
    {
        $this->panel($this->owner);
        $m = Membership::where('company_id', $this->company->id)->where('user_id', $this->admin->id)->first();
        $m->update(['billing_username' => 'arju11', 'billing_password' => 'S3cret!']);
        $row = ActivityLog::where('action', 'membership.updated')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame([null, 'arju11'], $row->changes['billing_username']);
        $this->assertSame([Activity::CHANGED, Activity::CHANGED], $row->changes['billing_password']);
        $this->assertStringNotContainsString('S3cret', json_encode($row->getAttributes()));
        $this->assertSame('console', $row->source);   // tests run in the console
        $this->assertSame($this->company->id, (int) $row->company_id);
    }

    public function test_nothing_is_logged_without_a_signed_in_person(): void
    {
        auth()->logout();
        BillingSetting::forCompany($this->company)->fill(['company_id' => $this->company->id])->save();
        $this->assertSame(0, ActivityLog::where('action', 'billing_setting.created')->count());
    }

    public function test_the_log_page_shows_before_and_after_and_downloads_csv(): void
    {
        $this->panel($this->owner);
        Activity::log('customer.updated', ['monthly_bill' => [520, 525]], note: 'দাম ঠিক', customerCode: '0042', companyId: $this->company->id);
        Livewire::test(ListActivityLogs::class)->assertSee('monthly_bill: 520 → 525')->assertSee('দাম ঠিক')
            ->filterTable('customer', ['code' => '0042'])->assertSee('0042')
            ->callAction('csv')->assertFileDownloaded();
    }

    public function test_log_rows_cannot_be_changed_or_removed(): void
    {
        $this->panel($this->owner);
        Activity::log('test.action', companyId: $this->company->id);
        $row = ActivityLog::latest('id')->first();
        $this->expectException(\LogicException::class);
        $row->update(['action' => 'changed']);
    }

    public function test_new_billing_permissions_are_owner_only_until_ticked(): void
    {
        foreach (['bill_edit', 'payment_delete', 'payment_transfer'] as $key) {
            $this->assertTrue(Menu::allows($this->owner, $this->company, $key));
            $this->assertFalse(Menu::allows($this->admin, $this->company, $key));
            $this->assertNotContains($key, Menu::defaults('admin'));
        }
        Membership::where('company_id', $this->company->id)->where('user_id', $this->admin->id)->update(['permissions' => json_encode(['bill_edit'])]);
        $this->assertTrue(Menu::allows($this->admin, $this->company, 'bill_edit'));
        $this->assertFalse(Menu::allows($this->admin, $this->company, 'payment_delete'));
    }
}
