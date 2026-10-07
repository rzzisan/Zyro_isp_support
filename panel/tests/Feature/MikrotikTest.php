<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Mikrotiks\Pages\ManageMikrotiks;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\MikrotikRouter;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class MikrotikTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_router_password_encrypted_and_tested(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $company->users()->attach($agent, ['role' => 'agent']);
        foreach (['CLNBD', 'CLNBD', 'CLN_4'] as $i => $srv) {
            BillingCustomer::create(['company_id' => $company->id, 'header_id' => $i + 1, 'customer_id' => "000{$i}", 'server' => $srv]);
        }
        $this->actingAs($agent)->get("/app/{$company->slug}/mikrotik")->assertForbidden();
        $this->actingAs($owner)->get("/app/{$company->slug}/mikrotik")->assertOk()->assertSee('CLNBD (2)');

        Http::fake(['engine.test/*' => Http::response(['identity' => 'CLNBD', 'version' => '7.15', 'ppp_active' => 2100, 'billing_server' => 'CLNBD'])]);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(ManageMikrotiks::class)
            ->callAction(TestAction::make('create')->table(), ['host' => '10.85.1.6', 'api_port' => 8728, 'username' => 'api-ro', 'password' => 's3cret', 'enabled' => true])
            ->assertHasNoFormErrors()->assertNotified('সংযোগ ঠিক আছে: CLNBD');
        $r = MikrotikRouter::sole();
        $this->assertSame([$company->id, 's3cret'], [$r->company_id, $r->password]);
        $this->assertNotSame('s3cret', DB::table('mikrotik_routers')->value('password'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), "/internal/{$company->id}/mikrotik/{$r->id}/test"));
    }
}
