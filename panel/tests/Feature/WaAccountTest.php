<?php

namespace Tests\Feature;

use App\Filament\App\Resources\WaAccounts\Pages\ManageWaAccounts;
use App\Models\Company;
use App\Models\User;
use App\Models\WaAccount;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WaAccountTest extends TestCase
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
    }

    private function account(Company $c, string $pnid): WaAccount
    {
        return WaAccount::create(['company_id' => $c->id, 'waba_id' => 'W'.$pnid, 'phone_number_id' => $pnid, 'access_token' => 'tok-'.$pnid]);
    }

    public function test_owner_sees_whatsapp_page_agent_cannot(): void
    {
        $slug = $this->company->slug;
        $this->actingAs($this->owner)->get("/app/{$slug}/whatsapp")->assertOk();
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent)->get("/app/{$slug}/whatsapp")->assertForbidden();
    }

    public function test_lists_only_own_numbers_and_adds_with_company(): void
    {
        $mine = $this->account($this->company, '111');
        $other = $this->account(Company::create(['name' => 'Other ISP']), '222');
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ManageWaAccounts::class)
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other])
            ->callAction(TestAction::make('create')->table(), ['phone_number_id' => '333', 'waba_id' => 'W3', 'access_token' => 'secret', 'bot_enabled' => true])
            ->assertHasNoFormErrors();
        $new = WaAccount::where('phone_number_id', '333')->sole();
        $this->assertSame($this->company->id, $new->company_id);
        $this->assertSame('secret', $new->access_token);

        // a phone number id already used by another company is refused
        Livewire::test(ManageWaAccounts::class)
            ->callAction(TestAction::make('create')->table(), ['phone_number_id' => '222', 'waba_id' => 'W', 'access_token' => 'x'])
            ->assertHasFormErrors(['phone_number_id' => 'unique']);
    }

    public function test_check_fills_display_number_with_company_token(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['display_phone_number' => '+880 1777-858289', 'verified_name' => 'Century Link Network', 'quality_rating' => 'GREEN'])]);
        $acc = $this->account($this->company, '111');
        $this->actingAs($this->owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(ManageWaAccounts::class)->callAction(TestAction::make('test')->table($acc));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer tok-111') && str_contains($r->url(), '/111?'));
        $this->assertSame('+880 1777-858289', $acc->fresh()->display_phone_number);
    }
}
