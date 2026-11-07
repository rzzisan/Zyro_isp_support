<?php

namespace App\Filament\Super\Pages;

use App\Models\KbKeywordOverride;
use App\Models\KbRouteOverride;
use App\Services\KbCatalog;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;

/**
 * ZDbot step 10 (plan §19): super-admin view of the AI support knowledge base (docs/kb) and its routing.
 * Repo files are read-only here; routing and extra keywords are an overlay in the DB, effective at once.
 */
class KbManagement extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $slug = 'kb-management';

    protected static string|\UnitEnum|null $navigationGroup = 'সিস্টেম';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.super.pages.kb-management';

    public string $tab = 'files';

    public ?string $fileId = null;

    public string $newFrom = '';

    public string $newTo = '';

    public string $newWhen = '';

    public string $kwKb = '';

    public string $kwText = '';

    public string $testQ = '';

    public ?array $testResult = null;

    public static function getNavigationLabel(): string
    {
        return __('AI সাপোর্ট KB');
    }

    public function getTitle(): string|Htmlable
    {
        return __('AI সাপোর্ট KB ও রাউটিং');
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    #[Computed]
    public function catalog(): KbCatalog
    {
        return app(KbCatalog::class);
    }

    #[Computed]
    public function files(): array
    {
        return $this->catalog->files();
    }

    #[Computed]
    public function coverage(): array
    {
        return $this->catalog->coverage();
    }

    #[Computed]
    public function edges(): array
    {
        return $this->catalog->edges();
    }

    #[Computed]
    public function overlaps(): array
    {
        return $this->catalog->keywordOverlaps();
    }

    #[Computed]
    public function overrides(): array
    {
        return $this->catalog->overrides();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['files', 'routes', 'test'], true) ? $tab : 'files';
    }

    public function openFile(string $id): void
    {
        $this->fileId = isset($this->files[$id]) ? $id : null;
        $this->tab = 'files';
    }

    public function closeFile(): void
    {
        $this->fileId = null;
    }

    public function addRoute(): void
    {
        $this->validate([
            'newFrom' => ['required', 'string'],
            'newTo' => ['required', 'string', 'different:newFrom'],
            'newWhen' => ['nullable', 'string', 'max:300'],
        ]);
        $files = $this->files;
        if (! isset($files[$this->newFrom], $files[$this->newTo])) {
            Notification::make()->danger()->title(__('KB ID পাওয়া যায়নি'))->send();

            return;
        }
        KbRouteOverride::updateOrCreate(
            ['from_id' => $this->newFrom, 'to_id' => $this->newTo],
            ['action' => 'add', 'when' => trim($this->newWhen) ?: null, 'active' => true, 'created_by' => $this->by()],
        );
        $this->newWhen = '';
        $this->done(__('লিংক যোগ হয়েছে'));
    }

    /** Switch a link on or off. A repo link gets a 'disable' row; a panel link flips its own 'add' row. */
    public function toggleEdge(string $from, string $to): void
    {
        $row = KbRouteOverride::where(['from_id' => $from, 'to_id' => $to])->first();
        if ($row) {
            $row->update(['active' => ! $row->active]);
        } else {
            KbRouteOverride::create(['from_id' => $from, 'to_id' => $to, 'action' => 'disable', 'active' => true, 'created_by' => $this->by()]);
        }
        $this->done(__('বদলানো হয়েছে'));
    }

    /** Remove a panel-added link entirely (repo links are only switched off, never deleted from here). */
    public function deleteRoute(string $from, string $to): void
    {
        KbRouteOverride::where(['from_id' => $from, 'to_id' => $to, 'action' => 'add'])->delete();
        $this->done(__('লিংক মুছে ফেলা হয়েছে'));
    }

    public function addKeyword(): void
    {
        $this->validate([
            'kwKb' => ['required', 'string'],
            'kwText' => ['required', 'string', 'max:120'],
        ]);
        if (! isset($this->files[$this->kwKb])) {
            Notification::make()->danger()->title(__('KB ID পাওয়া যায়নি'))->send();

            return;
        }
        KbKeywordOverride::updateOrCreate(
            ['kb_id' => $this->kwKb, 'keyword' => mb_strtolower(trim($this->kwText))],
            ['active' => true, 'created_by' => $this->by()],
        );
        $this->kwText = '';
        $this->done(__('কীওয়ার্ড যোগ হয়েছে'));
    }

    public function toggleKeyword(int $id): void
    {
        $row = KbKeywordOverride::find($id);
        $row?->update(['active' => ! $row->active]);
        $this->done(__('বদলানো হয়েছে'));
    }

    public function runTest(): void
    {
        $this->validate(['testQ' => ['required', 'string', 'max:500']]);
        $this->testResult = $this->catalog->testQuestion($this->testQ);
    }

    /** Lines that the repo still has to take over (every panel change, until a PR moves it into docs/kb). */
    public function pendingPanel(): array
    {
        return array_values(array_filter($this->overrides['routes'], fn ($r) => $r['active']));
    }

    private function by(): string
    {
        return (string) (auth()->user()?->name ?? 'super');
    }

    private function done(string $title): void
    {
        Notification::make()->success()->title($title)->send();
    }
}
