<?php

namespace Tests\Feature;

use App\Filament\Pages\EditProfile;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Every Desk user changes their own password: panel profile page and the app's /api/v1/password. */
class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->agent = User::factory()->create(['email' => 'agent@example.com', 'password' => 'old-password']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
    }

    private function asAgent(): void
    {
        $this->actingAs($this->agent);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_agent_opens_profile_page_from_company_panel(): void
    {
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/profile")->assertOk()
            ->assertSee('নতুন পাসওয়ার্ড', false)->assertSee('আমার প্রোফাইল ও পাসওয়ার্ড', false);
        // the user menu links to it from every company page
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}")->assertOk()->assertSee("/app/{$this->company->slug}/profile", false);
    }

    public function test_super_admin_has_profile_page_too(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->get('/super/profile')->assertOk()->assertSee('নতুন পাসওয়ার্ড', false);
    }

    public function test_panel_changes_password_with_current_one_and_signs_out_phones(): void
    {
        $this->agent->createToken('android:phone');
        $this->asAgent();
        Livewire::test(EditProfile::class)
            ->fillForm(['password' => 'new-password-1', 'passwordConfirmation' => 'new-password-1', 'currentPassword' => 'old-password'])
            ->call('save')->assertHasNoFormErrors();
        $this->agent->refresh();
        $this->assertTrue(Hash::check('new-password-1', $this->agent->password));
        $this->assertSame(0, $this->agent->tokens()->count());
        $this->assertSame('agent@example.com', $this->agent->email);
    }

    public function test_panel_rejects_wrong_current_password_and_keeps_email(): void
    {
        $this->asAgent();
        Livewire::test(EditProfile::class)
            ->fillForm(['email' => 'hacker@example.com', 'password' => 'new-password-1', 'passwordConfirmation' => 'new-password-1', 'currentPassword' => 'wrong'])
            ->call('save')->assertHasFormErrors(['currentPassword']);
        $this->agent->refresh();
        $this->assertTrue(Hash::check('old-password', $this->agent->password));
        $this->assertSame('agent@example.com', $this->agent->email);
    }

    public function test_panel_name_change_needs_no_password(): void
    {
        $this->asAgent();
        Livewire::test(EditProfile::class)->fillForm(['name' => 'Rezaul'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Rezaul', $this->agent->refresh()->name);
        $this->assertTrue(Hash::check('old-password', $this->agent->password));
    }

    public function test_app_changes_password_and_keeps_only_this_phone(): void
    {
        $this->agent->createToken('android:other');
        $token = $this->agent->createToken('android:this')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/password', ['current_password' => 'old-password',
            'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertOk()->assertJson(['ok' => true]);
        $this->assertTrue(Hash::check('new-password-1', $this->agent->refresh()->password));
        $this->assertSame(['android:this'], $this->agent->tokens()->pluck('name')->all());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    }

    public function test_app_rejects_wrong_current_or_short_or_mismatched_password(): void
    {
        $token = $this->agent->createToken('android:this')->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/password', ['current_password' => 'wrong',
            'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->withToken($token)->postJson('/api/v1/password', ['current_password' => 'old-password',
            'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->withToken($token)->postJson('/api/v1/password', ['current_password' => 'old-password',
            'password' => 'new-password-1', 'password_confirmation' => 'different-1'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertTrue(Hash::check('old-password', $this->agent->refresh()->password));
    }
}
