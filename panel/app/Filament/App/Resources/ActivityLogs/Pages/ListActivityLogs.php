<?php

namespace App\Filament\App\Resources\ActivityLogs\Pages;

use App\Filament\App\Resources\ActivityLogs\ActivityLogResource;
use App\Models\ActivityLog;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListActivityLogs extends ListRecords
{
    protected static string $resource = ActivityLogResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('কাজের লগ');
    }

    public function getSubheading(): ?string
    {
        return __('Desk-এ কে কখন কী বদলেছেন: প্যানেল, অ্যাপ, বট আর timer। লগ বদলানো বা মোছা যায় না।');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label(__('CSV ডাউনলোড'))->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn () => $this->csv()),
        ];
    }

    /** The rows the current filters show, newest first. */
    public function csv(): StreamedResponse
    {
        $query = $this->getFilteredTableQuery()->reorder('created_at', 'desc');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['time', 'user', 'actor', 'source', 'action', 'subject', 'customer', 'changes', 'note', 'ip']);
            $query->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $r) {
                    /** @var ActivityLog $r */
                    fputcsv($out, [$r->created_at?->timezone('Asia/Dhaka')->format('Y-m-d H:i:s'), $r->user?->name, $r->actor, $r->source,
                        $r->action, trim($r->subject_type.' '.$r->subject_id), $r->customer_code,
                        ActivityLogResource::changesText($r->changes), $r->note, $r->ip]);
                }
            });
            fclose($out);
        }, 'activity-log-'.now('Asia/Dhaka')->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
