<?php

namespace App\Filament\App\Widgets;

use App\Services\Insights;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Who answered customers in the last 7 days. */
class ReplyShareChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'কে উত্তর দিয়েছে (৭ দিন)';

    protected ?string $pollingInterval = '30s';

    protected ?string $maxHeight = '260px';

    protected function getData(): array
    {
        $c = (new Insights(Filament::getTenant()->getKey()))
            ->messageCounts(CarbonImmutable::now(Insights::TZ)->startOfDay()->subDays(6)->utc());

        return [
            'labels' => ['বট', 'এজেন্ট', 'ক্যাম্পেইন'],
            'datasets' => [[
                'data' => [$c['bot'], $c['staff'], $c['campaign']],
                'backgroundColor' => ['#f59e0b', '#6366f1', '#0f7c7b'],
                'borderWidth' => 0,
            ]],
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'cutout' => '62%',
        ];
    }
}
