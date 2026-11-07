<x-filament-panels::page>
    @php
        $r = $this->report();
        $n = fn ($v) => number_format((int) $v);
        $tk = fn ($v) => '৳'.number_format((float) $v);
        $inStock = collect([
            ...$r['brands']->where('left', '>', 0)->map(fn ($b) => ['name' => $b['name'], 'qty' => $b['left'], 'onu' => true]),
            ...$r['products']->where('stock', '>', 0)->sortByDesc('stock')->map(fn ($p) => ['name' => $p['name'], 'qty' => $p['stock'], 'onu' => false]),
        ]);
    @endphp
    <style>
        .zm { display: flex; flex-direction: column; gap: 36px; color: var(--z-fg); }
        .zm-tabs { display: flex; gap: 20px; font-size: .85rem; }
        .zm-tabs button { color: var(--z-muted); padding-bottom: 4px; border-bottom: 2px solid transparent; }
        .zm-tabs button.on { color: var(--z-fg); border-color: var(--z-fg); }
        .zm-label { font-size: .75rem; letter-spacing: .04em; text-transform: uppercase; color: var(--z-muted); }
        .zm-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 24px; }
        .zm-kpi .zm-v { font-size: 2rem; font-weight: 600; line-height: 1.1; margin-top: 6px; }
        .zm-kpi .zm-s { font-size: .8rem; color: var(--z-muted); margin-top: 4px; }
        .zm h2 { font-size: .95rem; font-weight: 600; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: baseline; }
        .zm h2 a { font-size: .8rem; font-weight: 400; color: var(--z-muted); }
        .zm-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 1px; background: var(--z-border);
                    border: 1px solid var(--z-border); border-radius: 10px; overflow: hidden; }
        .zm-tile { background: var(--z-surface); padding: 14px 16px; }
        .zm-tile .zm-q { font-size: 1.5rem; font-weight: 600; }
        .zm-tile .zm-n { font-size: .82rem; color: var(--z-muted); margin-top: 2px; }
        .zm-tile .zm-n i { font-style: normal; font-size: .7rem; margin-left: 4px; opacity: .8; }
        .zm-t { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .zm-t th { text-align: left; font-weight: 500; color: var(--z-muted); padding: 0 8px 8px 0; font-size: .75rem; }
        .zm-t td { padding: 9px 8px 9px 0; border-top: 1px solid var(--z-border); }
        .zm-t .num { text-align: right; }
        .zm-wrap { overflow-x: auto; }
        .zm-muted { color: var(--z-muted); }
        .zm-line { font-size: .85rem; color: var(--z-muted); line-height: 1.8; }
        .zm-line b { color: var(--z-fg); font-weight: 600; }
        .zm-preview { font-size: .8rem; color: var(--z-muted); }
    </style>

    <div class="zm">
        <div class="zm-preview">{{ __('নতুন ডিজাইনের preview') }} · <a href="?" class="underline">{{ __('আগের ডিজাইন') }}</a></div>

        <div class="zm-tabs">
            @foreach (\App\Filament\App\Pages\OnuStockDashboard::PERIODS as $p => $label)
                <button type="button" wire:click="setPeriod('{{ $p }}')" class="{{ $this->period === $p ? 'on' : '' }}">{{ __($label) }}</button>
            @endforeach
        </div>

        <div class="zm-kpis">
            <div class="zm-kpi"><div class="zm-label">{{ __('ONU স্টকে') }}</div><div class="zm-v">{{ $n($r['onu_left']) }}</div>
                <div class="zm-s">{{ $tk($r['onu_value']) }}</div></div>
            <div class="zm-kpi"><div class="zm-label">{{ __('ONU দেওয়া') }}</div><div class="zm-v">{{ $n($r['onu_out_p']) }}</div>
                <div class="zm-s">{{ __('কেনা :n', ['n' => $n($r['onu_in_p'])]) }}</div></div>
            <div class="zm-kpi"><div class="zm-label">{{ __('নতুন সংযোগ') }}</div><div class="zm-v">{{ $n($r['conn']->n) }}</div>
                <div class="zm-s">{{ $tk($r['conn']->total) }}</div></div>
            <div class="zm-kpi"><div class="zm-label">{{ __('মোট বাকি') }}</div><div class="zm-v">{{ $tk($r['due_all']->due) }}</div>
                <div class="zm-s">{{ __(':n জন', ['n' => $n($r['due_all']->n)]) }}</div></div>
        </div>

        <section>
            <h2>{{ __('স্টকে যা আছে') }} <a href="{{ \App\Filament\App\Resources\StockProducts\StockProductResource::getUrl() }}">{{ __('স্টক ইন / বিতরণ') }} →</a></h2>
            @if ($inStock->isEmpty())
                <div class="zm-muted">{{ __('এখন কোনো কিছুর স্টক নেই') }}</div>
            @else
                <div class="zm-tiles">
                    @foreach ($inStock as $s)
                        <div class="zm-tile"><div class="zm-q">{{ $n($s['qty']) }}</div>
                            <div class="zm-n">{{ $s['name'] }}@if ($s['onu'])<i>ONU</i>@endif</div></div>
                    @endforeach
                </div>
            @endif
            @if ($r['items_out'] || $r['low_brands'])
                <div class="zm-line" style="margin-top:8px">{{ __('শেষ হয়ে গেছে: :n টা', ['n' => $r['items_out'] + $r['low_brands']]) }}</div>
            @endif
        </section>

        <section class="zm-wrap">
            <h2>{{ __('ONU ব্র্যান্ড') }}</h2>
            <table class="zm-t">
                <tr><th>{{ __('ব্র্যান্ড') }}</th><th class="num">{{ __('স্টকে') }}</th><th class="num">{{ __('এই সময়ে দেওয়া') }}</th>
                    <th class="num">{{ __('মোট দেওয়া') }}</th><th class="num">{{ __('দাম') }}</th></tr>
                @foreach ($r['brands'] as $b)
                    <tr class="{{ $b['left'] > 0 ? '' : 'zm-muted' }}">
                        <td>{{ $b['name'] }}</td><td class="num">{{ $n($b['left']) }}</td><td class="num">{{ $n($b['out_p']) }}</td>
                        <td class="num">{{ $n($b['out']) }}</td><td class="num">{{ number_format($b['price']) }}</td>
                    </tr>
                @endforeach
            </table>
        </section>

        <section class="zm-wrap">
            <h2>{{ __('কর্মী') }}</h2>
            <table class="zm-t">
                <tr><th>{{ __('নাম') }}</th><th class="num">ONU</th><th class="num">{{ __('মাল') }}</th><th class="num">{{ __('সংযোগ') }}</th><th class="num">{{ __('বাকি') }}</th></tr>
                @forelse ($r['staff'] as $s)
                    <tr><td>{{ $s['name'] }}</td><td class="num">{{ $n($s['onu']) }}</td><td class="num">{{ $n($s['items']) }}</td>
                        <td class="num">{{ $n($s['conn']) }}</td><td class="num">{{ $s['due'] ? $tk($s['due']) : '—' }}</td></tr>
                @empty
                    <tr><td colspan="5" class="zm-muted">—</td></tr>
                @endforelse
            </table>
        </section>

        @if ($r['purposes']->isNotEmpty())
            <div class="zm-line">{{ __('ONU দেওয়ার কারণ') }}:
                @foreach ($r['purposes'] as $k => $v)<b>{{ $k }}</b> {{ $n($v) }}@if (! $loop->last) · @endif @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
