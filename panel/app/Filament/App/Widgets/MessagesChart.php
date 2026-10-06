<?php

namespace App\Filament\App\Widgets;

use App\Services\Insights;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/** Messages over time: customers vs bot vs staff. */
class MessagesChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'মেসেজের ধারা';

    protected ?string $pollingInterval = '15s';

    protected ?string $maxHeight = '280px';

    public ?string $filter = '24h';

    protected function getFilters(): ?array
    {
        return ['24h' => 'শেষ ২৪ ঘণ্টা', '7d' => 'শেষ ৭ দিন', '30d' => 'শেষ ৩০ দিন'];
    }

    protected function getData(): array
    {
        $in = new Insights(Filament::getTenant()->getKey());
        $s = match ($this->filter) {
            '7d' => $in->series('day', 7),
            '30d' => $in->series('day', 30),
            default => $in->series('hour', 24),
        };
        $line = fn (string $label, array $data, string $color) => [
            'label' => $label, 'data' => $data, 'borderColor' => $color, 'backgroundColor' => $color.'22',
            'fill' => true, 'tension' => 0.35, 'pointRadius' => 2,
        ];

        return [
            'labels' => $s['labels'],
            'datasets' => [
                $line('কাস্টমার', $s['customer'], '#0f7c7b'),
                $line('বট', $s['bot'], '#f59e0b'),
                $line('এজেন্ট', $s['staff'], '#6366f1'),
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
        ];
    }
}
