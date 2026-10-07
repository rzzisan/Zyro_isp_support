<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Technicians\Pages\ManageTechnicians;
use App\Filament\App\Resources\Conversations\Pages\ListConversations;
use App\Models\Company;
use App\Models\Technician;
use App\Models\User;
use App\Models\WaContact;
use App\Models\WaMessage;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TechnicianTest extends TestCase
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

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_owner_adds_technician_number_normalised(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageTechnicians::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Nazmul', 'wa_number' => '01711-000111', 'active' => true])
            ->assertHasFormErrors(['wa_number']);
        Livewire::test(ManageTechnicians::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Nazmul', 'wa_number' => '01711000111', 'active' => true])
            ->assertHasNoFormErrors();
        $t = Technician::sole();
        $this->assertSame(['8801711000111', $this->company->id], [$t->wa_number, $t->company_id]);
        Livewire::test(ManageTechnicians::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'Copy', 'wa_number' => '+8801711000111'])
            ->assertHasFormErrors(['wa_number']);
        // the same number in another company is fine
        Technician::create(['company_id' => Company::create(['name' => 'Other'])->id, 'name' => 'X', 'wa_number' => '8801711000222']);
        Livewire::test(ManageTechnicians::class)->assertCanNotSeeTableRecords(Technician::where('name', 'X')->get());
    }

    public function test_agent_cannot_manage_technicians_and_inbox_marks_them(): void
    {
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $this->actingAs($agent)->get("/app/{$this->company->slug}/technicians")->assertForbidden();

        Technician::create(['company_id' => $this->company->id, 'name' => 'Nazmul', 'wa_number' => '8801711000111']);
        $c = WaContact::create(['company_id' => $this->company->id, 'wa_number' => '8801711000111', 'name' => 'Naz', 'last_message_at' => now()]);
        WaMessage::create(['company_id' => $this->company->id, 'contact_id' => $c->id, 'direction' => 'in', 'sender' => 'customer', 'body' => '5629 line?']);
        $this->as($agent);
        Livewire::test(ListConversations::class)->assertSee('টেকনিশিয়ান');
    }
}
