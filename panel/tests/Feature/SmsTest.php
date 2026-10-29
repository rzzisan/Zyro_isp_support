<?php

namespace Tests\Feature;

use App\Filament\App\Pages\SmsSettings;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Filament\App\Resources\SmsCampaigns\Pages\ListSmsCampaigns;
use App\Filament\App\Resources\SmsGateways\Pages\ManageSmsGateways;
use App\Filament\App\Resources\SmsTemplates\Pages\ManageSmsTemplates;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\Membership;
use App\Models\SmsCampaign;
use App\Models\SmsGateway;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Support\Sms;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Desk SMS: length/parts, numbers, filters on the desk's customer copy, the queue, gateway settings, permissions. */
class SmsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        Http::fake([
            'engine.test/internal/*/sms/messages/*/send*' => Http::response(['ok' => true, 'status' => '0', 'text' => 'ACCEPTD', 'error' => null]),
            'engine.test/internal/*/sms/validate*' => Http::response(['client' => 'centurylinknetwork', 'balance' => 797.88]),
            'engine.test/*' => Http::response(['started' => true]),
        ]);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        $c = fn (int $h, string $id, string $name, ?string $mobile, array $extra = []) => BillingCustomer::create([
            'company_id' => $this->company->id, 'header_id' => $h, 'customer_id' => $id, 'name' => $name, 'mobile' => $mobile,
            'mobile_normalized' => $mobile ? '88'.$mobile : null, 'username' => 'u'.$id, 'status' => 'Active', ...$extra]);
        $c(1, '0001', 'Alif', '01711000001', ['zone' => 'Binodpur', 'due' => 525, 'monthly_bill' => 525, 'package' => '10 Mbps']);
        $c(2, '0002', 'Bulbul', '01811000002', ['zone' => 'Binodpur', 'due' => 0, 'monthly_bill' => 600]);
        $c(3, '0003', 'Chitra', '01911000003', ['zone' => 'Zisan', 'due' => 300, 'monthly_bill' => 500]);
        $c(4, '0004', 'Dipu', '123', ['zone' => 'Binodpur', 'due' => 200]);                       // no valid mobile
        $c(5, '0005', 'Emon', '01711000001', ['zone' => 'Binodpur', 'due' => 100]);               // same number as Alif
        $c(6, '0006', 'Faruk', '01611000006', ['zone' => 'Binodpur', 'due' => 900, 'is_left' => true]);
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_parts_follow_gsm_and_unicode_limits(): void
    {
        $this->assertSame(['chars' => 160, 'units' => 160, 'unicode' => false, 'parts' => 1, 'per_part' => 160], Sms::parts(str_repeat('a', 160)));
        $this->assertSame(2, Sms::parts(str_repeat('a', 161))['parts']);
        $this->assertSame(3, Sms::parts(str_repeat('a', 307))['parts']);           // 153 per part
        $this->assertSame(2, Sms::parts(str_repeat('a', 159).'{')['parts']);       // extension char counts twice
        $bn = Sms::parts(str_repeat('ক', 70));
        $this->assertTrue($bn['unicode']);
        $this->assertSame(1, $bn['parts']);
        $this->assertSame(2, Sms::parts(str_repeat('ক', 71))['parts']);
        $this->assertSame(3, Sms::parts(str_repeat('ক', 135))['parts']);          // 67 per part
        $this->assertSame(0, Sms::parts('')['parts']);
    }

    public function test_mobile_numbers_are_normalized(): void
    {
        $this->assertSame('8801711000001', Sms::mobile('01711-000001'));
        $this->assertSame('8801711000001', Sms::mobile('+8801711000001'));
        $this->assertSame('8801711000001', Sms::mobile('008801711000001'));
        $this->assertSame('8801711000001', Sms::mobile('1711000001'));
        $this->assertSame('8801711000001', Sms::mobile('০১৭১১০০০০০১'));
        $this->assertNull(Sms::mobile('01211000001'));
        $this->assertNull(Sms::mobile('12345'));
        $this->assertSame([['8801711000001', '8801811000002'], ['abc']], Sms::numbers("01711000001, 01811000002\nabc 01711000001"));
    }

    public function test_placeholders_are_filled_per_customer(): void
    {
        $c = BillingCustomer::where('header_id', 1)->first();
        $this->assertSame('প্রিয় Alif (0001), বকেয়া 525 টাকা {x}', Sms::render('প্রিয় {name} ({id}), বকেয়া {due} টাকা {x}', $c));
        $this->assertSame('বকেয়া 0', Sms::render('বকেয়া {due}', null));
        // bill payment: its own values win ({due} = left after the payment); outside one they stay empty
        $this->assertSame('Alif 400 ছাড় 25 বাকি 100 CLN', Sms::render('{name} {paid} ছাড় {discount} বাকি {due} {company}', $c,
            ['paid' => '400', 'discount' => '25', 'due' => '100', 'company' => 'CLN']));
        $this->assertSame('জমা  টাকা', Sms::render('জমা {paid} টাকা', $c));
        $this->assertSame(['paid', 'discount'], Sms::billOnly('{name} {paid} {discount} {due}'));
        $this->assertArrayHasKey('paid', Sms::placeholders('bill_paid'));
        $this->assertArrayNotHasKey('paid', Sms::placeholders());
        $this->assertSame('Century Link Network', Sms::companyName($this->company->id));
    }

    public function test_bulk_filters_pick_recipients_from_the_desk_copy(): void
    {
        [$by, $skipped] = Sms::byMobile(Sms::recipients($this->company->id, ['zone' => ['Binodpur'], 'due' => 'due'])->orderBy('customer_id')->get());
        // Alif and Emon share a number (first wins), Dipu has none, Faruk is Left
        $this->assertSame(['8801711000001'], array_map('strval', array_keys($by)));
        $this->assertSame('Alif', $by['8801711000001']->name);
        $this->assertSame(1, $skipped);
        $this->assertSame(1, Sms::recipients($this->company->id, ['list' => 'left'])->count());
        $this->assertSame(2, Sms::recipients($this->company->id, ['due_min' => 300])->count());
    }

    public function test_bulk_send_queues_one_sms_per_number_and_wakes_the_engine(): void
    {
        $this->smsGateway($this->company->id, ['rate' => 0.35]);
        $this->as($this->owner);
        Livewire::test(ListSmsCampaigns::class)
            ->callAction('sendSms', ['mode' => 'bulk', 'list' => 'current', 'zone' => ['Binodpur', 'Zisan'], 'due' => 'due',
                'body' => 'প্রিয় {name}, আপনার বকেয়া {due} টাকা।', 'confirm' => true])
            ->assertHasNoActionErrors();
        $campaign = SmsCampaign::sole();
        $this->assertSame('bulk', $campaign->kind);
        $this->assertSame(2, $campaign->total);                          // Alif (Emon's number is the same), Chitra
        $this->assertSame($this->owner->id, $campaign->user_id);
        $this->assertEquals(['zone' => ['Binodpur', 'Zisan'], 'due' => 'due', 'list' => 'current'], $campaign->filters);
        $this->assertEqualsWithDelta(0.70, (float) $campaign->cost, 0.001);
        $texts = SmsMessage::orderBy('id')->pluck('text', 'mobile')->all();
        $this->assertSame(['8801711000001' => 'প্রিয় Alif, আপনার বকেয়া 525 টাকা।', '8801911000003' => 'প্রিয় Chitra, আপনার বকেয়া 300 টাকা।'], $texts);
        $this->assertSame(['queued'], SmsMessage::distinct()->pluck('status')->all());
        $this->assertTrue((bool) SmsMessage::first()->unicode);
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/sms/kick" && $r->hasHeader('x-internal-key', 'k'));
    }

    public function test_bulk_send_needs_the_confirm_tick(): void
    {
        $this->as($this->owner);
        Livewire::test(ListSmsCampaigns::class)
            ->callAction('sendSms', ['mode' => 'bulk', 'list' => 'current', 'body' => 'hello'])
            ->assertHasActionErrors(['confirm']);
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_single_send_to_a_typed_number_uses_the_customer_it_belongs_to(): void
    {
        $this->as($this->owner);
        Livewire::test(ListSmsCampaigns::class)
            ->callAction('sendSms', ['mode' => 'single', 'customers' => ['3'], 'numbers' => '01811000002, 01511000099, xyz',
                'body' => 'Hi {name}'])
            ->assertHasNoActionErrors();
        $rows = SmsMessage::orderBy('id')->get(['mobile', 'text', 'customer_header_id'])->map->only(['mobile', 'text', 'customer_header_id'])->all();
        $this->assertSame([
            ['mobile' => '8801911000003', 'text' => 'Hi Chitra', 'customer_header_id' => 3],
            ['mobile' => '8801811000002', 'text' => 'Hi Bulbul', 'customer_header_id' => 2],
            ['mobile' => '8801511000099', 'text' => 'Hi ', 'customer_header_id' => null],
        ], $rows);
    }

    public function test_template_fills_the_text(): void
    {
        $t = SmsTemplate::create(['company_id' => $this->company->id, 'name' => 'Due', 'body' => 'বকেয়া {due}']);
        $this->as($this->owner);
        Livewire::test(ListSmsCampaigns::class)
            ->mountAction('sendSms')
            ->fillForm(['template' => $t->id])
            ->assertSchemaStateSet(['body' => 'বকেয়া {due}'], 'mountedActionSchema0');
    }

    public function test_template_editor_shows_placeholder_chips_per_type(): void
    {
        $this->as($this->owner);
        $html = $this->get('/app/'.$this->company->slug.'/sms-templates')->assertOk()->getContent();
        $this->assertStringContainsString('SMS টেমপ্লেট', $html);
        $chips = view('filament.app.sms.placeholders', ['items' => Sms::placeholders('bill_paid')])->render();
        foreach (['{paid}', '{discount}', '{due}', '{name}', 'textarea[data-sms-body]', 'setSelectionRange', "new Event('input'"] as $needle) {
            $this->assertStringContainsString($needle, $chips);
        }
        $this->assertStringNotContainsString('{paid}', view('filament.app.sms.placeholders', ['items' => Sms::placeholders()])->render());
        Livewire::test(ManageSmsTemplates::class)
            ->callAction(TestAction::make('create')->table(),
                ['type' => 'bill_paid', 'name' => 'Paid', 'body' => 'প্রিয় {name}, {paid} টাকা পেয়েছি'])
            ->assertHasNoActionErrors();
        $this->assertSame('bill_paid', SmsTemplate::where('name', 'Paid')->value('type'));
    }

    public function test_customer_page_sends_to_that_customer(): void
    {
        $c = BillingCustomer::where('header_id', 1)->first();
        $this->as($this->owner);
        Livewire::test(ViewCustomer::class, ['record' => $c->id])
            ->callAction('sendSmsToCustomer', ['body' => 'Hello {name}'])
            ->assertHasNoActionErrors();
        $this->assertSame(['8801711000001' => 'Hello Alif'], SmsMessage::pluck('text', 'mobile')->all());
    }

    public function test_cancel_stops_what_has_not_gone(): void
    {
        $campaign = Sms::queue($this->company->id, $this->owner->id, 'single', 'x', [], ['8801711000001', '8801811000002']);
        SmsMessage::where('mobile', '8801711000001')->update(['status' => 'sent']);
        $this->as($this->owner);
        Livewire::test(ListSmsCampaigns::class)->callAction(TestAction::make('cancel')->table($campaign));
        $this->assertSame(['8801711000001' => 'sent', '8801811000002' => 'cancelled'], SmsMessage::pluck('status', 'mobile')->all());
        $this->assertSame('cancelled', $campaign->fresh()->status);
    }

    public function test_gateway_keys_are_encrypted_and_never_shown_again(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageSmsGateways::class)
            ->callAction(TestAction::make('create')->table(), ['provider' => 'khudebarta', 'endpoint_url' => 'https://portal.example.test:3775/sendtext',
                'api_key' => 'AK-123', 'secret_key' => 'SK-456', 'caller_id' => 'CenturyLink', 'rate' => 0.35, 'verify_tls' => true])
            ->assertHasNoFormErrors();
        $raw = DB::table('sms_gateways')->first();
        $this->assertStringNotContainsString('AK-123', $raw->api_key);
        $g = SmsGateway::sole();
        $this->assertSame('AK-123', $g->api_key);
        // the first gateway becomes the main one
        $this->assertSame($g->id, SmsSetting::forCompany($this->company)->primary_gateway_id);
        Livewire::test(ManageSmsGateways::class)->assertDontSee('AK-123')->assertDontSee('SK-456')
            // blank keys keep the saved ones
            ->callAction(TestAction::make('edit')->table($g), ['caller_id' => 'CLN', 'api_key' => '', 'secret_key' => ''])
            ->assertHasNoFormErrors();
        $g->refresh();
        $this->assertSame(['AK-123', 'SK-456', 'CLN'], [$g->api_key, $g->secret_key, $g->caller_id]);
        $this->assertTrue(SmsSetting::forCompany($this->company)->configured());
    }

    public function test_bulksmsdhaka_needs_no_secret_key(): void
    {
        $this->as($this->owner);
        Livewire::test(ManageSmsGateways::class)
            ->callAction(TestAction::make('create')->table(), ['provider' => 'bulksmsdhaka', 'endpoint_url' => 'https://bulksmsdhaka.net/api',
                'api_key' => 'BK-1', 'caller_id' => '1234', 'rate' => 0.34])
            ->assertHasNoFormErrors();
        $g = SmsGateway::sole();
        $this->assertSame(['bulksmsdhaka', null], [$g->provider, $g->secret_key]);
        $this->assertTrue($g->configured());
        $this->assertEqualsWithDelta(0.34, SmsSetting::forCompany($this->company)->rate(), 0.0001);
    }

    public function test_fallback_takes_over_while_the_main_gateway_is_stopped(): void
    {
        $main = $this->smsGateway($this->company->id, ['rate' => 0.30]);
        $backup = SmsGateway::create(['company_id' => $this->company->id, 'provider' => 'bulksmsdhaka',
            'endpoint_url' => 'https://bulksmsdhaka.net/api', 'api_key' => 'x', 'caller_id' => '1234', 'rate' => 0.34]);
        $s = SmsSetting::forCompany($this->company);
        $s->fill(['fallback_gateway_id' => $backup->id])->save();
        $this->assertSame($main->id, $s->fresh()->activeGateway()->id);
        $main->update(['failed_at' => now(), 'last_error' => '2001 Balance Insufficient']);
        $this->assertSame($backup->id, $s->fresh()->activeGateway()->id);
        $this->assertEqualsWithDelta(0.34, $s->fresh()->rate(), 0.0001);
        // another company's gateway can never be the fallback
        $other = Company::create(['name' => 'Other ISP']);
        $theirs = SmsGateway::create(['company_id' => $other->id, 'provider' => 'bulksmsdhaka', 'endpoint_url' => 'https://x.test',
            'api_key' => 'y', 'caller_id' => '1']);
        $this->as($this->owner);
        Livewire::test(SmsSettings::class)->fillForm(['primary_gateway_id' => $main->id, 'fallback_gateway_id' => $theirs->id])
            ->call('save');
        $this->assertNotSame($theirs->id, $s->fresh()->fallback_gateway_id);
        // switching sending back on gives stopped gateways another chance
        Livewire::test(SmsSettings::class)->fillForm(['primary_gateway_id' => $main->id, 'enabled' => true])->call('save');
        $this->assertNull($main->fresh()->failed_at);
    }

    public function test_sending_stopped_by_itself_is_shown_on_every_page_until_saved(): void
    {
        $this->smsGateway($this->company->id);
        SmsSetting::forCompany($this->company)->fill(['enabled' => false, 'last_error' => 'গেটওয়ে Khudebarta: -4 Insufficient Balance'])->save();
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/sms-log")->assertOk()
            ->assertSee('SMS পাঠানো নিজে থেকে বন্ধ হয়ে গেছে')->assertSee('Insufficient Balance');
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/sms")->assertDontSee('SMS পাঠানো নিজে থেকে বন্ধ হয়ে গেছে');
        $this->as($this->owner);
        Livewire::test(SmsSettings::class)->call('save');
        $this->assertNull(SmsSetting::forCompany($this->company)->last_error);
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/sms-log")->assertDontSee('SMS পাঠানো নিজে থেকে বন্ধ হয়ে গেছে');
    }

    public function test_test_sms_goes_now_through_the_engine(): void
    {
        $g = $this->smsGateway($this->company->id);
        $this->as($this->owner);
        Livewire::test(ManageSmsGateways::class)
            ->callAction(TestAction::make('testSms')->table($g), ['mobile' => '01711000001', 'text' => 'test'])
            ->assertHasNoActionErrors()->assertNotified('গেটওয়ে SMS নিয়েছে');
        $m = SmsMessage::sole();
        $this->assertSame(['8801711000001', 'test'], [$m->mobile, $m->text]);
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/sms/messages/{$m->id}/send?gateway={$g->id}");
    }

    public function test_validate_shows_client_and_balance_without_sending(): void
    {
        $g = $this->smsGateway($this->company->id);
        $this->as($this->owner);
        Livewire::test(ManageSmsGateways::class)->callAction(TestAction::make('validate')->table($g))->assertNotified('কানেকশন ঠিক আছে');
        Http::assertSent(fn ($r) => $r->url() === "http://engine.test/internal/{$this->company->id}/sms/validate?gateway={$g->id}");
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_gateway_list_shows_only_the_companys_own(): void
    {
        $this->smsGateway($this->company->id, ['label' => 'আমাদের গেটওয়ে']);
        $other = Company::create(['name' => 'Other ISP']);
        SmsGateway::create(['company_id' => $other->id, 'provider' => 'bulksmsdhaka', 'label' => 'ওদের গেটওয়ে',
            'endpoint_url' => 'https://x.test', 'api_key' => 'y', 'caller_id' => '1']);
        $this->as($this->owner);
        Livewire::test(ManageSmsGateways::class)->assertSee('আমাদের গেটওয়ে')->assertDontSee('ওদের গেটওয়ে');
        $this->actingAs($this->agent)->get("/app/{$this->company->slug}/sms-gateways")->assertForbidden();
    }

    public function test_sms_pages_need_the_sms_permission(): void
    {
        $slug = $this->company->slug;
        foreach (['sms', 'sms-log', 'sms-templates'] as $page) {
            $this->actingAs($this->owner)->get("/app/{$slug}/{$page}")->assertOk();
            $this->actingAs($this->agent)->get("/app/{$slug}/{$page}")->assertForbidden();   // agents: off by default
        }
        $this->actingAs($this->agent)->get("/app/{$slug}/sms-gateway")->assertForbidden();
        Membership::where('user_id', $this->agent->id)->update(['permissions' => json_encode(['sms'])]);
        $this->actingAs($this->agent)->get("/app/{$slug}/sms")->assertOk();
        $this->actingAs($this->agent)->get("/app/{$slug}/sms-gateway")->assertForbidden();  // settings: owner/admin only
        $this->actingAs($this->owner)->get("/app/{$slug}/sms-gateway")->assertOk();
    }
}
