<?php

namespace Tests\Feature;

use App\Filament\App\Pages\TeamChat;
use App\Models\Company;
use App\Models\Membership;
use App\Models\StaffChatMessage;
use App\Models\User;
use App\Models\WalkieGroup;
use App\Support\StaffChat;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** টিম চ্যাট (App\Support\StaffChat): one to one between anyone, walkie groups as group chats, owner sees groups only. */
class TeamChatTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $tech;

    private User $owner;

    private User $agent;

    private WalkieGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();   // engine push
        $this->company = Company::create(['name' => 'Century Link Network']);
        [$this->tech, $this->owner, $this->agent] = User::factory()->count(3)->create()->all();
        $this->company->users()->attach($this->tech, ['role' => 'agent']);
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->agent, ['role' => 'agent']);
        $this->group = WalkieGroup::create(['company_id' => $this->company->id, 'name' => 'Line']);
        $this->group->memberships()->sync(Membership::where('company_id', $this->company->id)
            ->whereIn('user_id', [$this->tech->id, $this->agent->id])->pluck('id'));
    }

    protected function tearDown(): void
    {
        @exec('rm -rf '.escapeshellarg(storage_path('app/staff-chat/'.$this->company->id)));
        parent::tearDown();
    }

    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path): string
    {
        return '/api/v1/c/'.$this->company->slug.'/team-chat'.($path === '' ? '' : '/'.$path);
    }

    public function test_everyone_gets_the_team_chat_menu(): void
    {
        $this->assertContains('team_chat', $this->api($this->agent)->getJson('/api/v1/me')->json('companies.0.menus'));
    }

    public function test_one_to_one_message_is_unread_then_read_and_seen(): void
    {
        $this->api($this->tech)->postJson($this->url('u'.$this->owner->id), ['body' => 'লাইন ঠিক হয়েছে'])->assertOk()
            ->assertJsonPath('message.mine', true)->assertJsonPath('message.text', 'লাইন ঠিক হয়েছে');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/internal/'.$this->company->id.'/push')
            && $r['user_ids'] === [$this->owner->id] && $r['data']['type'] === 'team_chat'
            && $r['data']['chat'] === 'u'.$this->tech->id && $r['web']['url'] === '/app/'.$this->company->slug.'/team-chat?chat=u'.$this->tech->id);

        $list = $this->api($this->owner)->getJson($this->url(''))->assertOk();
        $list->assertJsonPath('unread', 1);
        $chat = collect($list->json('chats'))->firstWhere('ref', 'u'.$this->tech->id);
        $this->assertSame(1, $chat['unread']);
        $this->assertSame('direct', $chat['kind']);

        // the tech's message shows not yet seen; the owner opens the chat, then it is
        $this->api($this->tech)->getJson($this->url('u'.$this->owner->id))->assertJsonPath('messages.0.seen', false);
        $this->api($this->owner)->getJson($this->url('u'.$this->tech->id))->assertOk()->assertJsonPath('messages.0.mine', false);
        $this->api($this->owner)->getJson($this->url(''))->assertJsonPath('unread', 0);
        $this->api($this->tech)->getJson($this->url('u'.$this->owner->id))->assertJsonPath('messages.0.seen', true);
    }

    public function test_group_chat_is_the_walkie_group_and_owner_sees_groups_not_others_one_to_one(): void
    {
        $this->api($this->tech)->postJson($this->url('g'.$this->group->id), ['body' => 'সবাই অফিসে আসুন'])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/push') && $r['user_ids'] === [$this->agent->id]);
        $this->api($this->tech)->postJson($this->url('u'.$this->agent->id), ['body' => 'গোপন'])->assertOk();

        // the owner is not in the group but sees it, and not the tech-agent chat
        $refs = collect($this->api($this->owner)->getJson($this->url(''))->json('chats'))->pluck('ref')->all();
        $this->assertSame(['g'.$this->group->id], $refs);
        $this->api($this->owner)->getJson($this->url('g'.$this->group->id))->assertOk()->assertJsonPath('messages.0.text', 'সবাই অফিসে আসুন');

        // someone not in the group can't open it
        $outsider = User::factory()->create();
        $this->company->users()->attach($outsider, ['role' => 'agent']);
        $this->api($outsider)->getJson($this->url('g'.$this->group->id))->assertNotFound();
        $this->assertSame([], $this->api($outsider)->getJson($this->url(''))->json('chats'));
    }

    public function test_muted_member_gets_no_push(): void
    {
        $this->api($this->agent)->postJson($this->url('g'.$this->group->id.'/mute'), ['muted' => true])->assertOk();
        Http::fake();
        $this->api($this->tech)->postJson($this->url('g'.$this->group->id), ['body' => 'hi'])->assertOk();
        Http::assertNothingSent();
    }

    public function test_photo_and_voice_are_kept_and_served_only_to_the_chat(): void
    {
        $this->api($this->tech)->post($this->url('u'.$this->agent->id), ['file' => UploadedFile::fake()->image('a.png', 3000, 2000)])
            ->assertOk()->assertJsonPath('message.media.type', 'image');
        $img = StaffChatMessage::latest('id')->first();
        $this->assertSame('image/jpeg', $img->media_mime);
        [$w] = getimagesize($img->file());
        $this->assertSame(1600, $w);

        $voice = UploadedFile::fake()->createWithContent('v.m4a', "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A isom");
        $this->api($this->tech)->post($this->url('u'.$this->agent->id), ['file' => $voice, 'kind' => 'voice', 'seconds' => 7])
            ->assertOk()->assertJsonPath('message.media.type', 'voice')->assertJsonPath('message.media.seconds', 7);

        $this->api($this->agent)->get($this->url('media/'.$img->id))->assertOk();
        $this->api($this->owner)->get($this->url('media/'.$img->id))->assertNotFound();   // not the owner's chat

        // after 90 days the file goes, the message stays
        $img->forceFill(['created_at' => now()->subDays(91)])->save();
        $this->assertSame(1, StaffChat::prune());
        $this->assertNotNull($img->fresh()->media_gone_at);
        $this->api($this->agent)->getJson($this->url('u'.$this->tech->id))->assertJsonPath('messages.0.media.gone', true);
    }

    public function test_delete_own_within_15_minutes_owner_any(): void
    {
        $id = $this->api($this->tech)->postJson($this->url('g'.$this->group->id), ['body' => 'ভুল'])->json('message.id');
        $this->api($this->agent)->deleteJson($this->url('g'.$this->group->id.'/messages/'.$id))->assertForbidden();
        $this->api($this->tech)->deleteJson($this->url('g'.$this->group->id.'/messages/'.$id))->assertOk();
        $this->api($this->agent)->getJson($this->url('g'.$this->group->id))->assertJsonPath('messages.0.deleted', true)
            ->assertJsonPath('messages.0.text', null);

        $id = $this->api($this->tech)->postJson($this->url('g'.$this->group->id), ['body' => 'পুরনো'])->json('message.id');
        StaffChatMessage::whereKey($id)->update(['created_at' => now()->subMinutes(20)]);
        $this->api($this->tech)->deleteJson($this->url('g'.$this->group->id.'/messages/'.$id))->assertForbidden();
        $this->api($this->owner)->deleteJson($this->url('g'.$this->group->id.'/messages/'.$id))->assertOk();
    }

    public function test_cannot_message_yourself_or_someone_outside_the_company(): void
    {
        $this->api($this->tech)->postJson($this->url('u'.$this->tech->id), ['body' => 'x'])->assertNotFound();
        $stranger = User::factory()->create();
        $this->api($this->tech)->postJson($this->url('u'.$stranger->id), ['body' => 'x'])->assertNotFound();
    }

    public function test_panel_page_sends_and_shows_messages(): void
    {
        $this->actingAs($this->tech)->get('/app/'.$this->company->slug.'/team-chat')->assertOk()
            ->assertSee('Line')->assertSee($this->owner->name);

        Filament::setTenant($this->company);
        Livewire::actingAs($this->tech)->test(TeamChat::class)
            ->call('open', 'u'.$this->owner->id)->set('text', 'প্যানেল থেকে')->call('send')->assertHasNoErrors()
            ->assertSee('প্যানেল থেকে');
        $this->assertSame('u'.min($this->tech->id, $this->owner->id).'-'.max($this->tech->id, $this->owner->id),
            StaffChatMessage::latest('id')->value('chat'));

        $this->actingAs($this->owner)->get('/app/'.$this->company->slug.'/team-chat?chat=u'.$this->tech->id)->assertOk()
            ->assertSee('প্যানেল থেকে');
    }
}
