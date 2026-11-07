<x-filament-panels::page>
    @php
        $r = $this->report();
        $n = fn ($v) => number_format((int) $v);
        $tk = fn ($v) => number_format((float) $v).' '.__('টাকা');
        $url = fn (string $resource) => $resource::getUrl();
    @endphp
    <style>
        .zr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
        .zr-card { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; padding: 14px 16px; color: var(--z-fg); }
        .zr-card b { font-size: .85rem; color: var(--z-muted); font-weight: 600; }
        .zr-card .zr-big { font-size: 1.6rem; font-weight: 700; margin: 4px 0; }
        .zr-card .zr-sub { font-size: .8rem; color: var(--z-muted); line-height: 1.5; }
        .zr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 12px; }
        @media (max-width: 640px) { .zr-grid { grid-template-columns: 1fr; } }
        .zr-box { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; padding: 6px 0; overflow-x: auto; }
        .zr-box h3 { font-weight: 600; padding: 8px 16px; display: flex; justify-content: space-between; align-items: center; gap: 8px; }
        .zr-box h3 a { font-size: .8rem; font-weight: 500; color: var(--z-accent); }
        .zr-t { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .zr-t th { text-align: left; font-weight: 600; color: var(--z-muted); padding: 6px 12px; white-space: nowrap; }
        .zr-t td { padding: 7px 12px; border-top: 1px solid var(--z-border); white-space: nowrap; }
        .zr-t td.num, .zr-t th.num { text-align: right; }
        .zr-t tr.zr-sum td { font-weight: 700; }
        .zr-tabs { display: flex; flex-wrap: wrap; gap: 8px; }
        .zr-tabs button { border: 1px solid var(--z-border); border-radius: 999px; padding: 4px 14px; font-size: .85rem; }
        .zr-tabs button.on { border-color: var(--z-accent); background: color-mix(in srgb, var(--z-accent) 15%, transparent); }
        .zr-note { font-size: .8rem; color: var(--z-muted); }
        .zr-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 4px 16px 12px; }
        .zr-chip { border: 1px solid var(--z-border); border-radius: 999px; padding: 3px 12px; font-size: .82rem; }
        .zr-bad { color: #dc2626; font-weight: 700; }
        .zr-ok { color: #16a34a; font-weight: 700; }
        .zr-stock { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; padding: 4px 16px 14px; }
        .zr-item { position: relative; border: 1px solid var(--z-border); border-left: 4px solid #16a34a; border-radius: 12px; padding: 10px 12px; }
        .zr-item-onu { border-left-color: var(--z-accent); }
        .zr-item-name { font-weight: 600; font-size: .9rem; padding-right: 44px; line-height: 1.3; }
        .zr-item-big { font-size: 1.7rem; font-weight: 700; margin: 2px 0; }
        .zr-item-big small { font-size: .8rem; font-weight: 500; color: var(--z-muted); margin-left: 4px; }
        .zr-tag { position: absolute; top: 8px; right: 8px; font-size: .7rem; border: 1px solid var(--z-border); border-radius: 999px; padding: 1px 8px; color: var(--z-muted); }
    </style>

    <div class="zr-tabs">
        @foreach (\App\Filament\App\Pages\OnuStockDashboard::PERIODS as $p => $label)
            <button type="button" wire:click="setPeriod('{{ $p }}')" class="{{ $this->period === $p ? 'on' : '' }}">{{ __($label) }}</button>
        @endforeach
    </div>

    <div class="zr-cards">
        <div class="zr-card"><b>{{ __('ONU স্টকে আছে') }}</b><div class="zr-big">{{ $n($r['onu_left']) }}</div>
            <div class="zr-sub">{{ __('দাম ধরে :v', ['v' => $tk($r['onu_value'])]) }}@if ($r['low_brands']) · {{ __(':n টা ব্র্যান্ড শেষ', ['n' => $r['low_brands']]) }}@endif</div></div>
        <div class="zr-card"><b>{{ __('ONU দেওয়া হয়েছে') }}</b><div class="zr-big">{{ $n($r['onu_out_p']) }}</div>
            <div class="zr-sub">{{ __('এই সময়ে কেনা :n টা', ['n' => $n($r['onu_in_p'])]) }}</div></div>
        <div class="zr-card"><b>{{ __('নতুন সংযোগ') }}</b><div class="zr-big">{{ $n($r['conn']->n) }}</div>
            <div class="zr-sub">{{ __('মোট :t · জমা :d', ['t' => $tk($r['conn']->total), 'd' => $tk($r['conn']->deposit)]) }}</div></div>
        <div class="zr-card"><b>{{ __('সংযোগের বাকি (সব সময়)') }}</b><div class="zr-big">{{ $tk($r['due_all']->due) }}</div>
            <div class="zr-sub">{{ __(':n জনের কাছে', ['n' => $n($r['due_all']->n)]) }} · {{ __('এই সময়ে আদায় :p', ['p' => $tk($r['paid']->paid)]) }}</div></div>
        <div class="zr-card"><b>{{ __('অন্যান্য মাল') }}</b><div class="zr-big">{{ $n($r['items_total']) }}</div>
            <div class="zr-sub">@if ($r['items_out']){{ __(':n টা শেষ হয়ে গেছে', ['n' => $r['items_out']]) }}@else{{ __('সবগুলো স্টকে আছে') }}@endif</div></div>
    </div>

    @php
        $onuIn = $r['brands']->where('left', '>', 0);
        $itemsIn = $r['products']->where('stock', '>', 0)->sortByDesc('stock');
    @endphp
    <div class="zr-box">
        <h3><span>{{ __('স্টকে যা আছে') }} <span class="zr-note">{{ __('শুধু যেগুলোর স্টক আছে') }}</span></span>
            <a href="{{ $url(\App\Filament\App\Resources\StockProducts\StockProductResource::class) }}">{{ __('স্টক ইন / বিতরণ') }} →</a></h3>
        @if ($onuIn->isEmpty() && $itemsIn->isEmpty())
            <div class="zr-chips"><span class="zr-note">{{ __('এখন কোনো কিছুর স্টক নেই') }}</span></div>
        @else
            <div class="zr-stock">
                @foreach ($onuIn as $b)
                    <div class="zr-item zr-item-onu">
                        <span class="zr-tag">ONU</span>
                        <div class="zr-item-name">{{ $b['name'] }}</div>
                        <div class="zr-item-big">{{ $n($b['left']) }}<small>{{ __('টা') }}</small></div>
                        <div class="zr-sub">{{ __('দাম :p · মোট :v', ['p' => number_format($b['price']), 'v' => $tk($b['value'])]) }}</div>
                        <div class="zr-sub">{{ __('এই সময়ে কেনা :i · দেওয়া :o', ['i' => $n($b['in_p']), 'o' => $n($b['out_p'])]) }}</div>
                    </div>
                @endforeach
                @foreach ($itemsIn as $p)
                    <div class="zr-item">
                        <span class="zr-tag">{{ __('মাল') }}</span>
                        <div class="zr-item-name">{{ $p['name'] }}</div>
                        <div class="zr-item-big">{{ $n($p['stock']) }}<small>{{ __('টা') }}</small></div>
                        <div class="zr-sub">{{ __('এই সময়ে ইন :i · বিতরণ :o', ['i' => $n($p['in_p']), 'o' => $n($p['out_p'])]) }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="zr-box">
        <h3>{{ __('ONU স্টক (ব্র্যান্ড অনুযায়ী)') }} <a href="{{ $url(\App\Filament\App\Resources\OnuStockEntries\OnuStockEntryResource::class) }}">{{ __('স্টক যোগ করুন') }} →</a></h3>
        <table class="zr-t">
            <tr><th>{{ __('ব্র্যান্ড') }}</th><th class="num">{{ __('দাম') }}</th><th class="num">{{ __('মোট কেনা') }}</th><th class="num">{{ __('মোট দেওয়া') }}</th>
                <th class="num">{{ __('স্টকে আছে') }}</th><th class="num">{{ __('স্টকের দাম') }}</th><th class="num">{{ __('এই সময়ে কেনা') }}</th><th class="num">{{ __('এই সময়ে দেওয়া') }}</th></tr>
            @forelse ($r['brands'] as $b)
                <tr>
                    <td>{{ $b['name'] }}</td>
                    <td class="num">{{ number_format($b['price']) }}</td>
                    <td class="num">{{ $n($b['in']) }}</td>
                    <td class="num">{{ $n($b['out']) }}</td>
                    <td class="num {{ $b['left'] > 0 ? 'zr-ok' : 'zr-bad' }}">{{ $n($b['left']) }}</td>
                    <td class="num">{{ number_format($b['value']) }}</td>
                    <td class="num">{{ $n($b['in_p']) }}</td>
                    <td class="num">{{ $n($b['out_p']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="zr-note">{{ __('এখনো কোনো ব্র্যান্ড নেই') }}</td></tr>
            @endforelse
            @if ($r['brands']->isNotEmpty())
                <tr class="zr-sum"><td>{{ __('মোট') }}</td><td></td><td class="num">{{ $n($r['brands']->sum('in')) }}</td><td class="num">{{ $n($r['brands']->sum('out')) }}</td>
                    <td class="num">{{ $n($r['onu_left']) }}</td><td class="num">{{ number_format($r['onu_value']) }}</td>
                    <td class="num">{{ $n($r['onu_in_p']) }}</td><td class="num">{{ $n($r['onu_out_p']) }}</td></tr>
            @endif
        </table>
    </div>

    <div class="zr-grid">
        <div class="zr-box">
            <h3>{{ __('ONU কেন দেওয়া হয়েছে (এই সময়ে)') }}</h3>
            <div class="zr-chips">
                @forelse ($r['purposes'] as $k => $v)
                    <span class="zr-chip">{{ $k }}: {{ $n($v) }}</span>
                @empty
                    <span class="zr-note">{{ __('এই সময়ে কোনো ONU দেওয়া হয়নি') }}</span>
                @endforelse
            </div>
            <h3>{{ __('নতুন সংযোগের ধরন (এই সময়ে)') }}</h3>
            <div class="zr-chips">
                @forelse ($r['types'] as $k => $v)
                    <span class="zr-chip">{{ $k }}: {{ $n($v) }}</span>
                @empty
                    <span class="zr-note">{{ __('এই সময়ে কোনো নতুন সংযোগ নেই') }}</span>
                @endforelse
                @if ($r['paid']->n)
                    <span class="zr-chip">{{ __('বাকি আদায় :n বার · ছাড় :d', ['n' => $n($r['paid']->n), 'd' => $tk($r['paid']->discount)]) }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="zr-box">
        <h3>{{ __('কর্মী অনুযায়ী') }} <span class="zr-note">{{ __('ONU, মাল আর সংযোগ এই সময়ের; বাকি সব সময়ের, "টাকা যার কাছে" ধরে') }}</span></h3>
        <table class="zr-t">
            <tr><th>{{ __('কর্মী') }}</th><th class="num">{{ __('ONU লাগিয়েছেন') }}</th><th class="num">{{ __('মাল নিয়েছেন') }}</th>
                <th class="num">{{ __('সংযোগের অর্ডার') }}</th><th class="num">{{ __('জমার টাকা তাঁর কাছে') }}</th><th class="num">{{ __('বাকি (তাঁর ভাগে)') }}</th></tr>
            @forelse ($r['staff'] as $s)
                <tr>
                    <td>{{ $s['name'] }}</td>
                    <td class="num">{{ $n($s['onu']) }}</td>
                    <td class="num">{{ $n($s['items']) }}</td>
                    <td class="num">{{ $n($s['conn']) }}</td>
                    <td class="num">{{ $s['deposit'] ? number_format($s['deposit']) : '—' }}</td>
                    <td class="num {{ $s['due'] > 0 ? 'zr-bad' : '' }}">{{ $s['due'] ? number_format($s['due']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="zr-note">—</td></tr>
            @endforelse
        </table>
    </div>
</x-filament-panels::page>
