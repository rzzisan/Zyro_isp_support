<?php

namespace Tests\Feature;

use App\Models\BillingCustomer;
use App\Models\BillingOption;
use App\Models\BillingTicket;
use App\Models\Company;
use App\Models\CustomerLocation;
use App\Models\FieldJob;
use App\Models\FieldLocation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Technician location, told to them: consent, duty, points, start / finish places, and the owner-only page. */
class FieldLocationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.engine.url' => 'http://engine.test', 'services.engine.key' => 'k']);
        $this->company = Company::create(['name' => 'Century Link Network']);
        $cid = $this->company->id;
        $this->tech = User::factory()->create(['name' => 'Rifat']);
        $this->company->users()->attach($this->tech, ['role' => 'agent']);
        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['field_jobs']), 'billing_employee_id' => '3']);
        BillingOption::create(['company_id' => $cid, 'kind' => 'employees', 'option_id' => '3', 'name' => 'Rifat']);
        BillingCustomer::create(['company_id' => $cid, 'header_id' => 15630, 'customer_id' => '5629', 'name' => 'Bulbul',
            'username' => 'tld.bulbul', 'mobile' => '01838216754']);
        Http::fake(function ($request) use ($cid) {
            $url = $request->url();

            return match (true) {
                str_contains($url, "/internal/{$cid}/monitor/") => Http::response(['online' => true]),
                str_ends_with($url, '/solve') => Http::response(['ok' => true]),
                default => Http::response([], 404),
            };
        });
    }

    private function api(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($u->createToken('test')->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function url(string $path): string
    {
        return '/api/v1/c/'.$this->company->slug.'/'.$path;
    }

    private function ticket(): BillingTicket
    {
        return BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '1', 'state' => 'processing',
            'customer_header_id' => 15630, 'customer_id' => '5629', 'customer_name' => 'Bulbul', 'category' => 'Line Off',
            'assigned_to' => 'Rifat', 'opened_at' => now()->subHour()]);
    }

    private function at(string $dhaka): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-24 '.$dhaka, 'Asia/Dhaka'));
    }

    private function send(float $lat, float $lng, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->api($this->tech)->postJson($this->url('field/locations'), ['points' => [['lat' => $lat, 'lng' => $lng,
            'accuracy' => 10, 'at' => now()->toIso8601String()] + $extra]]);
    }

    public function test_privacy_at_login_is_the_consent(): void
    {
        $this->tech->forceFill(['email' => 'rifat@example.com', 'password' => 'secret-pass'])->save();
        $this->api($this->tech)->getJson($this->url('field/location'))->assertOk()->assertJsonPath('consent', false)->assertJsonPath('track', false);
        $this->send(23.75, 90.39)->assertStatus(422);

        $this->postJson('/api/v1/login', ['email' => 'rifat@example.com', 'password' => 'secret-pass', 'privacy' => true])->assertOk();
        $this->assertNotNull(Membership::where('user_id', $this->tech->id)->value('location_consent_at'));

        // someone without the menu cannot send
        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['inbox'])]);
        $this->api($this->tech)->getJson($this->url('field/location'))->assertForbidden();
    }

    public function test_duty_starts_at_the_office_after_ten_and_ends_at_five(): void
    {
        Membership::where('user_id', $this->tech->id)->update(['location_consent_at' => now()]);
        \App\Models\BotSetting::create(['company_id' => $this->company->id, 'field_office_lat' => 23.7500, 'field_office_lng' => 90.3900]);
        $this->ticket();

        $this->at('09:30');   // before the start: no location
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('track', false);

        $this->at('10:05');   // waiting to reach the office
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('track', true)->assertJsonPath('on_duty', false);
        $this->send(23.7600, 90.3900)->assertJsonPath('on_duty', false);                    // 1 km away
        $this->send(23.7500, 90.3900, ['mock' => true])->assertJsonPath('on_duty', false);   // fake GPS
        $this->send(23.75015, 90.3900)->assertJsonPath('on_duty', true)->assertJsonPath('track', true);   // ~17 m

        $this->at('17:01');
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('on_duty', false)->assertJsonPath('track', false);
    }

    public function test_duty_ends_when_the_work_is_done_and_tomorrow_work_does_not_count(): void
    {
        Membership::where('user_id', $this->tech->id)->update(['location_consent_at' => now()]);
        $this->at('10:30');
        $t1 = $this->ticket();
        $t2 = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '2', 'state' => 'processing',
            'customer_header_id' => 15630, 'category' => 'Line Off', 'assigned_to' => 'Rifat', 'opened_at' => now()]);
        $this->send(23.75, 90.39)->assertJsonPath('on_duty', true);   // no office set: the first point in hours

        $this->api($this->tech)->postJson($this->url("my-jobs/{$t2->id}/start"))->assertOk();
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t2->id}/later"))->assertOk()
            ->assertJsonPath('ticket.later_on', '2026-10-25')->assertJsonPath('ticket.job.status', 'paused');
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t1->id}/start"))->assertOk();
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t1->id}/solve"))->assertOk();
        $t1->update(['state' => 'solved', 'solved_at' => now()]);
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('on_duty', false)->assertJsonPath('track', false);

        // a new ticket the same day brings duty back without going to the office again
        BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '3', 'state' => 'pending',
            'customer_header_id' => 15630, 'category' => 'Line Off', 'assigned_to' => 'Rifat', 'opened_at' => now()]);
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('on_duty', true);

        // the next day the put-off ticket counts again
        $this->at('11:00');
        $this->travel(1)->days();
        $this->api($this->tech)->getJson($this->url('my-jobs'))->assertOk();
        $this->assertSame(2, \App\Support\FieldDuty::remaining($this->tech, $this->company));
    }

    public function test_old_points_are_deleted(): void
    {
        Membership::where('user_id', $this->tech->id)->update(['location_consent_at' => now()]);
        FieldLocation::create(['company_id' => $this->company->id, 'user_id' => $this->tech->id, 'lat' => 1, 'lng' => 1,
            'recorded_at' => now()->subDays(91)]);
        $this->api($this->tech)->postJson($this->url('field/locations'), ['points' => [['lat' => 23.75, 'lng' => 90.39,
            'at' => now()->toIso8601String()]]])->assertOk();
        $this->assertSame(1, FieldLocation::count());
    }

    public function test_a_running_job_tracks_every_minute_and_notes_where_it_started_and_finished(): void
    {
        Membership::where('user_id', $this->tech->id)->update(['location_consent_at' => now()]);
        $t = $this->ticket();
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/start"), ['lat' => 23.7500, 'lng' => 90.3900, 'accuracy' => 20])->assertOk();
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('track', true)->assertJsonPath('job_running', true);
        $job = FieldJob::first();
        $this->assertEqualsWithDelta(23.75, $job->start_lat, 0.0001);
        $this->assertNull($job->start_distance);   // the customer's place is not known yet

        $this->api($this->tech)->postJson($this->url('field/locations'), ['points' => [['lat' => 23.751, 'lng' => 90.39, 'at' => now()->toIso8601String()]]]);
        $this->assertSame($job->id, FieldLocation::first()->field_job_id);

        // finishing online from a good fix teaches where the customer is
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t->id}/solve"), ['lat' => 23.7510, 'lng' => 90.3900, 'accuracy' => 15])->assertOk();
        $this->assertNotNull($home = CustomerLocation::where('header_id', 15630)->first());
        $this->assertEqualsWithDelta(23.751, $home->lat, 0.0001);
        $this->assertSame(0, (int) FieldJob::first()->finish_distance);

        // the next job there measures the distance
        $t2 = BillingTicket::create(['company_id' => $this->company->id, 'complain_id' => '2', 'state' => 'processing',
            'customer_header_id' => 15630, 'category' => 'Line Off', 'assigned_to' => 'Rifat', 'opened_at' => now()]);
        $this->api($this->tech)->postJson($this->url("my-jobs/{$t2->id}/start"), ['lat' => 23.7600, 'lng' => 90.3900, 'mock' => true])->assertOk();
        $job2 = FieldJob::where('ticket_id', $t2->id)->first();
        $this->assertTrue($job2->start_mock);
        $this->assertEqualsWithDelta(1001, $job2->start_distance, 15);
    }

    public function test_only_the_owner_opens_the_map_page(): void
    {
        $owner = User::factory()->create();
        $this->company->users()->attach($owner, ['role' => 'owner']);
        $admin = User::factory()->create();
        $this->company->users()->attach($admin, ['role' => 'admin']);
        FieldLocation::create(['company_id' => $this->company->id, 'user_id' => $this->tech->id, 'lat' => 23.75, 'lng' => 90.39,
            'battery' => 64, 'recorded_at' => now()]);
        $url = "/app/{$this->company->slug}/field-locations";

        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Rifat')->assertSee('64%');
        $this->actingAs($admin)->get($url)->assertForbidden();
        $this->actingAs($this->tech)->get($url)->assertForbidden();

        \Livewire\Livewire::actingAs($owner)->test(\App\Filament\App\Pages\FieldLocations::class)
            ->call('showRoute', $this->tech->id)->assertOk()->assertSee('কিমি');
    }

    public function test_owner_sets_the_office_and_duty_hours(): void
    {
        $owner = User::factory()->create();
        $this->company->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($owner)->get("/app/{$this->company->slug}/staff-teams")->assertOk();
        \Filament\Facades\Filament::setCurrentPanel('app');
        \Filament\Facades\Filament::setTenant($this->company);
        \Livewire\Livewire::test(\App\Filament\App\Resources\StaffTeams\Pages\ManageStaffTeams::class)
            ->callAction('fieldSettings', data: ['field_job_minutes' => 90, 'field_office_lat' => '23.7512345', 'field_office_lng' => '90.3912345',
                'field_office_radius' => 25, 'field_duty_start' => '09:30', 'field_duty_end' => '18:00'])->assertHasNoActionErrors();
        $this->assertSame([23.7512345, 90.3912345, 25, '09:30', '18:00'], \App\Support\FieldDuty::settings($this->company->id));
    }
}
