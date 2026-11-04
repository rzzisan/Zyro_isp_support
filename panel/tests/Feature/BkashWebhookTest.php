<?php

namespace Tests\Feature;

use App\Filament\App\Pages\BkashWebhookSettings;
use App\Filament\App\Resources\OnlinePayments\OnlinePaymentResource;
use App\Filament\App\Resources\OnlinePayments\Pages\ListOnlinePayments;
use App\Models\BillingCustomer;
use App\Models\BillingOnlinePayment;
use App\Models\BkashWebhook;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BkashWebhookTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create(['name' => 'Zisan']);
        $this->agent = User::factory()->create();
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 6798, 'customer_id' => '0042', 'name' => 'Alif', 'username' => 'ng.alif']);
    }

    private function panel(User $u): void
    {
        $this->actingAs($u);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function payment(array $over = []): BillingOnlinePayment
    {
        return BillingOnlinePayment::create([...['company_id' => $this->company->id, 'source' => 'desk_webhook', 'billing_row_id' => 1,
            'gateway' => 'bKash', 'type_for' => 'BKASH_WEBHOOK', 'trx_id' => 'BK4J420ANO', 'amount' => 525, 'reference' => 'bill',
            'payer' => '8801711000111', 'paid_at' => now()->subHour()], ...$over]);
    }

    public function test_owner_saves_the_settings_and_sees_the_url(): void
    {
        $this->panel($this->owner);
        Livewire::test(BkashWebhookSettings::class)
            ->assertSee('https://desk.zyrotechbd.com/pay/bkash/webhook/'.$this->company->slug)
            ->assertSee(__('এখনো subscribe হয়নি'))
            ->fillForm(['enabled' => true, 'merchant_number' => '01777-858289', 'settle_in_billing' => true, 'settle_user_id' => $this->owner->id])
            ->call('save')->assertHasNoFormErrors();
        $w = BkashWebhook::where('company_id', $this->company->id)->first();
        $this->assertTrue($w->enabled);
        $this->assertSame('01777858289', $w->merchant_number);
        $this->assertTrue($w->settle_in_billing);
        $this->assertSame($this->owner->id, $w->settle_user_id);
    }

    public function test_settling_needs_a_login_to_post_with(): void
    {
        $this->panel($this->owner);
        Livewire::test(BkashWebhookSettings::class)
            ->fillForm(['enabled' => true, 'settle_in_billing' => true, 'settle_user_id' => null])
            ->call('save')->assertHasFormErrors(['settle_user_id']);
    }

    public function test_agents_cannot_open_the_settings(): void
    {
        $this->actingAs($this->agent)->get('/app/'.$this->company->slug.'/bkash-webhook-settings')->assertForbidden();
        $this->actingAs($this->owner)->get('/app/'.$this->company->slug.'/bkash-webhook-settings')->assertOk();
    }

    public function test_pending_subscription_is_confirmed_through_the_engine(): void
    {
        BkashWebhook::create(['company_id' => $this->company->id, 'pending_topic_arn' => 'arn:aws:sns:ap-southeast-1:1:bpt',
            'pending_subscribe_url' => 'https://sns.ap-southeast-1.amazonaws.com/?Action=ConfirmSubscription']);
        Http::fake(["engine.test/internal/{$this->company->id}/bkash-webhook/confirm" => Http::response(['topic_arn' => 'arn:aws:sns:ap-southeast-1:1:bpt'])]);
        $this->panel($this->owner);
        Livewire::test(BkashWebhookSettings::class)->assertSee(__('নিশ্চিত করা বাকি: :t', ['t' => 'arn:aws:sns:ap-southeast-1:1:bpt']))->callAction('confirm');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bkash-webhook/confirm') && $r->header('x-internal-key') === ['k']);
    }

    public function test_unmatched_desk_payment_shows_and_is_settled_with_a_picked_customer(): void
    {
        $p = $this->payment();
        $cid = $this->company->id;
        Http::fake(["engine.test/internal/{$cid}/bkash-webhook/payments/{$p->id}/settle*" => Http::response(['state' => 'settled'])]);
        $this->panel($this->owner);
        $this->assertSame('1', OnlinePaymentResource::getNavigationBadge());
        Livewire::test(ListOnlinePayments::class)
            ->assertCanSeeTableRecords([$p])->assertSee(__('কাস্টমার চেনা যায়নি'))
            ->filterTable('source', 'desk_webhook')->assertCanSeeTableRecords([$p])
            ->callTableAction('settle', $p, ['header_id' => '6798'])->assertHasNoTableActionErrors();
        $p->refresh();
        $this->assertSame(6798, (int) $p->customer_header_id);
        $this->assertSame('0042', $p->customer_id);
        $this->assertSame('staff', $p->matched_by);
        Http::assertSent(fn ($r) => str_contains($r->url(), "/payments/{$p->id}/settle") && str_contains($r->url(), 'user_id='.$this->owner->id));
    }

    public function test_settled_or_billing_rows_have_no_settle_action(): void
    {
        $done = $this->payment(['customer_header_id' => 6798, 'customer_id' => '0042', 'settle_state' => 'settled']);
        $billing = $this->payment(['source' => 'webhook', 'billing_row_id' => 2, 'trx_id' => 'X2', 'status_id' => 2]);
        $this->panel($this->owner);
        Livewire::test(ListOnlinePayments::class)
            ->assertTableActionHidden('settle', $done)->assertTableActionHidden('settle', $billing)
            ->assertSee(__('বিলিং-এ জমা হয়েছে'));
        $this->assertNull(OnlinePaymentResource::getNavigationBadge());
    }

    public function test_failed_settle_shows_the_engine_error(): void
    {
        $p = $this->payment(['customer_header_id' => 6798, 'customer_id' => '0042', 'settle_state' => 'failed', 'settle_error' => 'pending bill']);
        Http::fake(['engine.test/*' => Http::response(['detail' => 'এই কাস্টমারের একটা বিল approve হয়নি'], 400)]);
        $this->panel($this->owner);
        $this->assertSame('1', OnlinePaymentResource::getNavigationBadge());
        Livewire::test(ListOnlinePayments::class)->callTableAction('settle', $p, ['header_id' => '6798'])
            ->assertNotified(__('জমা হয়নি'));
    }
}
