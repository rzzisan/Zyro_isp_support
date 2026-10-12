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

        return (clone $q)->where('active', true)->count().'টা · বিলিং থেকে কপি করে ডেস্কে রাখা, নিজে থেকে বদলায় না; বিলিংয়ে বদলালে "Sync" চাপুন'
            .($last ? ' · শেষ Sync '.\Illuminate\Support\Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A') : ' · এখনো Sync হয়নি');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label('Sync')->icon(Heroicon::OutlinedArrowPath)
                ->action(fn () => $this->sync($this->kind())),
            Action::make('syncAll')->label('সব তালিকা Sync')->icon(Heroicon::OutlinedArrowPathRoundedSquare)->color('gray')
                ->requiresConfirmation()->modalDescription('বিলিং থেকে ১৪টা তালিকাই (Zone, Box, Package, কর্মী, ...) নতুন করে আনা হবে।')
                ->action(fn () => $this->sync(null)),
        ];
    }

    private function sync(?string $kind): void
    {
        try {
            $r = Engine::syncBillingConfig(Filament::getTenant()->getKey(), $kind);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Sync হয়নি')->body($e->getMessage())->send();

            return;
        }
        $lines = [];
        foreach ($r as $k => $x) {
            $lines[] = (BillingOption::KINDS[$k] ?? $k).': '.($x['total'] ?? 0).'টা'
                .(($x['inactive'] ?? 0) ? ', বিলিংয়ে আর নেই '.$x['inactive'].'টা' : '')
                .(($x['kept'] ?? false) ? ' (বিলিং খালি দিয়েছে, আগেরটা রাখা হলো)' : '');
        }
        Notification::make()->success()->title('Sync হয়েছে')->body(implode(' · ', $lines))->send();
    }
}
