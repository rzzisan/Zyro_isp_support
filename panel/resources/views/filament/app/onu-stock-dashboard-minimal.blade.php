<x-filament-panels::page>
    @php
        $r = $this->report();
        $x = $this->insights($r);
        $n = fn ($v) => number_format((int) $v);
        $staff = $r['staff']->take(8);
        $staffMax = max(1, (int) $staff->max('onu'), (int) $staff->max('conn'));
        $purposeTotal = max(1, (int) $r['purposes']->sum());
        $palette = ['var(--z-accent)', '#f59e0b', '#6366f1', '#ec4899', '#94a3b8'];
        $kpis = [
            ['label' => __('ONU স্টকে'), 'value' => $r['onu_left'], 'money' => false, 'icon' => 'heroicon-o-cube',
                'sub' => '৳'.number_format($r['onu_value']).' '.__('দামের'), 'change' => null],
            ['label' => __('ONU দেওয়া'), 'value' => $r['onu_out_p'], 'money' => false, 'icon' => 'heroicon-o-signal',
                'sub' => __('কেনা :n', ['n' => $n($r['onu_in_p'])]), 'change' => $x['given_change']],
            ['label' => __('নতুন সংযোগ'), 'value' => $r['conn']->n, 'money' => false, 'icon' => 'heroicon-o-user-plus',
                'sub' => '৳'.number_format($r['conn']->total), 'change' => $x['conn_change']],
            ['label' => __('মোট বাকি'), 'value' => $r['due_all']->due, 'money' => true, 'icon' => 'heroicon-o-banknotes',
                'sub' => __(':n জনের কাছে', ['n' => $n($r['due_all']->n)]), 'change' => null],
        ];
        $every = max(1, (int) ceil(count($x['buckets']) / 8));
    @endphp
    <style>
        .zd { display: flex; flex-direction: column; gap: 20px; color: var(--z-fg); }
        .zd-top { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 12px; }
        .zd-seg { display: inline-flex; padding: 4px; border-radius: 12px; background: color-mix(in srgb, var(--z-fg) 6%, transparent); }
        .zd-seg button { padding: 6px 14px; border-radius: 9px; font-size: .85rem; color: var(--z-muted); transition: all .2s; }
        .zd-seg button.on { background: var(--z-surface); color: var(--z-fg); box-shadow: 0 1px 3px rgba(0,0,0,.12); font-weight: 600; }
        .zd-live { font-size: .78rem; color: var(--z-muted); display: flex; align-items: center; gap: 6px; }
        .zd-live i { width: 7px; height: 7px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 0 rgba(34,197,94,.6); animation: zd-pulse 2s infinite; }
        @keyframes zd-pulse { 70% { box-shadow: 0 0 0 8px rgba(34,197,94,0); } 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0); } }
        .zd-card { background: var(--z-surface); border: 1px solid var(--z-border); border-radius: 18px; padding: 18px 20px; }
        .zd-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
        .zd-kpi { position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s; }
        .zd-kpi:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -12px rgba(0,0,0,.25); }
        .zd-kpi-head { display: flex; justify-content: space-between; align-items: center; }
        .zd-kpi-label { font-size: .82rem; color: var(--z-muted); }
        .zd-ic { width: 34px; height: 34px; border-radius: 10px; display: grid; place-items: center;
                 background: color-mix(in srgb, var(--z-accent) 14%, transparent); color: var(--z-accent); }
        .zd-ic svg { width: 18px; height: 18px; }
        .zd-big { font-size: 2.1rem; font-weight: 700; letter-spacing: -.02em; margin-top: 10px; line-height: 1; font-variant-numeric: tabular-nums; }
        .zd-sub { font-size: .8rem; color: var(--z-muted); margin-top: 8px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .zd-chg { font-size: .72rem; font-weight: 600; padding: 2px 7px; border-radius: 999px; }
        .zd-up { color: #16a34a; background: rgba(22,163,74,.12); }
        .zd-down { color: #dc2626; background: rgba(220,38,38,.12); }
        .zd-h { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 14px; gap: 8px; }
        .zd-h h3 { font-weight: 600; font-size: 1rem; }
        .zd-h small, .zd-h a { font-size: .8rem; color: var(--z-muted); }
        .zd-h a:hover { color: var(--z-accent); }
        .zd-legend { display: flex; gap: 14px; font-size: .78rem; color: var(--z-muted); }
        .zd-legend span::before { content: ''; display: inline-block; width: 9px; height: 9px; border-radius: 3px; margin-right: 5px; background: var(--c); }
        .zd-chart { display: flex; align-items: flex-end; justify-content: space-around; gap: 3px; height: 150px; }
        .zd-col { flex: 1; max-width: 56px; display: flex; gap: 2px; align-items: flex-end; height: 100%; min-width: 0; position: relative; }
        .zd-col div { flex: 1; border-radius: 4px 4px 1px 1px; min-height: 2px; transform-origin: bottom; animation: zd-grow .9s cubic-bezier(.2,.8,.2,1) both; }
        @keyframes zd-grow { from { transform: scaleY(0); } }
        @keyframes zd-fill { from { transform: scaleX(0); } }
        .zd-col:hover div { filter: brightness(1.15); }
        .zd-col .g { background: var(--z-accent); }
        .zd-col .c { background: color-mix(in srgb, #f59e0b 80%, transparent); }
        .zd-x { display: flex; justify-content: space-around; gap: 3px; margin-top: 6px; }
        .zd-x span { flex: 1; max-width: 56px; font-size: .68rem; color: var(--z-muted); text-align: center; white-space: nowrap; overflow: visible; min-width: 0; }
        .zd-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
        .zd-tile { border: 1px solid var(--z-border); border-radius: 14px; padding: 14px; transition: transform .2s, border-color .2s; }
        .zd-tile:hover { transform: translateY(-2px); border-color: color-mix(in srgb, var(--z-accent) 50%, var(--z-border)); }
        .zd-tile-top { display: flex; justify-content: space-between; gap: 6px; align-items: start; }
        .zd-tile-name { font-size: .85rem; font-weight: 500; line-height: 1.3; }
        .zd-chip { font-size: .66rem; font-weight: 600; padding: 2px 7px; border-radius: 999px; color: var(--z-accent);
                   background: color-mix(in srgb, var(--z-accent) 12%, transparent); flex: none; }
        .zd-qty { font-size: 1.7rem; font-weight: 700; margin: 6px 0 10px; font-variant-numeric: tabular-nums; }
        .zd-bar { height: 6px; border-radius: 999px; background: color-mix(in srgb, var(--z-fg) 8%, transparent); overflow: hidden; }
        .zd-bar div { height: 100%; border-radius: 999px; transform-origin: left; animation: zd-fill 1.1s cubic-bezier(.2,.8,.2,1) both; }
        .zd-cov { font-size: .74rem; color: var(--z-muted); margin-top: 6px; }
        .zd-two { display: grid; grid-template-columns: 1.4fr 1fr; gap: 14px; }
        @media (max-width: 1000px) { .zd-two { grid-template-columns: 1fr; } }
        .zd-rank { display: grid; grid-template-columns: 22px 1fr auto; gap: 10px; align-items: center; padding: 9px 0; }
        .zd-rank + .zd-rank { border-top: 1px solid var(--z-border); }
        .zd-rank b { font-size: .75rem; color: var(--z-muted); font-weight: 600; }
        .zd-rank .nm { font-size: .88rem; font-weight: 500; }
        .zd-rank .mini { height: 5px; border-radius: 999px; margin-top: 5px; background: color-mix(in srgb, var(--z-fg) 7%, transparent); overflow: hidden; display: flex; }
        .zd-rank .mini div { height: 100%; transform-origin: left; animation: zd-fill 1.1s cubic-bezier(.2,.8,.2,1) both; }
        .zd-rank .vals { text-align: right; font-size: .8rem; color: var(--z-muted); white-space: nowrap; }
        .zd-rank .vals strong { color: var(--z-fg); font-size: .9rem; }
        .zd-due { color: #dc2626; font-weight: 600; }
        .zd-stack { display: flex; height: 12px; border-radius: 999px; overflow: hidden; gap: 2px; margin-bottom: 12px; }
        .zd-stack div { transform-origin: left; animation: zd-fill 1.1s cubic-bezier(.2,.8,.2,1) both; }
        .zd-plist div { display: flex; justify-content: space-between; font-size: .84rem; padding: 6px 0; }
        .zd-plist span::before { content: ''; display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 8px; background: var(--c); }
        .zd-empty { font-size: .85rem; color: var(--z-muted); padding: 10px 0; }
        .zd-note { font-size: .75rem; color: var(--z-muted); }
    </style>

    <div class="zd" wire:poll.60s>
        <div class="zd-top">
            <div class="zd-seg">
                @foreach (\App\Filament\App\Pages\OnuStockDashboard::PERIODS as $p => $label)
                    <button type="button" wire:click="setPeriod('{{ $p }}')" class="{{ $this->period === $p ? 'on' : '' }}">{{ __($label) }}</button>
                @endforeach
            </div>
            <div class="zd-live"><i></i> {{ __('লাইভ') }} · {{ now('Asia/Dhaka')->format('h:i A') }} · <a href="?" class="underline">{{ __('আগের ডিজাইন') }}</a></div>
        </div>

        <div class="zd-kpis">
            @foreach ($kpis as $k)
                <div class="zd-card zd-kpi">
                    <div class="zd-kpi-head"><span class="zd-kpi-label">{{ $k['label'] }}</span>
                        <span class="zd-ic"><x-filament::icon :icon="$k['icon']" /></span></div>
                    <div class="zd-big" x-data="{ v: 0, t: {{ (float) $k['value'] }} }"
                         x-init="let s = performance.now(); const f = (n) => { const p = Math.min(1, (n - s) / 900); v = t * (1 - Math.pow(1 - p, 3)); if (p < 1) requestAnimationFrame(f) }; requestAnimationFrame(f)"
                         x-text="'{{ $k['money'] ? '৳' : '' }}' + Math.round(v).toLocaleString('en-US')">{{ $k['money'] ? '৳' : '' }}{{ number_format((float) $k['value']) }}</div>
                    <div class="zd-sub">
                        @if ($k['change'] !== null)
                            <span class="zd-chg {{ $k['change'] >= 0 ? 'zd-up' : 'zd-down' }}">{{ $k['change'] >= 0 ? '▲' : '▼' }} {{ abs($k['change']) }}%</span>
                        @endif
                        <span>{{ $k['sub'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="zd-card">
            <div class="zd-h"><h3>{{ __('কাজের ধারা') }}</h3>
                <div class="zd-legend"><span style="--c: var(--z-accent)">{{ __('ONU দেওয়া') }}</span><span style="--c: #f59e0b">{{ __('নতুন সংযোগ') }}</span></div></div>
            @if (array_sum(array_column($x['buckets'], 'given')) + array_sum(array_column($x['buckets'], 'conn')) === 0)
                <div class="zd-empty">{{ __('এই সময়ে কোনো কাজ নেই') }}</div>
            @else
                <div class="zd-chart">
                    @foreach ($x['buckets'] as $b)
                        <div class="zd-col" title="{{ $b['label'] }} · ONU {{ $b['given'] }} · {{ __('সংযোগ') }} {{ $b['conn'] }}">
                            <div class="g" style="height: {{ round($b['given'] * 100 / $x['max_bucket'], 1) }}%"></div>
                            <div class="c" style="height: {{ round($b['conn'] * 100 / $x['max_bucket'], 1) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="zd-x">
                    @foreach ($x['buckets'] as $i => $b)
                        <span>{{ $i % $every === 0 ? $b['label'] : '' }}</span>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="zd-card">
            <div class="zd-h"><h3>{{ __('স্টকে যা আছে') }} <small>· {{ __('আগে যেটা শেষ হবে সেটা আগে') }}</small></h3>
                <a href="{{ \App\Filament\App\Resources\StockProducts\StockProductResource::getUrl() }}">{{ __('স্টক ইন / বিতরণ') }} →</a></div>
            @if ($x['lines']->isEmpty())
                <div class="zd-empty">{{ __('এখন কোনো কিছুর স্টক নেই') }}</div>
            @else
                <div class="zd-tiles">
                    @foreach ($x['lines'] as $l)
                        @php
                            $color = $l['days'] === null ? '#94a3b8' : ($l['days'] < 15 ? '#ef4444' : ($l['days'] < 45 ? '#f59e0b' : '#22c55e'));
                            $w = $l['days'] === null ? 0 : min(100, max(4, round($l['days'] * 100 / 90)));
                        @endphp
                        <div class="zd-tile">
                            <div class="zd-tile-top"><span class="zd-tile-name">{{ $l['name'] }}</span>@if ($l['onu'])<span class="zd-chip">ONU</span>@endif</div>
                            <div class="zd-qty">{{ $n($l['qty']) }}</div>
                            <div class="zd-bar"><div style="width: {{ $w }}%; background: {{ $color }}"></div></div>
                            <div class="zd-cov">
                                @if ($l['days'] === null)
                                    {{ __('গত ৩০ দিনে ব্যবহার হয়নি') }}
                                @else
                                    {{ __('~:d দিন চলবে', ['d' => $n($l['days'])]) }} · {{ __('৩০ দিনে :u টা', ['u' => $n($l['used30'])]) }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($r['items_out'] || $r['low_brands'])
                <div class="zd-note" style="margin-top: 12px">{{ __('শেষ হয়ে গেছে: :n টা', ['n' => $r['items_out'] + $r['low_brands']]) }}</div>
            @endif
        </div>

        <div class="zd-two">
            <div class="zd-card">
                <div class="zd-h"><h3>{{ __('কর্মী') }}</h3><small>{{ __('ONU · সংযোগ · বাকি') }}</small></div>
                @forelse ($staff as $i => $s)
                    <div class="zd-rank">
                        <b>{{ $i + 1 }}</b>
                        <div><div class="nm">{{ $s['name'] }}</div>
                            <div class="mini">
                                <div style="width: {{ round($s['onu'] * 50 / $staffMax, 1) }}%; background: var(--z-accent)"></div>
                                <div style="width: {{ round($s['conn'] * 50 / $staffMax, 1) }}%; background: #f59e0b"></div>
                            </div></div>
                        <div class="vals"><strong>{{ $n($s['onu']) }}</strong> ONU · <strong>{{ $n($s['conn']) }}</strong> {{ __('সংযোগ') }}
                            @if ($s['due'] > 0)<div class="zd-due">৳{{ number_format($s['due']) }}</div>@endif</div>
                    </div>
                @empty
                    <div class="zd-empty">—</div>
                @endforelse
            </div>

            <div class="zd-card">
                <div class="zd-h"><h3>{{ __('ONU দেওয়ার কারণ') }}</h3></div>
                @if ($r['purposes']->isEmpty())
                    <div class="zd-empty">{{ __('এই সময়ে কোনো ONU দেওয়া হয়নি') }}</div>
                @else
                    <div class="zd-stack">
                        @foreach ($r['purposes']->values() as $i => $v)
                            <div style="width: {{ round($v * 100 / $purposeTotal, 1) }}%; background: {{ $palette[$i % 5] }}"></div>
                        @endforeach
                    </div>
                    <div class="zd-plist">
                        @foreach ($r['purposes'] as $k => $v)
                            <div><span style="--c: {{ $palette[$loop->index % 5] }}">{{ $k }}</span><strong>{{ $n($v) }}</strong></div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
