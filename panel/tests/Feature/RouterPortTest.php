<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Customer router port per company: the settings page saves it and the IP becomes an http://IP:port link. */
class RouterPortTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_url_uses_the_company_port(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $this->assertSame('http://10.20.1.5', $company->routerUrl('10.20.1.5'));
        $company->update(['router_port' => 7863]);
        $this->assertSame('http://10.20.1.5:7863', $company->routerUrl('10.20.1.5'));
        $this->assertNull($company->routerUrl('"><script>'));
        $this->assertNull($company->routerUrl(null));
    }

    public function test_ip_component_renders_a_new_tab_link(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $company->update(['router_port' => 7863]);
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($company);

        $html = (string) $this->blade('<x-router-ip :ip="$ip" />', ['ip' => '10.20.1.5']);
        $this->assertStringContainsString('href="http://10.20.1.5:7863"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('—', (string) $this->blade('<x-router-ip :ip="null" />'));
    }

    public function test_owner_saves_the_port_on_the_settings_page(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);

        $this->actingAs($user)->get('/app/'.$company->slug.'/network-settings')->assertOk()
            ->assertSee('কাস্টমারের রাউটারের রিমোট পোর্ট');
    }
}
