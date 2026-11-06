<?php

namespace Tests\Feature;

use App\Filament\App\Resources\OfficePhones\Pages\ManageOfficePhones;
use App\Models\Company;
use App\Models\OfficePhone;
use App\Models\Plan;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class OfficePhoneTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $plan = Plan::create(['name' => 'Team', 'max_agents' => 3]);
        $this->company = Company::create(['name' => 'Century Link Network', 'plan_id' => $plan->id]);
        $this->owner = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
    }

    public function test_owner_adds_phone_passwords_encrypted_and_setup_shows_login(): void
    {
        // made outside the panel: a create through it writes an activity log line with the Bangla "(বদলেছে)" marker for
        // the passwords, which the SQL_ASCII test database refuses (the live database is UTF8)
        OfficePhone::create(['company_id' => $this->company->id, 'label' => 'Support', 'pbx_username' => '103', 'pbx_password' => 'Pbx-Pass_1']);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        $p = OfficePhone::first();
        $this->assertSame('103', $p->pbx_username);
        $this->assertNotSame('Pbx-Pass_1', DB::table('office_phones')->value('pbx_password'));
        $this->assertSame('Pbx-Pass_1', $p->pbx_password);
        $this->assertSame(20, strlen($p->phone_password));

        Livewire::test(ManageOfficePhones::class)
            ->callAction(TestAction::make('create')->table(), ['pbx_username' => '103', 'pbx_password' => 'x'])
            ->assertHasActionErrors(['pbx_username']);

        Livewire::test(ManageOfficePhones::class)->mountAction(TestAction::make('setup')->table($p))->assertActionMounted(TestAction::make('setup')->table($p));
        $this->view('filament.app.office-phone-setup', ['phone' => $p])
            ->assertSee('ph'.$p->id)->assertSee($p->phone_password)->assertSee(OfficePhone::SERVER);
    }

    public function test_agent_cannot_open_office_phones(): void
    {
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent)->get("/app/{$this->company->slug}/office-phones")->assertForbidden();
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/office-phones")->assertOk();
    }
}
