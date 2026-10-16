<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Conversations\Pages\ListConversations;
use App\Filament\App\Resources\Conversations\Pages\ViewConversation;
use App\Models\Company;
use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaDraft;
use App\Models\WaMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private WaContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.send_enabled' => false]);
        [$this->company, $this->owner, $this->contact] = $this->companyWithChat('Century Link Network', '8801711000001');
    }

    private function companyWithChat(string $name, string $wa): array
    {
        $company = Company::create(['name' => $name]);
        $owner = User::factory()->create();
        $company->users()->attach($owner, ['role' => 'owner']);
        $account = WaAccount::create(['company_id' => $company->id, 'waba_id' => 'WABA'.$company->id, 'phone_number_id' => 'PN'.$company->id,
            'access_token' => 'token-'.$company->id]);
        $contact = WaContact::create(['company_id' => $company->id, 'wa_number' => $wa, 'name' => "Customer of $name",
            'last_message_at' => now()]);
        WaMessage::create(['company_id' => $company->id, 'contact_id' => $contact->id, 'wa_account_id' => $account->id,
            'wa_message_id' => 'wamid.'.$wa, 'direction' => 'in', 'sender' => 'customer', 'body' => 'লাইন বন্ধ']);

        return [$company, $owner, $contact];
    }

    private function as(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        Filament::setTenant($this->company);
    }

    private function agent(): User
    {
        $u = User::factory()->create();
        $this->company->users()->attach($u, ['role' => 'agent']);

        return $u;
    }

    public function test_inbox_lists_only_own_company_chats(): void
    {
        [, , $other] = $this->companyWithChat('Other ISP', '8801711999999');
        $this->as($this->owner);
        Livewire::test(ListConversations::class)
            ->assertSee('Customer of Century Link Network')->assertDontSee('Customer of Other ISP')
            ->call('setBox', 'waiting')->assertSee('Customer of Century Link Network')
            ->set('search', 'nobody-here')->assertDontSee('Customer of Century Link Network');
    }

    public function test_agent_opens_inbox_and_chat_but_not_another_companys_chat(): void
    {
        [, , $other] = $this->companyWithChat('Other ISP', '8801711999999');
        $agent = $this->agent();
        $slug = $this->company->slug;
        $this->actingAs($agent)->get("/app/{$slug}/inbox")->assertOk();
        $this->actingAs($agent)->get("/app/{$slug}/inbox/{$this->contact->id}")->assertOk()->assertSee('লাইন বন্ধ');
        $this->actingAs($agent)->get("/app/{$slug}/inbox/{$other->id}")->assertNotFound();
    }

    public function test_reply_in_test_mode_is_saved_not_sent_and_pauses_bot(): void
    {
        Http::fake();
        $agent = $this->agent();
        $this->as($agent);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->set('reply', 'ভাই, রাউটার রিস্টার্ট দিন')
            ->set('pauseMinutes', 30)
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('reply', '');
        Http::assertNothingSent();
        $m = WaMessage::where('sender', 'staff')->sole();
        $this->assertSame('test', $m->status);
        $this->assertSame($agent->id, $m->user_id);
        $this->assertSame("ভাই, রাউটার রিস্টার্ট দিন\n\n- ".$agent->name, $m->body);
        $this->contact->refresh();
        $this->assertTrue($this->contact->isBotPaused());
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, $this->contact->bot_paused_until->timestamp, 60);
        $this->assertSame($agent->id, $this->contact->assigned_user_id);
    }

    public function test_reply_with_sending_enabled_uses_company_token(): void
    {
        config(['services.whatsapp.send_enabled' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->set('reply', 'ঠিক আছে')->set('pauseMinutes', 0)->call('send')->assertHasNoErrors();
        Http::assertSent(fn ($r) => str_contains($r->url(), "/PN{$this->company->id}/messages")
            && $r->hasHeader('Authorization', "Bearer token-{$this->company->id}")
            && $r['to'] === '8801711000001' && $r['text']['body'] === "ঠিক আছে\n\n- ".$this->owner->name);
        $this->assertSame('wamid.OUT1', WaMessage::where('sender', 'staff')->value('wa_message_id'));
        $this->assertFalse($this->contact->fresh()->isBotPaused());
    }

    public function test_reply_blocked_after_24_hours(): void
    {
        WaMessage::query()->update(['created_at' => now()->subHours(30)]);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->set('reply', 'হ্যালো')->call('send');
        $this->assertSame(0, WaMessage::where('sender', 'staff')->count());
    }

    public function test_pause_resume_assign_and_use_draft(): void
    {
        $agent = $this->agent();
        $this->contact->forceFill(['ident_state' => ['stage' => 'agent']])->save();
        $draft = WaDraft::create(['company_id' => $this->company->id, 'contact_id' => $this->contact->id,
            'mode' => 'shadow', 'draft' => 'রাউটারটা একবার বন্ধ করে চালু করুন']);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->assertSee('রাউটারটা একবার বন্ধ করে চালু করুন')
            ->call('useDraft', $draft->id)->assertSet('reply', 'রাউটারটা একবার বন্ধ করে চালু করুন')
            ->callAction('pause')
            ->callAction('assign', ['user_id' => $agent->id]);
        $this->contact->refresh();
        $this->assertTrue($this->contact->isBotPaused());
        $this->assertFalse($this->contact->bot_paused);
        $this->assertEqualsWithDelta(now()->addHours(12)->timestamp, $this->contact->bot_paused_until->timestamp, 60);
        $this->assertSame($agent->id, $this->contact->assigned_user_id);

        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])->callAction('resume');
        $this->contact->refresh();
        $this->assertFalse($this->contact->isBotPaused());
        $this->assertNull($this->contact->ident_state);
    }

    public function test_muted_chat_gets_no_notification_and_is_listed(): void
    {
        [, , $other] = $this->companyWithChat('Temp', '8801711000002');
        $other->forceFill(['company_id' => $this->company->id, 'name' => 'Other Person'])->save();
        WaMessage::where('contact_id', $other->id)->update(['company_id' => $this->company->id]);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])->callAction('mute');
        $this->assertTrue($this->contact->refresh()->notify_muted);

        $poll = $this->getJson("/notify/{$this->company->slug}/poll?after=0")->assertOk();
        $this->assertSame([$other->id], array_column($poll->json('items'), 'contact'));
        $this->assertSame((int) WaMessage::max('id'), $poll->json('last'));   // muted messages are still skipped past

        Livewire::test(ListConversations::class)->call('setBox', 'muted')
            ->assertSee('Customer of Century Link Network')->assertDontSee('Other Person');

        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])->callAction('unmute');
        $this->assertFalse($this->contact->refresh()->notify_muted);
    }

    public function test_cannot_use_another_companys_draft(): void
    {
        [$otherCo, , $other] = $this->companyWithChat('Other ISP', '8801711999999');
        $draft = WaDraft::create(['company_id' => $otherCo->id, 'contact_id' => $other->id, 'mode' => 'shadow', 'draft' => 'secret']);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->call('useDraft', $draft->id)->assertNotFound();
    }

    public function test_media_only_for_members_and_cached(): void
    {
        Storage::fake('local');
        Http::fake([
            'graph.facebook.com/*/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/x/MEDIA1']),
            'lookaside.fbsbx.com/*' => Http::response('OGGDATA'),
        ]);
        $m = WaMessage::create(['company_id' => $this->company->id, 'contact_id' => $this->contact->id,
            'wa_account_id' => WaAccount::where('company_id', $this->company->id)->value('id'),
            'direction' => 'in', 'sender' => 'customer', 'type' => 'audio', 'media_id' => 'MEDIA1', 'media_mime' => 'audio/ogg']);
        [, $stranger] = $this->companyWithChat('Other ISP', '8801711999999');

        $this->actingAs($stranger)->get("/media/{$m->id}")->assertForbidden();
        $this->actingAs($this->owner)->get("/media/{$m->id}")->assertOk();
        $this->actingAs($this->owner)->get("/media/{$m->id}")->assertOk();
        Http::assertSentCount(2); // second view served from disk
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', "Bearer token-{$this->company->id}"));
    }

    public function test_send_image_with_caption(): void
    {
        Storage::fake('local');
        config(['services.whatsapp.send_enabled' => true]);
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'MEDIA9']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.IMG1']]]),
        ]);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->set('attachment', \Illuminate\Http\UploadedFile::fake()->image('router.jpg', 40, 40))
            ->set('reply', 'এই রাউটারের লাইট দেখুন')->set('pauseMinutes', 0)
            ->call('send')->assertHasNoErrors()->assertSet('attachment', null)->assertSet('reply', '');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/media') && $r->hasHeader('Authorization', "Bearer token-{$this->company->id}"));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['type'] === 'image'
            && $r['image']['id'] === 'MEDIA9' && $r['image']['caption'] === "এই রাউটারের লাইট দেখুন\n\n- ".$this->owner->name);
        $m = WaMessage::where('sender', 'staff')->sole();
        $this->assertSame(['image', 'MEDIA9', 'wamid.IMG1'], [$m->type, $m->media_id, $m->wa_message_id]);
        Storage::disk('local')->assertExists("media/{$this->company->id}/{$m->id}");
        $this->get("/media/{$m->id}")->assertOk();
    }

    public function test_send_rejects_unsupported_file(): void
    {
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->set('attachment', \Illuminate\Http\UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload'))
            ->call('send')->assertHasErrors(['attachment']);
        $this->assertSame(0, WaMessage::where('sender', 'staff')->count());
    }

    public function test_agent_sets_changes_and_removes_the_chats_customer_id(): void
    {
        $line = fn (string $id, string $mobile) => \App\Models\BillingCustomer::create(['company_id' => $this->company->id,
            'header_id' => (int) $id + 1000, 'customer_id' => $id, 'name' => "Cust {$id}", 'username' => "kp.c{$id}",
            'mobile' => $mobile, 'mobile_normalized' => '88'.$mobile, 'status' => 'Active']);
        $line('0101', '01711000001');
        $line('0202', '01811000002');
        $agent = $this->agent();
        $this->as($agent);

        // the registered number's only line is suggested before anyone sets it
        $this->assertSame('0101', $this->contact->suggestedCustomerId());
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->assertActionVisible('linkCustomer')->assertActionHidden('unlinkCustomer')
            ->mountAction('linkCustomer')->assertSchemaStateSet(['customer_id' => '0101'])
            ->callMountedAction()->assertHasNoActionErrors();
        $c = $this->contact->fresh();
        $this->assertSame('0101', $c->customer_id);
        $this->assertSame($agent->id, $c->customer_set_by);
        $this->assertNotNull($c->customer_set_at);

        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->callAction('linkCustomer', ['customer_id' => '0202'])->assertHasNoActionErrors()
            ->assertSee('0202');
        $this->assertSame('0202', $this->contact->fresh()->customer_id);

        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->assertActionVisible('unlinkCustomer')->callAction('unlinkCustomer');
        $c = $this->contact->fresh();
        $this->assertNull($c->customer_set_at);
        $this->assertNull($c->customer_set_by);
        $this->assertSame('0202', $c->customer_id);
    }

    /** Details column: the chat customer's MikroTik/ONU now, and Solve on their open ticket (blocked line refused). */
    private function lineFor(array $mikrotik): \App\Models\BillingTicket
    {
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $cid = $this->company->id;
        \App\Models\BillingCustomer::create(['company_id' => $cid, 'header_id' => 15630, 'customer_id' => '5629',
            'name' => 'Rezaul', 'mobile' => '01838216754', 'username' => 'rz5629']);
        $this->contact->forceFill(['customer_id' => '5629'])->save();
        Http::fake([
            "engine.test/internal/{$cid}/customers/15630/ticket-info" => Http::response(['customer' => ['customer_id' => '5629', 'due' => 0],
                'mikrotik' => $mikrotik, 'onu' => ['source' => 'olt', 'OnuStatus' => 'Online', 'OpticalPower' => -19.5, 'OLTName' => 'OLT-1', 'OLTPort' => '0/1:7']]),
            "engine.test/internal/{$cid}/tickets/*/solve" => Http::response(['ok' => true]),
        ]);

        return \App\Models\BillingTicket::create(['company_id' => $cid, 'complain_id' => '57231', 'state' => 'processing',
            'customer_header_id' => 15630, 'customer_id' => '5629', 'mobile' => '01838216754', 'category' => 'Line Off', 'opened_at' => now()]);
    }

    public function test_details_show_line_now_and_solve_an_open_ticket(): void
    {
        $t = $this->lineFor(['online' => true, 'uptime' => '2h5m', 'address' => '10.20.0.9', 'router' => 'CCR', 'download_bytes' => 3221225472, 'upload_bytes' => 52428800]);
        $this->as($this->agent());
        $w = Livewire::test(\App\Filament\App\Resources\Conversations\Widgets\CustomerLine::class, ['customerId' => '5629'])
            ->assertSee('MikroTik · অনলাইন')->assertSee('2h5m')->assertSee('3 GB')->assertSee('50 MB')->assertSee('-19.5 dBm')->assertSee('#57231')
            ->assertActionVisible(\Filament\Actions\Testing\TestAction::make('solve')->arguments(['ticket' => $t->id]))
            ->callAction(\Filament\Actions\Testing\TestAction::make('solve')->arguments(['ticket' => $t->id]), ['remark' => 'ok'])
            ->assertHasNoErrors()->assertNotified('টিকিট #57231 সমাধান হয়েছে');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tickets/57231/solve') && $r['remark'] === 'ok');
    }

    public function test_details_refuse_solve_on_a_blocked_line(): void
    {
        $t = $this->lineFor(['online' => false, 'blocked' => true, 'profile' => 'BLOCKED-PROFILE', 'router' => 'CCR']);
        $this->as($this->agent());
        Livewire::test(\App\Filament\App\Resources\Conversations\Widgets\CustomerLine::class, ['customerId' => '5629'])
            ->assertSee('MikroTik · ব্লক')->assertSee('BLOCKED-PROFILE')
            ->assertActionDisabled(\Filament\Actions\Testing\TestAction::make('solve')->arguments(['ticket' => $t->id]));
    }

    public function test_details_cannot_solve_another_customers_ticket(): void
    {
        $this->lineFor(['online' => true]);
        $other = \App\Models\BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '9', 'state' => 'pending',
            'customer_id' => '7777', 'opened_at' => now()]);
        $this->as($this->agent());
        Livewire::test(\App\Filament\App\Resources\Conversations\Widgets\CustomerLine::class, ['customerId' => '5629'])
            ->assertActionHidden(\Filament\Actions\Testing\TestAction::make('solve')->arguments(['ticket' => $other->id]));
    }
}
