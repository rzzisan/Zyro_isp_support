<?php

namespace Tests\Feature;

use App\Filament\App\Resources\VoiceTemplates\Pages\ManageVoiceTemplates;
use App\Filament\App\Resources\VoiceTemplates\VoiceTemplateResource;
use App\Models\BillingCustomer;
use App\Models\BillingCustomerPayment;
use App\Models\Company;
use App\Models\User;
use App\Models\VoiceReminderSetting;
use App\Models\VoiceTemplate;
use App\Support\VoiceTemplates;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Voice reminder templates: defaults, spoken placeholders, TTS preview through the engine, uploads, permissions. */
class VoiceTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private BillingCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake(['engine.test/internal/*/voice/tts' => fn (Request $r) => Http::response(['file' => explode('/', $r->url())[4].'/'.substr(sha1($r['text']), 0, 20)])]);
        Carbon::setTestNow(Carbon::parse('2026-10-29 06:00:00', 'UTC'));   // 12:00 in Dhaka
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        $this->customer = BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 77, 'customer_id' => '0042',
            'name' => 'Alif Hasan', 'mobile' => '01711000001', 'username' => 'alif', 'status' => 'Active', 'due' => 520,
            'monthly_bill' => 520, 'package' => '10 Mbps', 'zone' => 'Binodpur', 'bill_day' => 31, 'disabled' => false]);
        BillingCustomerPayment::create(['company_id' => $this->company->id, 'customer_header_id' => 77, 'bill_header_id' => 1,
            'received_on' => '2026-10-02', 'paid' => 500]);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_first_visit_gives_the_default_templates_once(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageVoiceTemplates::class)->assertOk()->assertSee('বিল রিমাইন্ডার')->assertSee('এজেন্টকে জানানো');
        Livewire::test(ManageVoiceTemplates::class);
        $this->assertSame(count(VoiceTemplate::DEFAULTS), VoiceTemplate::where('company_id', $this->company->id)->count());
    }

    public function test_values_are_spoken_in_bangla(): void
    {
        $v = VoiceTemplates::values($this->company->id, $this->customer);
        $this->assertSame('৫২০', $v['due']);
        $this->assertSame('৪ ২', $v['id']);
        $this->assertSame('৩১ অক্টোবর', $v['expire_date']);
        $this->assertSame('২', $v['days_left']);
        $this->assertSame('চালু', $v['line_status']);
        $this->assertSame('৫০০ টাকা, ২ অক্টোবর', $v['last_payment']);
        $text = VoiceTemplates::render('{name}, বিল {due} টাকা, শেষ তারিখ {expire_date}। {called_for}{nope}', $v);
        $this->assertSame('Alif Hasan, বিল ৫২০ টাকা, শেষ তারিখ ৩১ অক্টোবর। {nope}', $text);
        $this->assertSame(['nope'], VoiceTemplates::unknown('{name} {nope} {reconnect_message}'));
        $this->assertSame('ফেরত: Alif-এর', VoiceTemplates::render('ফেরত: {called_for}', $v, ['called_for' => 'Alif-এর']));
    }

    public function test_listen_makes_the_audio_with_example_data(): void
    {
        $this->as($this->owner);
        VoiceTemplate::seed($this->company->id);
        $t = VoiceTemplate::where('company_id', $this->company->id)->where('type', 'reminder')->first();
        Livewire::test(ManageVoiceTemplates::class)->callAction(TestAction::make('listen')->table($t))->assertHasNoErrors();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), "/internal/{$this->company->id}/voice/tts")
            && str_contains($r['text'], 'রহিম উদ্দিন') && str_contains($r['text'], 'Century Link Network') && $r['voice'] === 'Kore'
            && $r->header('x-internal-key') === ['k']);
    }

    public function test_preview_in_the_form_uses_the_chosen_customer(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageVoiceTemplates::class)
            ->mountAction(TestAction::make('create')->table())
            ->fillForm(['name' => 'Test', 'type' => 'reminder', 'source' => 'tts', 'body' => '{name}, বকেয়া {due} টাকা', 'voice' => 'Charon',
                'speed' => '1.15', 'preview_customer' => '77'])
            ->callAction(TestAction::make('preview')->schemaComponent('preview_actions', schema: 'mountedActionSchema0'))
            ->assertSchemaStateSet(['preview_text' => 'Alif Hasan, বকেয়া ৫২০ টাকা'], 'mountedActionSchema0');
        Http::assertSent(fn (Request $r) => $r['text'] === 'Alif Hasan, বকেয়া ৫২০ টাকা' && $r['voice'] === 'Charon' && (float) $r['speed'] === 1.15);
    }

    public function test_spliced_audio_keeps_each_value_apart(): void
    {
        $v = VoiceTemplates::values($this->company->id, $this->customer);
        $this->assertSame(['Alif Hasan,', 'আপনার বকেয়া', '৫২০', 'টাকা, শেষ তারিখ', '৩১ অক্টোবর।', 'ধন্যবাদ'],
            VoiceTemplates::parts('{name}, আপনার বকেয়া {due} টাকা, শেষ তারিখ {expire_date}। ধন্যবাদ', $v));
        // a word stuck to the value goes with it; values side by side stay one piece
        $this->assertSame(['Alif Hasan-এর', 'লাইন', '৫২০ ৪ ২'], VoiceTemplates::parts('{name}-এর লাইন {due} {id}', $v));
        $this->assertNull(VoiceTemplates::parts('শুধু লেখা, কোনো তথ্য নেই', $v));
        $this->assertNull(VoiceTemplates::parts('{name}', $v));

        // the form's preview sends the pieces; in "full" mode the whole text only
        $this->as($this->owner);
        $form = fn () => Livewire::test(ManageVoiceTemplates::class)->mountAction(TestAction::make('create')->table())
            ->fillForm(['name' => 'Test', 'type' => 'reminder', 'source' => 'tts', 'body' => '{name}, বকেয়া {due} টাকা', 'voice' => 'Kore',
                'speed' => '1.00', 'preview_customer' => '77'])
            ->callAction(TestAction::make('preview')->schemaComponent('preview_actions', schema: 'mountedActionSchema0'));
        $form();
        Http::assertSent(fn (Request $r) => $r['parts'] === ['Alif Hasan,', 'বকেয়া', '৫২০', 'টাকা']);
        VoiceReminderSetting::forCompany($this->company)->fill(['audio_mode' => 'full'])->save();
        Http::fake(['engine.test/internal/*/voice/tts' => Http::response(['file' => $this->company->id.'/abc'])]);
        $form();
        Http::assertSent(fn (Request $r) => $r['text'] === 'Alif Hasan, বকেয়া ৫২০ টাকা' && ! isset($r['parts']));
    }

    public function test_every_template_voice_can_be_changed_at_once(): void
    {
        $this->as($this->owner);
        VoiceTemplate::seed($this->company->id);
        $rec = VoiceTemplate::where('company_id', $this->company->id)->first();
        $rec->update(['source' => 'upload', 'body' => null, 'voice' => 'Kore']);
        Livewire::test(ManageVoiceTemplates::class)
            ->callAction(TestAction::make('voice_all')->table(), ['voice' => 'Charon', 'speed' => '1.15'])->assertHasNoErrors();
        $tts = VoiceTemplate::where('company_id', $this->company->id)->where('source', 'tts')->get();
        $this->assertGreaterThan(0, $tts->count());
        $this->assertSame(['Charon'], $tts->pluck('voice')->unique()->values()->all());
        $this->assertSame([1.15], $tts->map(fn ($t) => (float) $t->speed)->unique()->values()->all());
        $this->assertSame('Kore', $rec->fresh()->voice);   // own recordings untouched
    }

    public function test_create_keeps_one_source(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageVoiceTemplates::class)
            ->callAction(TestAction::make('create')->table(), ['name' => 'নতুন', 'type' => 'general', 'source' => 'tts',
                'body' => 'হ্যালো {name}', 'voice' => 'Puck', 'speed' => '0.85'])
            ->assertHasNoActionErrors();
        $t = VoiceTemplate::where('name', 'নতুন')->first();
        $this->assertSame($this->company->id, $t->company_id);
        $this->assertSame('Puck', $t->voice);
        $this->assertNull($t->audio_path);
        $this->assertEquals(0.85, (float) $t->speed);
    }

    public function test_audio_route_is_for_the_company_menu_users_only(): void
    {
        $dir = storage_path("app/voice-audio/{$this->company->id}");
        @mkdir($dir, 0777, true);
        $hash = str_repeat('a', 20);
        file_put_contents("{$dir}/{$hash}.mp3", 'ID3');
        $url = "/voice/{$this->company->slug}/audio/{$this->company->id}-{$hash}";
        $this->actingAs($this->owner)->get($url)->assertOk();
        $this->actingAs($this->agent)->get($url)->assertForbidden();
        $this->actingAs($this->owner)->get("/voice/{$this->company->slug}/audio/999-{$hash}")->assertNotFound();
        $this->actingAs($this->owner)->get("/voice/{$this->company->slug}/audio/..-{$hash}")->assertNotFound();
        @unlink("{$dir}/{$hash}.mp3");
    }

    public function test_agents_do_not_see_the_menu(): void
    {
        $this->as($this->agent);
        $this->assertFalse(VoiceTemplateResource::canAccess());
        $this->as($this->owner);
        $this->assertTrue(VoiceTemplateResource::canAccess());
    }
}
