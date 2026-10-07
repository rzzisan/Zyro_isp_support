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
            ->set('pauseHours', 2)
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
        $this->assertSame($agent->id, $this->contact->assigned_user_id);
    }

    public function test_reply_with_sending_enabled_uses_company_token(): void
    {
        config(['services.whatsapp.send_enabled' => true]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $this->as($this->owner);
        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])
            ->set('reply', 'ঠিক আছে')->set('pauseHours', 0)->call('send')->assertHasNoErrors();
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
        $this->assertTrue($this->contact->bot_paused);
        $this->assertSame($agent->id, $this->contact->assigned_user_id);

        Livewire::test(ViewConversation::class, ['record' => $this->contact->id])->callAction('resume');
        $this->contact->refresh();
        $this->assertFalse($this->contact->isBotPaused());
        $this->assertNull($this->contact->ident_state);
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
            ->set('reply', 'এই রাউটারের লাইট দেখুন')->set('pauseHours', 0)
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
}
