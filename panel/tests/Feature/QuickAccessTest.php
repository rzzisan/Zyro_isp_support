<?php

namespace Tests\Feature;

use App\Filament\App\Pages\QuickAccessSettings;
use App\Filament\App\Resources\LineEnables\LineEnableResource;
use App\Filament\App\Resources\Tickets\TicketResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Owner picks the sidebar's quick access menus; members still need each menu's permission. */
class QuickAccessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private function member(string $role, ?array $permissions = null): User
    {
        $this->company ??= Company::create(['name' => 'Century Link Network']);
        $user = User::factory()->create();
        $this->company->users()->attach($user, ['role' => $role, 'permissions' => $permissions === null ? null : json_encode($permissions)]);

        return $user;
    }

    /** "group: item item" lines of the sidebar. */
    private function sidebar(User $user): string
    {
        // Filament builds the navigation once per app instance; a test runs several requests in one app
        $this->app->forgetInstance(\Filament\Navigation\NavigationManager::class);
        $html = $this->actingAs($user)->get('/app/'.$this->company->slug)->assertOk()->getContent();
        preg_match_all('/class="fi-sidebar-(group|item)-label[^"]*"[^>]*>\s*([^<]+?)\s*</', $html, $m, PREG_SET_ORDER);
        $out = '';
        foreach ($m as [, $kind, $label]) {
            $out .= $kind === 'group' ? "\n$label:" : " $label";
        }

        return $out;
    }

    private function pick(User $owner, array $classes): void
    {
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
        Livewire::test(QuickAccessSettings::class)
            ->set('data.quick_access', $classes)
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_owner_picks_quick_access_menus(): void
    {
        $owner = $this->member('owner');
        $this->assertStringContainsString("\nকুইক অ্যাক্সেস: ইনবক্স টিকিট বিল কালেকশন কাস্টমার\n", $this->sidebar($owner));

        $this->pick($owner, [LineEnableResource::class, TicketResource::class]);
        // ticket stays first (already there), the new one comes after it
        $this->assertSame([TicketResource::class, LineEnableResource::class], $this->company->fresh()->quick_access);

        $sidebar = $this->sidebar($owner);
        $this->assertStringContainsString("\nকুইক অ্যাক্সেস: টিকিট লাইন চালু/বন্ধের রেকর্ড\n", $sidebar);
        $this->assertMatchesRegularExpression('/\nসাপোর্ট: ইনবক্স/u', $sidebar);
        $this->assertMatchesRegularExpression('/\nকাস্টমার ও বিল: কাস্টমার বিল কালেকশন\n/u', $sidebar);
    }

    public function test_nothing_picked_hides_the_group(): void
    {
        $owner = $this->member('owner');
        $this->pick($owner, []);
        $this->assertSame([], $this->company->fresh()->quick_access);
        $this->assertStringNotContainsString('কুইক অ্যাক্সেস:', $this->sidebar($owner));
    }

    public function test_member_sees_only_menus_they_may_open(): void
    {
        $this->member('owner');
        $agent = $this->member('agent', ['inbox']);
        $sidebar = $this->sidebar($agent);
        $this->assertStringContainsString("\nকুইক অ্যাক্সেস: ইনবক্স\n", $sidebar);
        $this->assertStringNotContainsString('কাস্টমার', $sidebar);
    }

    public function test_only_the_owner_opens_the_page(): void
    {
        $this->member('owner');
        foreach (['admin', 'agent'] as $role) {
            $this->actingAs($this->member($role))->get('/app/'.$this->company->slug.'/quick-access')->assertForbidden();
        }
        $this->actingAs($this->company->users()->wherePivot('role', 'owner')->first())
            ->get('/app/'.$this->company->slug.'/quick-access')->assertOk()->assertSee('লাইন চালু/বন্ধের রেকর্ড');
    }
}
