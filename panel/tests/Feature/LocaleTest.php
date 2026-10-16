<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\Locale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Panel language: Bangla by default, per-user switch in the user menu, company default, every text in lang/en.json. */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'owner', ?string $companyLocale = null): array
    {
        $company = Company::create(['name' => 'Century Link Network']);
        if ($companyLocale) {
            $company->forceFill(['default_locale' => $companyLocale])->save();
        }
        $user = User::factory()->create();
        $company->users()->attach($user, ['role' => $role]);

        return [$company, $user];
    }

    public function test_every_bangla_text_has_an_english_translation(): void
    {
        $en = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], array_values(array_diff(Locale::keys(), array_keys($en))), 'missing in lang/en.json');
        foreach ($en as $bn => $text) {
            // same :placeholders on both sides
            preg_match_all('/:([a-z_]+)/', $bn, $a);
            preg_match_all('/:([a-z_]+)/', $text, $b);
            $this->assertEqualsCanonicalizing($a[1], $b[1], "placeholders of \"$bn\"");
        }
    }

    public function test_panel_is_bangla_by_default(): void
    {
        [$company, $user] = $this->member();
        $this->actingAs($user)->get('/app/'.$company->slug.'/inbox')->assertOk()
            ->assertSee('ইনবক্স')->assertSee('English')->assertDontSee('Pick a conversation on the left');
    }

    public function test_user_switches_to_english_and_back(): void
    {
        [$company, $user] = $this->member();
        $this->actingAs($user)->from('/app/'.$company->slug.'/inbox')->get('/locale/en')
            ->assertRedirect('/app/'.$company->slug.'/inbox');
        $this->assertSame('en', $user->fresh()->locale);

        $this->get('/app/'.$company->slug.'/inbox')->assertOk()
            ->assertSee('Pick a conversation on the left')->assertSee('Support')->assertSee('বাংলা');
        $this->get('/app/'.$company->slug.'/tickets')->assertOk()->assertSee('Billing tickets')->assertSee('In progress');

        $this->get('/locale/bn');
        $this->get('/app/'.$company->slug.'/inbox')->assertOk()->assertSee('বাম পাশ থেকে একটা কনভারসেশন বাছুন');
    }

    public function test_unknown_language_is_refused(): void
    {
        [, $user] = $this->member();
        $this->actingAs($user)->get('/locale/fr')->assertNotFound();
        $this->assertNull($user->fresh()->locale);
    }

    public function test_company_default_applies_until_the_user_chooses(): void
    {
        [$company, $user] = $this->member('agent', 'en');
        $this->actingAs($user)->get('/app/'.$company->slug.'/inbox')->assertOk()->assertSee('Pick a conversation on the left');

        $user->forceFill(['locale' => 'bn'])->save();
        $this->get('/app/'.$company->slug.'/inbox')->assertOk()->assertSee('বাম পাশ থেকে একটা কনভারসেশন বাছুন');
    }

    public function test_ticket_sms_status_is_not_translated(): void
    {
        // {status} in the customer's SMS is customer-facing; the staff member's panel language must not change it
        $src = file_get_contents(app_path('Filament/App/TicketActions.php'));
        $this->assertStringContainsString("'status' => 'খোলা'", $src);
        $this->assertStringContainsString("'status' => 'সমাধান'", $src);
    }
}
