<?php

namespace App\Filament\App\Widgets;

use App\Services\TicketInsights;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Tickets opened vs solved per day. */
class TicketTrendChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'টিকিট: প্রতিদিন খোলা বনাম সমাধান';

    protected ?string $pollingInterval = '120s';

    protected ?string $maxHeight = '280px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['7' => 'শেষ ৭ দিন', '30' => 'শেষ ৩০ দিন', '90' => 'শেষ ৯০ দিন'];
    }

    protected function getData(): array
    {
        $d = (new TicketInsights(Filament::getTenant()->getKey()))->daily((int) ($this->filter ?: 30));

        return [
            'labels' => $d['labels'],
            'datasets' => [
                ['label' => 'খোলা হয়েছে', 'data' => $d['opened'], 'backgroundColor' => '#f59e0b', 'borderRadius' => 4],
                ['label' => 'সমাধান হয়েছে', 'data' => $d['solved'], 'backgroundColor' => '#0f7c7b', 'borderRadius' => 4],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
