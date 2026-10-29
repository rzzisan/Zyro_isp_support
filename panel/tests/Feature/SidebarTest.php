<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Desk sidebar: menu groups in working order (AppPanelProvider::navigationGroups), admin-only groups hidden from agents. */
class SidebarTest extends TestCase
{
    use RefreshDatabase;

    /** Sidebar as "# group: item, item" lines, read from the rendered page. */
    private function sidebar(string $role): array
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => $role]);
        $html = $this->actingAs($user)->get('/app/'.$company->slug)->assertOk()->getContent();
        preg_match_all('/class="fi-sidebar-(group|item)-label[^"]*"[^>]*>\s*([^<]+?)\s*</', $html, $m, PREG_SET_ORDER);
        $lines = [''];
        foreach ($m as [, $kind, $label]) {
            if ($kind === 'group') {
                $lines[] = "# $label:";
            } else {
                $lines[array_key_last($lines)] .= " $label";
            }
        }

        return array_map('trim', $lines);
    }

    public function test_owner_sees_menu_groups_in_order(): void
    {
        $this->assertSame([
            'ড্যাশবোর্ড',
            '# কুইক অ্যাক্সেস: ইনবক্স টিকিট বিল কালেকশন কাস্টমার',
            '# সাপোর্ট: এলাকার কর্মী ফিল্ড টিম টেকনিশিয়ান লোকেশন',
            '# কাস্টমার ও বিল: অনলাইন পেমেন্ট লাইন চালু/বন্ধের রেকর্ড',
            '# SMS ও WhatsApp: SMS পাঠান SMS লগ SMS টেমপ্লেট WhatsApp পাঠান WhatsApp লগ',
            '# নেটওয়ার্ক: MikroTik MikroTik ব্যাকআপ OLT নেটওয়ার্ক সেটিংস',
            '# বিলিং কনফিগারেশন: এলাকা প্যাকেজ ও সংযোগ সাপোর্ট তালিকা',
            '# WhatsApp ও AI: WhatsApp বট সেটিংস বটের FAQ AI key AI খরচ',
            '# সেটিংস: আমার পাসওয়ার্ড কুইক অ্যাক্সেস আমার বিলিং লগইন বিলিং সংযোগ মানি রিসিট টিম পারমিশন গ্রুপ টেকনিশিয়ান SMS গেটওয়ে ওয়াকি-টকি হটলাইন (SIP)',
        ], $this->sidebar('owner'));
    }

    public function test_quick_access_is_always_open(): void
    {
        $company = Company::create(['name' => 'Century Link Network']);
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => 'owner']);
        $html = $this->actingAs($user)->get('/app/'.$company->slug.'/line-enables')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-group-label="কুইক অ্যাক্সেস"\s*x-bind:class="[^"]*"\s*class="fi-sidebar-group"/u', $html);
    }

    public function test_agent_does_not_see_admin_menus(): void
    {
        $sidebar = implode("\n", $this->sidebar('agent'));
        foreach (['বিলিং কনফিগারেশন', 'MikroTik', 'AI খরচ', 'টিম'] as $adminOnly) {
            $this->assertStringNotContainsString($adminOnly, $sidebar);
        }
        $this->assertStringContainsString('# কুইক অ্যাক্সেস: ইনবক্স টিকিট', $sidebar);
    }
}
