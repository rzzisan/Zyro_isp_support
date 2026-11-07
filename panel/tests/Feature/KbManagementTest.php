<?php

namespace Tests\Feature;

use App\Filament\Super\Pages\KbManagement;
use App\Models\KbKeywordOverride;
use App\Models\KbRouteOverride;
use App\Models\User;
use App\Services\KbCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** ZDbot step 10: KB catalog reads docs/kb, routing overlay, keyword test box, super-admin only. */
class KbManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_reads_repo_kb_and_splits_sales_part(): void
    {
        $files = app(KbCatalog::class)->files();
        $this->assertGreaterThanOrEqual(36, count($files));
        $this->assertArrayHasKey('super.kb_manager', $files);
        $this->assertNotSame('', $files['billing.collect']['sales'] ?? '', 'সেলস অংশ শিরোনাম ধরে ভাগ হয়');
        $this->assertStringNotContainsString('## সেলস অংশ', $files['billing.collect']['customer']);
        $this->assertStringNotContainsString('সেলস', $files['billing.collect']['customer']);
    }

    public function test_index_rows_and_coverage_are_read(): void
    {
        $cov = app(KbCatalog::class)->coverage();
        $this->assertSame(count(app(KbCatalog::class)->indexRows()), $cov['index_rows']);
        $this->assertIsArray($cov['broken']);
    }

    public function test_panel_add_disable_and_keyword_change_effective_routes(): void
    {
        $catalog = app(KbCatalog::class);
        $files = $catalog->files();
        $from = collect($files)->first(fn ($f) => $f['related'] !== []);
        $repoEdge = $from['related'][0]['to'];

        KbRouteOverride::create(['from_id' => $from['id'], 'to_id' => $repoEdge, 'action' => 'disable', 'active' => true]);
        $this->assertFalse(collect($catalog->activeEdges())->contains(fn ($e) => $e['from'] === $from['id'] && $e['to'] === $repoEdge));

        KbRouteOverride::create(['from_id' => 'super.kb_manager', 'to_id' => 'billing.collect', 'action' => 'add', 'when' => 'বিল দিয়েছি', 'active' => true]);
        $this->assertTrue(collect($catalog->activeEdges())->contains(fn ($e) => $e['from'] === 'super.kb_manager' && $e['to'] === 'billing.collect'));

        KbKeywordOverride::create(['kb_id' => 'billing.collect', 'keyword' => 'টাকা জমা', 'active' => true]);
        $this->assertContains('টাকা জমা', $catalog->keywords('billing.collect'));
    }

    public function test_test_box_picks_main_file_and_related_by_when(): void
    {
        KbRouteOverride::create(['from_id' => 'billing.collect', 'to_id' => 'line.enable_disable', 'action' => 'add', 'when' => 'লাইন চালু', 'active' => true]);
        $res = app(KbCatalog::class)->testQuestion('বিল দিয়েছি কিন্তু লাইন চালু হয়নি');
        $this->assertNotNull($res['main']);
        $this->assertNotEmpty($res['scores']);
    }

    public function test_only_super_admin_reaches_page(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false]));
        $this->assertFalse(KbManagement::canAccess());
    }

    public function test_super_admin_adds_route_and_toggles_it(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin);

        Livewire::test(KbManagement::class)
            ->set('newFrom', 'billing.collect')->set('newTo', 'line.enable_disable')->set('newWhen', 'লাইন চালু না হলে')
            ->call('addRoute')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('kb_route_overrides', ['from_id' => 'billing.collect', 'to_id' => 'line.enable_disable', 'action' => 'add']);

        Livewire::test(KbManagement::class)->call('toggleEdge', 'billing.collect', 'line.enable_disable');
        $this->assertDatabaseHas('kb_route_overrides', ['from_id' => 'billing.collect', 'to_id' => 'line.enable_disable', 'active' => false]);
    }

    public function test_unknown_kb_id_is_refused(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
        Livewire::test(KbManagement::class)
            ->set('newFrom', 'billing.collect')->set('newTo', 'nope.nothing')
            ->call('addRoute');
        $this->assertDatabaseMissing('kb_route_overrides', ['to_id' => 'nope.nothing']);
    }
}
