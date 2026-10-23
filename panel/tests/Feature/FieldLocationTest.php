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

    public function test_consent_comes_before_duty_and_points(): void
    {
        $point = ['lat' => 23.75, 'lng' => 90.39, 'accuracy' => 12, 'battery' => 80, 'at' => now()->toIso8601String()];
        $this->api($this->tech)->getJson($this->url('field/location'))->assertOk()->assertJsonPath('consent', false)->assertJsonPath('track', false);
        $this->api($this->tech)->postJson($this->url('field/duty'), ['on' => true])->assertStatus(422);
        $this->api($this->tech)->postJson($this->url('field/locations'), ['points' => [$point]])->assertStatus(422);

        $this->api($this->tech)->postJson($this->url('field/consent'))->assertOk()->assertJsonPath('consent', true)->assertJsonPath('track', false);
        $this->api($this->tech)->postJson($this->url('field/duty'), ['on' => true])->assertOk()
            ->assertJsonPath('track', true)->assertJsonPath('interval', 300);
        $this->api($this->tech)->postJson($this->url('field/locations'), ['points' => [$point,
            ['lat' => 23.7, 'lng' => 90.3, 'at' => now()->addHour()->toIso8601String()],     // wrong phone clock
            ['lat' => 23.7, 'lng' => 90.3, 'mock' => true, 'at' => now()->subMinute()->toIso8601String()]]])
            ->assertOk()->assertJsonPath('saved', 2);
        $this->assertSame(1, FieldLocation::where('mock', true)->count());

        $this->api($this->tech)->postJson($this->url('field/duty'), ['on' => false])->assertOk()->assertJsonPath('track', false);

        // someone without the menu cannot send
        Membership::where('user_id', $this->tech->id)->update(['permissions' => json_encode(['inbox'])]);
        $this->api($this->tech)->getJson($this->url('field/location'))->assertForbidden();
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
        $this->api($this->tech)->getJson($this->url('field/location'))->assertJsonPath('track', true)->assertJsonPath('interval', 60);
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
}
