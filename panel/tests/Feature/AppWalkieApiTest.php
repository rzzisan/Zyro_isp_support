<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Membership;
use App\Models\User;
use App\Models\WalkieCall;
use App\Models\WalkieGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppWalkieApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $tech;

    private User $owner;

    private User $other;

    private WalkieGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.livekit' => ['url' => 'wss://rtc.example', 'api_url' => 'http://127.0.0.1:7880', 'key' => 'APIkey', 'secret' => str_repeat('s', 32)]]);
        Http::fake();   // engine push
        $this->company = Company::create(['name' => 'Century Link Network']);
        [$this->tech, $this->owner, $this->other] = User::factory()->count(3)->create()->all();
        $this->company->users()->attach($this->tech, ['role' => 'agent']);
        $this->company->users()->attach($this->owner, ['role' => 'owner']);
        $this->company->users()->attach($this->other, ['role' => 'agent']);
        $this->group = WalkieGroup::create(['company_id' => $this->company->id, 'name' => 'Line']);
        $this->group->memberships()->sync(Membership::where('company_id', $this->company->id)
            ->whereIn('user_id', [$this->tech->id, $this->owner->id])->pluck('id'));
    }

    private function api(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path): string
    {
        return '/api/v1/c/'.$this->company->slug.'/'.$path;
    }

    public function test_group_members_get_the_walkie_and_a_room_token(): void
    {
        $this->assertContains('walkie', $this->api($this->tech)->getJson('/api/v1/me')->json('companies.0.menus'));
        $this->assertNotContains('walkie', $this->api($this->other)->getJson('/api/v1/me')->json('companies.0.menus'));

        $res = $this->api($this->tech)->getJson($this->url('walkie'))->assertOk()
            ->assertJsonPath('url', 'wss://rtc.example')->assertJsonPath('on_at', '10:00')
            ->assertJsonPath('groups.0.room', "c{$this->company->id}-g{$this->group->id}")
            ->assertJsonCount(2, 'groups.0.members');
        // a LiveKit token: HS256 with the api secret, this user, this room only
        [$head, $body, $sig] = explode('.', $res->json('groups.0.token'));
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertSame("u{$this->tech->id}", $claims['sub']);
        $this->assertSame('APIkey', $claims['iss']);
        $this->assertSame("c{$this->company->id}-g{$this->group->id}", $claims['video']['room']);
        $this->assertSame(rtrim(strtr(base64_encode(hash_hmac('sha256', "$head.$body", str_repeat('s', 32), true)), '+/', '-_'), '='), $sig);

        $this->assertSame([], $this->api($this->other)->getJson($this->url('walkie'))->assertOk()->json('groups'));
        $this->api($this->other)->getJson($this->url("walkie/groups/{$this->group->id}/clips"))->assertForbidden();
        $this->api($this->tech)->getJson($this->url("walkie/groups/{$this->group->id}/clips"))->assertOk()->assertJsonPath('data', []);
    }

    public function test_private_call_rings_answers_and_ends(): void
    {
        $this->api($this->tech)->postJson($this->url('walkie/calls'), ['user_id' => $this->other->id])->assertForbidden();

        $call = $this->api($this->tech)->postJson($this->url('walkie/calls'), ['user_id' => $this->owner->id])->assertOk()
            ->assertJsonPath('status', 'ringing')->assertJsonPath('outgoing', true)->json();
        $this->assertNotEmpty($call['token']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), "/internal/{$this->company->id}/push") && $r['data']['type'] === 'walkie_call'
            && $r['user_ids'] === [$this->owner->id]);

        $this->api($this->tech)->postJson($this->url("walkie/calls/{$call['id']}/answer"))->assertForbidden();
        $this->api($this->owner)->postJson($this->url("walkie/calls/{$call['id']}/answer"))->assertOk()
            ->assertJsonPath('status', 'talking')->assertJsonPath('other.id', $this->tech->id);
        $this->api($this->other)->getJson($this->url("walkie/calls/{$call['id']}"))->assertForbidden();
        $this->api($this->owner)->postJson($this->url("walkie/calls/{$call['id']}/end"))->assertOk()
            ->assertJsonPath('status', 'ended')->assertJsonPath('token', null);

        // declined, and one nobody answers goes missed
        $c2 = $this->api($this->tech)->postJson($this->url('walkie/calls'), ['user_id' => $this->owner->id])->json('id');
        $this->api($this->owner)->postJson($this->url("walkie/calls/{$c2}/end"))->assertJsonPath('status', 'declined');
        $c3 = $this->api($this->tech)->postJson($this->url('walkie/calls'), ['user_id' => $this->owner->id])->json('id');
        WalkieCall::whereKey($c3)->update(['created_at' => now()->subMinute()]);
        $this->api($this->tech)->getJson($this->url("walkie/calls/{$c3}"))->assertJsonPath('status', 'missed');
    }

    public function test_only_the_owner_manages_groups_and_hears_recordings(): void
    {
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/walkie-groups")->assertOk()->assertSee('Line');
        $this->actingAs($this->owner)->get("/app/{$this->company->slug}/walkie-recordings")->assertOk();
        $this->actingAs($this->tech)->get("/app/{$this->company->slug}/walkie-groups")->assertForbidden();
        $this->actingAs($this->tech)->get("/app/{$this->company->slug}/walkie-recordings")->assertForbidden();
    }
}
