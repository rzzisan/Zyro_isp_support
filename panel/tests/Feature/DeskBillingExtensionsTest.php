<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingDisableShadow;
use App\Filament\App\Pages\BillingExtensions;
use App\Filament\App\Pages\BillingRules;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillingCustomer;
use App\Models\BillingSetting;
use App\Models\Company;
use App\Models\DeskExtension;
use App\Models\Membership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/** In-house billing phase 4a: সময় বাড়ানো (plan 2.2 #11). */
class DeskBillingExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private User $other;

    private BillingCustomer $rahim;

    private int $rahimRef;

    private int $karimRef;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake(['engine.test/*' => Http::response(['status' => 'done'])]);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->other = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        $this->company->users()->attach($this->other, ['role' => 'agent']);
        Membership::where('company_id', $this->company->id)->where('user_id', $this->agent->id)
            ->update(['permissions' => json_encode(['customers', 'bill_extend'])]);
        Membership::where('company_id', $this->company->id)->where('user_id', $this->other->id)
            ->update(['permissions' => json_encode(['customers'])]);
        BillingSetting::forCompany($this->company)->fill(['extend_max_days' => 10, 'extend_per_month' => 1])->save();
        $c = $this->company->id;
        $this->rahim = BillingCustomer::create(['company_id' => $c, 'header_id' => 7, 'customer_id' => '0201', 'name' => 'Rahim Mia',
            'username' => 'rahim', 'status' => 'Active', 'monthly_bill' => 500, 'due' => 1000, 'disabled' => false]);
        BillingCustomer::create(['company_id' => $c, 'header_id' => 8, 'customer_id' => '0202', 'name' => 'Karim Mia',
            'username' => 'karim', 'status' => 'Active', 'monthly_bill' => 500, 'due' => 500, 'disabled' => true]);
        $this->rahimRef = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 7, 'customer_code' => '0201',
            'username' => 'rahim', 'name' => 'Rahim Mia', 'status' => 'Active', 'monthly_bill' => 500]);
        $this->karimRef = DB::table('desk_customers')->insertGetId(['company_id' => $c, 'header_id' => 8, 'customer_code' => '0202',
            'username' => 'karim', 'name' => 'Karim Mia', 'status' => 'Active', 'monthly_bill' => 500, 'line_state' => 'off']);
        DB::table('desk_customer_balances')->insert(['customer_ref' => $this->rahimRef, 'company_id' => $c, 'balance' => 1000, 'has_history' => true]);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function day(int $n): string
    {
        return now('Asia/Dhaka')->startOfDay()->addDays($n)->toDateString();
    }

    public function test_a_member_with_the_permission_extends_within_the_limits(): void
    {
        $this->as($this->other);
        Livewire::test(ViewCustomer::class, ['record' => $this->rahim->id])->assertActionHidden('extend');

        $this->as($this->agent);
        Livewire::test(ViewCustomer::class, ['record' => $this->rahim->id])->assertActionVisible('extend')
            ->callAction('extend', ['until' => $this->day(5), 'reason' => '২০ তারিখে বেতন পেলে দেবেন'])->assertHasNoActionErrors();
        $e = DeskExtension::sole();
        $this->assertSame(['active', 5, '0201', 1000.0, false], [$e->status, $e->days, $e->customer_code, (float) $e->due_at_start, $e->over_limit]);
        $this->assertSame($this->day(5), substr((string) DB::table('desk_customers')->where('id', $this->rahimRef)->value('extended_until'), 0, 10));
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'billing.extend')->where('customer_code', '0201')->count());

        // a second time this month is past the limit for a member
        Livewire::test(ViewCustomer::class, ['record' => $this->rahim->id])
            ->callAction('extend', ['until' => $this->day(3), 'reason' => 'আবার']);
        $this->assertSame(1, DeskExtension::count());
        Http::assertNothingSent();      // the line was on: nothing to switch
    }

    public function test_the_owner_may_go_past_the_limits_and_replace(): void
    {
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $this->rahim->id])
            ->callAction('extend', ['until' => $this->day(5), 'reason' => 'a'])->assertHasNoActionErrors()
            ->callAction('extend', ['until' => $this->day(20), 'reason' => 'b'])->assertHasNoActionErrors();
        $this->assertSame(['replaced', 'active'], DeskExtension::orderBy('id')->pluck('status')->all());
        $this->assertTrue(DeskExtension::where('status', 'active')->sole()->over_limit);
    }

    public function test_an_off_line_is_left_alone_without_the_switch_permission(): void
    {
        $karim = BillingCustomer::where('header_id', 8)->first();
        $this->as($this->agent);
        Livewire::test(ViewCustomer::class, ['record' => $karim->id])
            ->callAction('extend', ['until' => $this->day(3), 'reason' => 'x', 'enable_line' => true])->assertHasNoActionErrors();
        $this->assertSame('left_off', DeskExtension::sole()->line_action);
        Http::assertNothingSent();
    }

    public function test_a_test_account_line_is_turned_on_by_the_desk(): void
    {
        BillingSetting::forCompany($this->company)->fill(['line_control' => 'test', 'line_test_codes' => ['0202']])->save();
        $karim = BillingCustomer::where('header_id', 8)->first();
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $karim->id])
            ->callAction('extend', ['until' => $this->day(3), 'reason' => 'x', 'enable_line' => true])->assertHasNoActionErrors();
        $this->assertSame('enabled', DeskExtension::sole()->line_action);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/desk-line') && $r['action'] === 'enable' && $r['reason'] === 'extend' && $r['header_id'] === 8);
    }

    public function test_the_list_page_and_cancel(): void
    {
        $base = '/app/'.$this->company->slug;
        $this->actingAs($this->other)->get($base.'/billing-extensions')->assertForbidden();
        DB::table('desk_extensions')->insert(['company_id' => $this->company->id, 'customer_ref' => $this->rahimRef, 'customer_code' => '0201',
            'until' => $this->day(4), 'given_on' => $this->day(0), 'days' => 4, 'reason' => 'আংশিক দিয়েছেন', 'created_at' => now()]);
        DB::table('desk_customers')->where('id', $this->rahimRef)->update(['extended_until' => $this->day(4)]);
        $this->actingAs($this->agent)->get($base.'/billing-extensions')->assertOk()->assertSee('Rahim Mia')->assertSee('আংশিক দিয়েছেন');
        $this->as($this->agent);
        Livewire::test(BillingExtensions::class)
            ->callAction(TestAction::make('cancel')->table(DeskExtension::sole()), ['note' => 'ভুল করে'])->assertHasNoActionErrors();
        $this->assertSame('canceled', DeskExtension::sole()->status);
        $this->assertNull(DB::table('desk_customers')->where('id', $this->rahimRef)->value('extended_until'));
    }

    public function test_the_owner_gives_many_overdue_lines_a_date_from_the_turn_off_list(): void
    {
        $c = $this->company->id;
        foreach ([$this->rahimRef, $this->karimRef] as $ref) {
            DB::table('desk_disable_shadow')->insert(['company_id' => $c, 'day' => $this->day(0), 'customer_ref' => $ref,
                'decision' => 'disable', 'reason' => 'overdue', 'due' => 500, 'result' => 'desk_only']);
        }
        $this->as($this->owner);
        Livewire::test(BillingDisableShadow::class)
            ->filterTable('result', null)
            ->selectTableRecords(DB::table('desk_disable_shadow')->pluck('id')->all())
            ->callAction(TestAction::make('extend')->table()->bulk(), ['until' => $this->day(25), 'reason' => 'মাসের শেষে দেবেন'])
            ->assertHasNoActionErrors();
        $this->assertSame(2, DeskExtension::where('kind', 'bulk')->where('status', 'active')->count());
        $this->assertSame('left_off', DeskExtension::where('customer_ref', $this->karimRef)->value('line_action'));
        Http::assertNothingSent();
    }

    public function test_the_limits_are_saved_on_the_rules_page(): void
    {
        $this->as($this->owner);
        Livewire::test(BillingRules::class)->fillForm(['extend_max_days' => 7, 'extend_per_month' => 2])->call('save');
        $s = BillingSetting::where('company_id', $this->company->id)->first();
        $this->assertSame([7, 2], [(int) $s->extend_max_days, (int) $s->extend_per_month]);
    }

    public function test_the_app_extends_through_the_api(): void
    {
        $url = '/api/v1/c/'.$this->company->slug.'/customers/7';
        Sanctum::actingAs($this->other);
        $this->getJson($url)->assertOk()->assertJsonPath('can.extend', false)->assertJsonPath('extension', null);
        $this->postJson($url.'/extend', ['until' => $this->day(3), 'reason' => 'x'])->assertForbidden();

        Sanctum::actingAs($this->agent);
        $this->postJson($url.'/extend', ['until' => $this->day(30), 'reason' => 'x'])->assertStatus(422);   // past 10 days
        $this->postJson($url.'/extend', ['until' => $this->day(3), 'reason' => 'বেতন পেলে'])->assertOk()
            ->assertJsonPath('extension.until', $this->day(3));
        $this->getJson($url)->assertJsonPath('can.extend', true)->assertJsonPath('extension.reason', 'বেতন পেলে');
        $this->assertSame('app', DeskExtension::sole()->source);
    }
}
