<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Membership;
use App\Models\Technician;
use App\Models\User;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The Android app's inbox API: the panel's chats, replies, assign, bot pause and mute, company and menu checks. */
class AppInboxApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private WaAccount $account;

    private WaContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.send_enabled' => false]);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $this->owner = User::factory()->create(['name' => 'Zisan']);
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->account = WaAccount::create(['company_id' => $this->company->id, 'waba_id' => 'W', 'phone_number_id' => 'P',
            'access_token' => 't']);
        $this->contact = $this->chat($this->company, '8801711000001', 'Bulbul', 'লাইন বন্ধ');
    }

    private function chat(Company $company, string $wa, string $name, string $text, string $dir = 'in'): WaContact
    {
        $c = WaContact::create(['company_id' => $company->id, 'wa_number' => $wa, 'name' => $name, 'last_message_at' => now()]);
        WaMessage::create(['company_id' => $company->id, 'contact_id' => $c->id, 'wa_account_id' => $this->account->id,
            'direction' => $dir, 'sender' => $dir === 'in' ? 'customer' : 'bot', 'body' => $text]);

        return $c;
    }

    private function api(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($u->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path, ?Company $company = null): string
    {
        return '/api/v1/c/'.($company ?? $this->company)->slug.'/'.$path;
    }

    public function test_chat_list_shows_own_company_chats_with_waiting_and_staff_flags(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        $this->chat($other, '8801711999999', 'Not ours', 'hi');
        $this->chat($this->company, '8801811000000', 'Answered', 'ok', 'out');
        $tech = $this->chat($this->company, '8801911000000', 'Rafiq', 'কাজ শেষ');
        Technician::create(['company_id' => $this->company->id, 'name' => 'Rafiq', 'wa_number' => '8801911000000', 'active' => true]);

        $res = $this->api($this->owner)->getJson($this->url('chats'))->assertOk();
        $names = collect($res->json('data'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Bulbul', 'Answered', 'Rafiq'], $names->all());
        $row = collect($res->json('data'))->firstWhere('name', 'Bulbul');
        $this->assertTrue($row['waiting']);
        $this->assertSame('01711000001', $row['number']);
        $this->assertSame('লাইন বন্ধ', $row['last_message']['body']);
        $this->assertTrue(collect($res->json('data'))->firstWhere('id', $tech->id)['staff']);
        $this->assertFalse(collect($res->json('data'))->firstWhere('id', $tech->id)['waiting']);
        $res->assertJsonPath('waiting_count', 1);

        $this->api($this->owner)->getJson($this->url('chats?box=waiting'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_known_customers_show_pppoe_with_a_short_whatsapp_name_and_are_found_by_pppoe(): void
    {
        $byId = $this->chat($this->company, '8801711000002', 'Sharmin Akhter', 'hi');
        $byId->update(['customer_id' => '4306']);
        \App\Models\BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 4306, 'customer_id' => '4306',
            'name' => 'Sharmin', 'username' => 'mp.sharmin', 'mobile_normalized' => '8801700000000']);
        $byNumber = $this->chat($this->company, '8801711000003', 'Rina', 'hi');
        \App\Models\BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 5000, 'customer_id' => '5000',
            'name' => 'Rina', 'username' => 'tld.rina', 'mobile_normalized' => '8801711000003']);
        $twoLines = $this->chat($this->company, '8801711000004', 'Kamal', 'hi');
        foreach ([6001, 6002] as $h) {
            \App\Models\BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => $h, 'customer_id' => (string) $h,
                'name' => 'Kamal', 'username' => "k{$h}", 'mobile_normalized' => '8801711000004']);
        }

        $data = collect($this->api($this->owner)->getJson($this->url('chats'))->assertOk()->json('data'));
        $this->assertSame('mp.sharmin(Sharmi…)', $data->firstWhere('id', $byId->id)['name']);
        $this->assertSame('tld.rina(Rina)', $data->firstWhere('id', $byNumber->id)['name']);
        $this->assertSame('Kamal', $data->firstWhere('id', $twoLines->id)['name']);

        $found = $this->api($this->owner)->getJson($this->url('chats?q=sharmin'))->assertOk()->json('data');
        $this->assertSame([$byId->id], collect($found)->pluck('id')->all());
    }

    public function test_chat_shows_messages_and_a_reply_is_stored_signed_with_the_bot_paused(): void
    {
        $this->api($this->owner)->getJson($this->url('chats/'.$this->contact->id))->assertOk()
            ->assertJsonPath('chat.window_open', true)->assertJsonPath('messages.0.body', 'লাইন বন্ধ')
            ->assertJsonPath('members.0.name', 'Zisan')->assertJsonPath('chat.header_id', null);
        \App\Models\BillingCustomer::create(['company_id' => $this->company->id, 'header_id' => 15630, 'customer_id' => '0629',
            'name' => 'Bulbul', 'username' => 'tld.bulbul', 'monthly_bill' => 525, 'due' => 0]);
        $this->contact->update(['customer_id' => '0629']);
        $this->api($this->owner)->getJson($this->url('chats/'.$this->contact->id))
            ->assertJsonPath('chat.header_id', 15630)->assertJsonPath('chat.line_customer_id', '0629');

        $this->api($this->owner)->postJson($this->url('chats/'.$this->contact->id.'/reply'), ['body' => 'দেখছি', 'pause_minutes' => 60])
            ->assertOk()->assertJsonPath('message.status', 'test')->assertJsonPath('message.user', 'Zisan')
            ->assertJsonPath('message.body', "দেখছি\n\n- Zisan");
        $c = $this->contact->refresh();
        $this->assertTrue($c->isBotPaused());
        $this->assertSame($this->owner->id, $c->assigned_user_id);

        $this->api($this->owner)->postJson($this->url('chats/'.$this->contact->id.'/reply'), [])->assertStatus(422);
    }

    public function test_a_file_reply_is_kept_and_its_media_served(): void
    {
        Storage::fake('local');
        $res = $this->api($this->owner)->post($this->url('chats/'.$this->contact->id.'/reply'),
            ['file' => UploadedFile::fake()->image('bill.jpg'), 'body' => 'রসিদ'])->assertOk();
        $id = $res->json('message.id');
        $this->assertTrue($res->json('message.has_media'));
        $this->api($this->owner)->get($this->url('media/'.$id))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_reply_after_24_hours_is_refused_with_a_message(): void
    {
        WaMessage::where('contact_id', $this->contact->id)->update(['created_at' => now()->subDays(2)]);
        $this->api($this->owner)->postJson($this->url('chats/'.$this->contact->id.'/reply'), ['body' => 'hello'])
            ->assertStatus(422)->assertJsonStructure(['message']);
    }

    public function test_assign_bot_pause_and_mute(): void
    {
        $agent = User::factory()->create(['name' => 'Arif']);
        $this->company->users()->attach($agent, ['role' => 'agent']);
        $stranger = User::factory()->create();
        $url = fn ($p) => $this->url('chats/'.$this->contact->id.'/'.$p);

        $this->api($this->owner)->postJson($url('assign'), ['user_id' => $agent->id])->assertOk()->assertJsonPath('chat.assigned.name', 'Arif');
        $this->api($this->owner)->postJson($url('assign'), ['user_id' => 'me'])->assertOk()->assertJsonPath('chat.assigned.name', 'Zisan');
        $this->api($this->owner)->postJson($url('assign'), ['user_id' => $stranger->id])->assertStatus(422);
        $this->api($this->owner)->postJson($url('assign'), ['user_id' => null])->assertOk()->assertJsonPath('chat.assigned', null);

        $this->api($this->owner)->postJson($url('bot'), ['paused' => true])->assertOk()->assertJsonPath('chat.bot_paused', true);
        $this->assertTrue($this->contact->refresh()->bot_paused_until->gt(now()->addHours(11)));
        $this->api($this->owner)->postJson($url('bot'), ['paused' => false])->assertOk()->assertJsonPath('chat.bot_paused', false);

        $this->api($this->owner)->postJson($url('mute'), ['muted' => true])->assertOk()->assertJsonPath('chat.notify_muted', true);
    }

    public function test_other_companies_and_members_without_the_inbox_menu_are_refused(): void
    {
        $other = Company::create(['name' => 'Other ISP']);
        $theirs = $this->chat($other, '8801711999999', 'Not ours', 'hi');
        $this->api($this->owner)->getJson($this->url('chats/'.$theirs->id))->assertNotFound();
        $this->api($this->owner)->getJson($this->url('chats', $other))->assertForbidden();

        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'agent']);
        Membership::where('user_id', $agent->id)->update(['permissions' => json_encode(['customers'])]);
        $this->api($agent)->getJson($this->url('chats'))->assertForbidden();
    }

    public function test_owner_deletes_a_sent_message_in_desk_only(): void
    {
        $agent = User::factory()->create();
        $this->company->users()->attach($agent, ['role' => 'admin']);
        $out = WaMessage::create(['company_id' => $this->company->id, 'contact_id' => $this->contact->id,
            'direction' => 'out', 'sender' => 'staff', 'user_id' => $agent->id, 'body' => 'ভুল মেসেজ', 'status' => 'sent']);
        $in = $this->contact->messages()->where('direction', 'in')->first();
        $url = $this->url("chats/{$this->contact->id}/messages/");

        $this->api($this->owner)->getJson($this->url("chats/{$this->contact->id}"))->assertJsonPath('chat.can_delete', true);
        $this->api($agent)->getJson($this->url("chats/{$this->contact->id}"))->assertJsonPath('chat.can_delete', false);
        $this->api($agent)->deleteJson($url.$out->id)->assertForbidden();
        $this->api($this->owner)->deleteJson($url.$in->id)->assertForbidden();  // only messages we sent
        $this->api($this->owner)->deleteJson($url.$out->id)->assertOk();

        $this->assertSoftDeleted($out);
        $this->assertSame($this->owner->id, WaMessage::withTrashed()->find($out->id)->deleted_by);
        $ids = collect($this->api($this->owner)->getJson($this->url("chats/{$this->contact->id}"))->json('messages'))->pluck('id');
        $this->assertNotContains($out->id, $ids);
        $this->assertContains($in->id, $ids);
        $this->api($this->owner)->deleteJson($url.$out->id)->assertNotFound();
    }

    public function test_owner_turns_off_a_members_name_under_replies(): void
    {
        Membership::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['wa_signature' => false]);
        $this->api($this->owner)->postJson($this->url("chats/{$this->contact->id}/reply"), ['body' => 'ঠিক আছে'])->assertOk();
        $this->assertSame('ঠিক আছে', $this->contact->messages()->latest('id')->first()->body);
    }
}
