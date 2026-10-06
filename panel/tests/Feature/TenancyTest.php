<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private function member(Company $company, string $role = 'agent'): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_company_slug_is_generated_and_unique(): void
    {
        $a = Company::create(['name' => 'Century Link Network']);
        $b = Company::create(['name' => 'Century Link Network']);
        $this->assertSame('century-link-network', $a->slug);
        $this->assertSame('century-link-network-2', $b->slug);
    }

    public function test_member_sees_own_company_but_not_another(): void
    {
        $a = Company::create(['name' => 'ISP A']);
        $b = Company::create(['name' => 'ISP B']);
        $agent = $this->member($a);

        $this->actingAs($agent)->get('/app/'.$a->slug)->assertOk();
        $this->actingAs($agent)->get('/app/'.$b->slug)->assertNotFound();
    }

    public function test_company_user_cannot_open_super_panel(): void
    {
        $a = Company::create(['name' => 'ISP A']);
        $owner = $this->member($a, 'owner');

        $this->actingAs($owner)->get('/super')->assertForbidden();
    }

    public function test_super_admin_can_open_super_panel_and_companies(): void
    {
        $super = User::factory()->create();
        $super->forceFill(['is_super_admin' => true])->save();

        $this->actingAs($super)->get('/super')->assertOk();
        $this->actingAs($super)->get('/super/companies')->assertOk();
    }

    public function test_user_without_company_cannot_open_app_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/app')->assertForbidden();
    }

    public function test_super_admin_without_company_is_sent_to_super_panel(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin)->get('/app')->assertRedirect('/super');
        $this->actingAs($admin)->get('/app/some-company/inbox')->assertRedirect('/super');
    }
}
