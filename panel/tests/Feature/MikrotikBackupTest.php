<?php

namespace Tests\Feature;

use App\Filament\App\Resources\MikrotikBackups\Pages\ManageMikrotikBackups;
use App\Models\Company;
use App\Models\MikrotikBackup;
use App\Models\MikrotikRouter;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** MikroTik backups page, downloads (owner/admin only: exports hold PPPoE passwords) and the "back up now" button. */
class MikrotikBackupTest extends TestCase
{
    use RefreshDatabase;

    private function setUpCompany(): array
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $company->users()->attach($agent, ['role' => 'agent']);
        $router = MikrotikRouter::create(['company_id' => $company->id, 'host' => '10.85.1.6', 'api_port' => 8728,
            'username' => 'desk', 'password' => 's3cret', 'enabled' => true]);

        return [$company, $owner, $agent, $router];
    }

    public function test_only_managers_see_and_download_backups(): void
    {
        Storage::fake('local');
        [$company, $owner, $agent, $router] = $this->setUpCompany();
        $path = "mikrotik-backups/{$company->id}/{$router->id}/2026-10-19/CLN-3-20261019-0300.rsc";
        Storage::disk('local')->put($path, '/ppp secret add name=x password=y');
        $b = MikrotikBackup::create(['company_id' => $company->id, 'router_id' => $router->id, 'router_name' => 'CLN-3',
            'status' => 'ok', 'rsc_path' => $path, 'rsc_size' => 33, 'started_at' => now()]);

        $this->actingAs($agent)->get("/app/{$company->slug}/mikrotik-backups")->assertForbidden();
        $this->actingAs($agent)->get("/download/{$company->slug}/mikrotik-backup/{$b->id}/rsc")->assertForbidden();
        $this->actingAs($owner)->get("/app/{$company->slug}/mikrotik-backups")->assertOk()->assertSee('CLN-3');
        $res = $this->actingAs($owner)->get("/download/{$company->slug}/mikrotik-backup/{$b->id}/rsc")->assertOk();
        $this->assertStringContainsString('CLN-3-20261019-0300.rsc', $res->headers->get('content-disposition'));
        $this->actingAs($owner)->get("/download/{$company->slug}/mikrotik-backup/{$b->id}/backup")->assertNotFound();

        $other = Company::create(['name' => 'Other ISP']);
        $other->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->get("/download/{$other->slug}/mikrotik-backup/{$b->id}/rsc")->assertClientError();
    }

    public function test_back_up_now_asks_the_engine(): void
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        [$company, $owner, , $router] = $this->setUpCompany();
        Http::fake(['engine.test/*' => Http::response(['started' => true, 'id' => 1])]);
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(ManageMikrotikBackups::class)
            ->callAction(TestAction::make('backupNow')->table(), ['router_id' => $router->id])
            ->assertNotified('ব্যাকআপ শুরু হয়েছে: 10.85.1.6');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), "/internal/{$company->id}/mikrotik/{$router->id}/backup"));
    }

    public function test_retention_is_saved_on_network_settings(): void
    {
        [$company, $owner] = $this->setUpCompany();
        $this->assertSame(30, $company->fresh()->mikrotik_backup_days);
        $this->actingAs($owner)->get('/app/'.$company->slug.'/network-settings')->assertOk()->assertSee('MikroTik ব্যাকআপ কতদিন রাখা হবে (দিন)');
        Filament::setCurrentPanel('app');
        Filament::setTenant($company);
        Livewire::test(\App\Filament\App\Pages\NetworkSettings::class)
            ->fillForm(['router_port' => 7863, 'mikrotik_backup_days' => 14])->call('save')->assertHasNoFormErrors();
        $this->assertSame([7863, 14], [$company->fresh()->router_port, $company->fresh()->mikrotik_backup_days]);
    }
}
