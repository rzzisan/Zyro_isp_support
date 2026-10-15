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
            '# সাপোর্ট: ইনবক্স টিকিট',
            '# কাস্টমার ও বিল: কাস্টমার বিল কালেকশন লাইন চালু/বন্ধের রেকর্ড',
            '# SMS: SMS পাঠান SMS লগ SMS টেমপ্লেট',
            '# নেটওয়ার্ক: MikroTik OLT',
            '# বিলিং কনফিগারেশন: এলাকা প্যাকেজ ও সংযোগ সাপোর্ট তালিকা',
            '# WhatsApp ও AI: WhatsApp বট সেটিংস বটের FAQ AI key AI খরচ',
            '# সেটিংস: আমার বিলিং লগইন বিলিং সংযোগ মানি রিসিট টিম টেকনিশিয়ান SMS গেটওয়ে',
        ], $this->sidebar('owner'));
    }

    public function test_agent_does_not_see_admin_menus(): void
    {
        $sidebar = implode("\n", $this->sidebar('agent'));
        foreach (['বিলিং কনফিগারেশন', 'MikroTik', 'AI খরচ', 'টিম'] as $adminOnly) {
            $this->assertStringNotContainsString($adminOnly, $sidebar);
        }
        $this->assertStringContainsString('# সাপোর্ট: ইনবক্স টিকিট', $sidebar);
    }
}
