<?php

namespace App\Filament\App\Widgets;

use App\Services\TicketInsights;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Where the tickets are: by zone, by problem, open tickets by zone, or who solved most (30 days). */
class TicketBreakdownChart extends ChartWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'টিকিটের ভাগ';

    protected ?string $pollingInterval = '120s';

    protected ?string $maxHeight = '300px';

    public ?string $filter = 'open_zone';

    protected function getFilters(): ?array
    {
        return [
            'open_zone' => 'খোলা টিকিট: Zone অনুযায়ী',
            'zone' => '৩০ দিন: Zone অনুযায়ী',
            'category' => '৩০ দিন: সমস্যার ধরন',
            'solved_by' => '৩০ দিন: কে বেশি সমাধান করেছে',
        ];
    }

    protected function getData(): array
    {
        $t = new TicketInsights(Filament::getTenant()->getKey());
        $from = CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays(29)->utc();
        $rows = match ($this->filter) {
            'zone' => $t->top('zone', $from, limit: 10),
            'category' => $t->top('category', $from, limit: 10),
            'solved_by' => $t->top('solved_by', $from, limit: 10),
            default => $t->top('zone', $from, openOnly: true, limit: 10),
        };

        return [
            'labels' => array_keys($rows),
            'datasets' => [[
                'label' => 'টিকিট',
                'data' => array_values($rows),
                'backgroundColor' => $this->filter === 'open_zone' ? '#f59e0b' : '#0f7c7b',
                'borderRadius' => 6,
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
