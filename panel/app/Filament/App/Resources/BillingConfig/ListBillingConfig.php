<?php

namespace App\Filament\App\Resources\BillingConfig;

use App\Models\BillingOption;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/** List page of one billing list, with Sync buttons (this list / every list). */
abstract class ListBillingConfig extends ListRecords
{
    private function kind(): string
    {
        return static::getResource()::kind();
    }

    public function getSubheading(): ?string
    {
        $q = BillingOption::where('company_id', Filament::getTenant()->getKey())->where('kind', $this->kind());
        $last = (clone $q)->max('synced_at');

        return __(':nটা · বিলিং থেকে কপি করে ডেস্কে রাখা, নিজে থেকে বদলায় না; বিলিংয়ে বদলালে "Sync" চাপুন', ['n' => (clone $q)->where('active', true)->count()])
            .' · '.($last ? __('শেষ Sync :time', ['time' => \Illuminate\Support\Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A')]) : __('এখনো Sync হয়নি'));
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label('Sync')->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => $this->sync($this->kind())),
            Action::make('syncAll')->label(__('সব তালিকা Sync'))->icon(Heroicon::OutlinedArrowPathRoundedSquare)->color('gray')
                ->requiresConfirmation()->modalDescription(__('বিলিং থেকে ১৪টা তালিকাই (Zone, Box, Package, কর্মী, ...) নতুন করে আনা হবে।'))
                ->action(fn () => $this->sync(null)),
        ];
    }

    private function sync(?string $kind): void
    {
        try {
            $r = Engine::syncBillingConfig(Filament::getTenant()->getKey(), $kind);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title(__('Sync হয়নি'))->body($e->getMessage())->send();

            return;
        }
        $lines = [];
        foreach ($r as $k => $x) {
            $lines[] = __(BillingOption::KINDS[$k] ?? $k).': '.__(':nটা', ['n' => $x['total'] ?? 0])
                .(($x['inactive'] ?? 0) ? ', '.__('বিলিংয়ে আর নেই :nটা', ['n' => $x['inactive']]) : '')
                .(($x['kept'] ?? false) ? __(' (বিলিং খালি দিয়েছে, আগেরটা রাখা হলো)') : '');
        }
        Notification::make()->success()->title(__('Sync হয়েছে'))->body(implode(' · ', $lines))->send();
    }
}
