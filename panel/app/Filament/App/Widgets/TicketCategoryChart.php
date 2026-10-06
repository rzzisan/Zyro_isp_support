<?php

namespace App\Filament\App\Widgets;

use App\Services\Insights;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** What customers report most (the bot's ticket categories, last 30 days). */
class TicketCategoryChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'সমস্যার ধরন (৩০ দিন)';

    protected ?string $pollingInterval = '60s';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $cats = (new Insights(Filament::getTenant()->getKey()))
            ->ticketCategories(CarbonImmutable::now(Insights::TZ)->startOfDay()->subDays(29)->utc());

        return [
            'labels' => array_keys($cats),
            'datasets' => [[
                'label' => 'টিকিট',
                'data' => array_values($cats),
                'backgroundColor' => '#0f7c7b',
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
