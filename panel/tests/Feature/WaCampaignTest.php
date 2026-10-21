<?php

namespace Tests\Feature;

use App\Filament\App\Resources\WaCampaignMessages\Pages\ListWaCampaignMessages;
use App\Filament\App\Resources\WaCampaigns\Pages\ListWaCampaigns;
use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaCampaign;
use App\Models\WaCampaignMessage;
use App\Support\WaTemplates;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** "WhatsApp পাঠান": approved templates from Meta, {{n}} mapping, filters shared with SMS, the queue, permissions. */
class WaCampaignTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    private const BODY = "প্রিয় {{1}},\nআপনার ইন্টারনেট সংযোগের তথ্য: আইডি {{2}}, প্যাকেজ {{3}}, মেয়াদ {{4}} পর্যন্ত।";

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k',
            'services.whatsapp.graph_version' => 'v26.0']);
        Http::fake([
            'graph.facebook.com/v26.0/waba1/message_templates*' => Http::response(['data' => [
                ['name' => 'account_support_update', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'bn',
                    'components' => [['type' => 'BODY', 'text' => self::BODY]]],
                ['name' => 'pending_one', 'status' => 'PENDING', 'category' => 'UTILITY', 'language' => 'bn',
                    'components' => [['type' => 'BODY', 'text' => 'x']]],
                ['name' => 'offer_image', 'status' => 'APPROVED', 'category' => 'MARKETING', 'language' => 'bn',
                    'components' => [['type' => 'HEADER', 'format' => 'IMAGE'], ['type' => 'BODY', 'text' => 'অফার {{1}}']]],
            ]]),
            'graph.facebook.com/v26.0/pn1*' => Http::response(['messaging_limit_tier' => 'TIER_1K', 'quality_rating' => 'GREEN']),
            'engine.test/*' => Http::response(['started' => true]),
        ]);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create();
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        WaAccount::create(['company_id' => $this->company->id, 'waba_id' => 'waba1', 'phone_number_id' => 'pn1',
            'access_token' => 'tok', 'bot_enabled' => true, 'utility_rate' => 0.5]);
        $c = fn (int $h, string $id, string $name, ?string $mobile, array $extra = []) => BillingCustomer::create([
            'company_id' => $this->company->id, 'header_id' => $h, 'customer_id' => $id, 'name' => $name, 'mobile' => $mobile,
            'mobile_normalized' => $mobile ? '88'.$mobile : null, 'username' => 'u'.$id, 'status' => 'Active', ...$extra]);
        $c(1, '0071', 'Rajib Hossain', '01711000001', ['zone' => 'Binodpur', 'package' => '20 Mbps', 'bill_day' => 5]);
        $c(2, '0072', 'Bulbul', '01811000002', ['zone' => 'Binodpur', 'package' => '10 Mbps']);
        $c(3, '0073', 'Chitra', '01911000003', ['zone' => 'Zisan', 'package' => '10 Mbps']);
        $c(4, '0074', 'Dipu', '123', ['zone' => 'Binodpur']);                       // no valid mobile
    }

    private function as(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    public function test_only_approved_templates_are_listed_and_media_headers_are_not_supported(): void
    {
        $all = WaTemplates::templates($this->company->id);
        $this->assertSame(['account_support_update|bn', 'offer_image|bn'], array_keys($all));
        $t = $all['account_support_update|bn'];
        $this->assertTrue($t['supported']);
        $this->assertSame(4, $t['params']);
        $this->assertFalse($all['offer_image|bn']['supported']);
    }

    public function test_values_follow_the_mapping_and_never_go_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'Asia/Dhaka'));
        $c = BillingCustomer::where('header_id', 1)->first();
        $map = [['field' => 'name'], ['field' => 'id'], ['field' => 'package'], ['field' => 'expire_date']];
        $this->assertSame(['Rajib Hossain', '0071', '20 Mbps', '05-11-2026'], WaTemplates::values($this->company->id, $map, 4, $c));
        $this->assertSame(['-', 'নিজে  লেখা'], WaTemplates::values($this->company->id,
            [['field' => 'package'], ['field' => '_text', 'text' => "নিজে\n লেখা"]], 2, null));
        $t = WaTemplates::templates($this->company->id)['account_support_update|bn'];
        $this->assertStringContainsString('প্রিয় Rajib Hossain,', WaTemplates::text($t, ['Rajib Hossain', '0071', '20 Mbps', '05-11-2026']));
    }

    public function test_bulk_send_queues_one_message_per_number_and_wakes_the_engine(): void
    {
        $this->as($this->owner);
        Livewire::test(ListWaCampaigns::class)
            ->callAction('sendWa', data: ['mode' => 'bulk', 'list' => 'current', 'zone' => ['Binodpur'],
                'template' => 'account_support_update|bn', 'p1' => 'name', 'p2' => 'id', 'p3' => 'package',
                'p4' => '_text', 'p4_text' => '30-11-2026', 'confirm' => true])
            ->assertHasNoActionErrors();
        $campaign = WaCampaign::sole();
        $this->assertSame(2, $campaign->total);                 // Dipu has no valid number
        $this->assertSame('UTILITY', $campaign->template_category);
        $this->assertEquals(1.0, (float) $campaign->cost);       // 2 x 0.5
        $m = WaCampaignMessage::where('customer_id', '0071')->sole();
        $this->assertSame('8801711000001', $m->wa_number);
        $this->assertSame(['Rajib Hossain', '0071', '20 Mbps', '30-11-2026'], $m->params);
        $this->assertSame('queued', $m->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/internal/'.$this->company->id.'/wa-campaigns/kick'));
    }

    public function test_send_needs_the_confirm_tick_and_every_variable(): void
    {
        $this->as($this->owner);
        Livewire::test(ListWaCampaigns::class)
            ->callAction('sendWa', data: ['mode' => 'single', 'customers' => ['1'], 'template' => 'account_support_update|bn',
                'p1' => 'name', 'p2' => 'id', 'p3' => 'package', 'p4' => null, 'confirm' => false])
            ->assertHasActionErrors(['confirm', 'p4']);
        $this->assertSame(0, WaCampaign::count());
    }

    public function test_cancel_stops_what_has_not_gone(): void
    {
        $this->as($this->owner);
        $t = WaTemplates::templates($this->company->id)['account_support_update|bn'];
        $c = BillingCustomer::where('header_id', 1)->first();
        $campaign = WaTemplates::queue($this->company->id, $this->owner->id, 'single', $t,
            [['field' => 'name'], ['field' => 'id'], ['field' => 'package'], ['field' => 'expire_date']],
            ['8801711000001' => $c], ['8801999000999']);
        $this->assertSame(2, $campaign->total);
        Livewire::test(ListWaCampaigns::class)->callAction(TestAction::make('cancel')->table($campaign));
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame(0, $campaign->messages()->where('status', 'queued')->count());
    }

    public function test_pages_need_the_permission(): void
    {
        $this->as($this->agent);
        $this->get(ListWaCampaigns::getUrl())->assertForbidden();
        $this->get(ListWaCampaignMessages::getUrl())->assertForbidden();
        $this->as($this->owner);
        $this->get(ListWaCampaigns::getUrl())->assertOk()->assertSee('WhatsApp পাঠান');
        $this->get(ListWaCampaignMessages::getUrl())->assertOk();
    }
}
