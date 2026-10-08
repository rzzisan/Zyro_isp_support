<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BillingSettings;
use App\Filament\App\Pages\BotSettings;
use App\Filament\App\Resources\AiKeys\Pages\ManageAiKeys;
use App\Filament\App\Resources\Memberships\Pages\ManageMemberships;
use App\Models\AiKey;
use App\Models\BillingConnection;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySettingsTest extends TestCase
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

    private function as(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function member(string $role): User
    {
        $u = User::factory()->create();
        $this->company->users()->attach($u, ['role' => $role]);

        return $u;
    }

    public function test_owner_opens_all_settings_pages_agent_cannot(): void
    {
        $slug = $this->company->slug;
        foreach (['billing-settings', 'bot-settings', 'ai-keys', 'memberships'] as $p) {
            $this->actingAs($this->owner)->get("/app/{$slug}/{$p}")->assertOk();
        }
        $agent = $this->member('agent');
        foreach (['billing-settings', 'bot-settings', 'ai-keys', 'memberships'] as $p) {
            $this->actingAs($agent)->get("/app/{$slug}/{$p}")->assertForbidden();
        }
    }

    public function test_billing_password_is_encrypted_and_kept_when_left_blank(): void
    {
        $this->as($this->owner);
        Livewire::test(BillingSettings::class)
            ->fillForm(['provider' => 'ispdigital', 'base_url' => 'https://demo.ispdigital.cloud/', 'username' => 'bot', 'password' => 'p@ss-12345'])
            ->call('save')->assertHasNoFormErrors();

        $raw = DB::table('billing_connections')->where('company_id', $this->company->id)->value('password');
        $this->assertNotSame('p@ss-12345', $raw);
        $this->assertSame('p@ss-12345', BillingConnection::first()->password);
        $this->assertSame('https://demo.ispdigital.cloud', BillingConnection::first()->base_url);

        Livewire::test(BillingSettings::class)
            ->fillForm(['username' => 'bot2', 'password' => ''])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('p@ss-12345', BillingConnection::first()->password);
        $this->assertSame('bot2', BillingConnection::first()->username);
    }

    public function test_billing_connection_test_logs_in_and_counts_customers(): void
    {
        BillingConnection::create(['company_id' => $this->company->id, 'base_url' => 'https://demo.ispdigital.cloud',
            'username' => 'bot', 'password' => 'secret']);
        Http::fake([
            'demo.ispdigital.cloud/Account/Login' => Http::response('<input name="__RequestVerificationToken" type="hidden" value="tok123">'),
            'demo.ispdigital.cloud/Account/LoginChecker' => Http::response('', 302, ['Location' => '/EmployeeDashboard/Index']),
            'demo.ispdigital.cloud/EmployeeDashboard/Index' => Http::response('ok'),
            'demo.ispdigital.cloud/Customer/AjaxCustomerList*' => Http::response(['iTotalRecords' => 1, 'iTotalDisplayRecords' => 4814, 'aaData' => []]),
        ]);
        $this->as($this->owner);
        Livewire::test(BillingSettings::class)->call('testConnection');

        $c = BillingConnection::first();
        $this->assertTrue($c->last_check_ok);
        $this->assertStringContainsString('4814', $c->last_check_message);
    }

    public function test_bot_settings_save(): void
    {
        $this->as($this->owner);
        Livewire::test(BotSettings::class)
            ->fillForm(['ai_provider' => 'groq', 'bot_mode' => 'live', 'live_allowlist' => '01782703244',
                'auto_ticket' => true, 'reply_signature' => '- Zyro', 'extra_prompt' => 'বিকাশ: 01777858289'])
            ->call('save')->assertHasNoFormErrors();

        $s = $this->company->botSetting()->first();
        $this->assertSame('live', $s->bot_mode);
        $this->assertSame('- Zyro', $s->reply_signature);
    }

    public function test_bot_prompts_show_default_and_save_only_real_edits(): void
    {
        Http::fake(['*/bot-prompts' => Http::response(['customer' => 'full', 'technician' => 'full',
            'customer_default' => 'ডিফল্ট কাস্টমার নিয়ম', 'technician_default' => 'ডিফল্ট {tech} নিয়ম'])]);
        $this->as($this->owner);

        Livewire::test(BotSettings::class)
            ->assertSet('data.customer_prompt', 'ডিফল্ট কাস্টমার নিয়ম')
            ->assertSet('data.technician_prompt', 'ডিফল্ট {tech} নিয়ম')
            ->fillForm(['ai_provider' => 'groq', 'bot_mode' => 'shadow', 'customer_prompt' => "ডিফল্ট কাস্টমার নিয়ম\n",
                'technician_prompt' => 'নিজের {tech} নিয়ম'])
            ->call('save')->assertHasNoFormErrors();

        $s = $this->company->botSetting()->first();
        $this->assertNull($s->customer_prompt);
        $this->assertSame('নিজের {tech} নিয়ম', $s->technician_prompt);

        Livewire::test(BotSettings::class)->assertSet('data.technician_prompt', 'নিজের {tech} নিয়ম')
            ->fillForm(['technician_prompt' => ''])->call('save')->assertHasNoFormErrors();
        $this->assertNull($s->fresh()->technician_prompt);
    }

    public function test_bot_settings_open_when_engine_is_down(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('engine down'));
        $this->as($this->owner);

        Livewire::test(BotSettings::class)->assertOk()->assertSet('data.customer_prompt', null);
    }

    public function test_ai_key_is_encrypted_and_scoped_to_company(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        AiKey::create(['company_id' => $other->id, 'provider' => 'groq', 'api_key' => 'gsk_other_9999', 'label' => 'other-key']);

        $this->as($this->owner);
        Livewire::test(ManageAiKeys::class)
            ->callAction(TestAction::make('create')->table(), ['provider' => 'groq', 'api_key' => 'gsk_mine_1234', 'label' => 'mine'])
            ->assertHasNoFormErrors()
            ->assertSee('••••1234')
            ->assertDontSee('other-key');

        $raw = DB::table('ai_keys')->where('label', 'mine')->value('api_key');
        $this->assertNotSame('gsk_mine_1234', $raw);
        $this->assertSame($this->company->id, AiKey::where('label', 'mine')->value('company_id'));
    }

    public function test_team_add_respects_seats_and_roles(): void
    {
        $admin = $this->member('admin');   // 2 of 3 seats
        $this->as($admin);

        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Agent A', 'email' => 'a@example.com', 'password' => 'agent-pass-123', 'role' => 'agent']);
        $this->assertSame('agent', User::where('email', 'a@example.com')->first()->roleIn($this->company));

        // plan full (3/3): next add is blocked
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Agent B', 'email' => 'b@example.com', 'password' => 'agent-pass-123', 'role' => 'agent']);
        $this->assertNull(User::where('email', 'b@example.com')->first());
        $this->assertSame(3, $this->company->users()->count());
    }

    public function test_admin_cannot_create_owner(): void
    {
        $admin = $this->member('admin');
        $this->as($admin);
        Livewire::test(ManageMemberships::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'agent-pass-123', 'role' => 'owner']);
        $this->assertNull(User::where('email', 'x@example.com')->first()?->roleIn($this->company));
    }

    public function test_other_company_members_not_listed(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        $stranger = User::factory()->create(['name' => 'Stranger Person']);
        $other->users()->attach($stranger, ['role' => 'owner']);

        $this->as($this->owner);
        Livewire::test(ManageMemberships::class)->assertDontSee('Stranger Person');
    }
}
