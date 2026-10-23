<x-filament-panels::page>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
        .zl-note { font-size: .8rem; color: var(--z-muted); }
        .zl-bar { display: flex; gap: 10px; flex-wrap: wrap; align-items: end; }
        .zl-bar label { display: flex; flex-direction: column; gap: 4px; font-size: .8rem; color: var(--z-muted); }
        .zl-bar select, .zl-bar input { border: 1px solid var(--z-border); background: var(--z-surface); color: var(--z-fg); border-radius: 8px; padding: 6px 10px; }
        .zl-map { height: 460px; border-radius: 14px; border: 1px solid var(--z-border); z-index: 0; }
        .zl-card { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; overflow: hidden; }
        .zl-table { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .zl-table th, .zl-table td { padding: 8px 12px; border-bottom: 1px solid var(--z-border); text-align: left; vertical-align: top; }
        .zl-table th { color: var(--z-muted); font-weight: 600; font-size: .78rem; }
        .zl-table tr:last-child td { border-bottom: 0; }
        .zl-stats { display: flex; gap: 18px; flex-wrap: wrap; font-size: .85rem; color: var(--z-muted); }
        .zl-stats strong { color: var(--z-fg); font-size: 1.05rem; }
        .zl-good { color: #059669; } .zl-bad { color: #dc2626; } .zl-warn { color: #d97706; }
        .zl-link { color: var(--z-accent); cursor: pointer; background: none; border: 0; padding: 0; font: inherit; }
    </style>

    <div class="zl-note">{{ __('টেকনিশিয়ানরা লগইনের সময় প্রাইভেসি পলিসি মেনে নিয়েছেন। লোকেশন যায় শুধু ডিউটির সময় (শুরুর সময়ের পর অফিসে এলে শুরু, শেষের সময়ে বা সব কাজ শেষ হলে শেষ) আর কাজ চলার সময়। শুধু মালিক এই পাতা দেখেন, ৯০ দিন পর পুরোনো লোকেশন মুছে যায়।') }}</div>

    <div class="zl-bar">
        <label>{{ __('টেকনিশিয়ান') }}
            <select wire:model.live="user">
                <option value="">{{ __('সবার শেষ অবস্থান') }}</option>
                @foreach ($technicians as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </label>
        @if ($route)
            <label>{{ __('তারিখ') }}
                <input type="date" wire:model.live="date" max="{{ now('Asia/Dhaka')->toDateString() }}">
            </label>
        @endif
    </div>

    <div wire:key="map-{{ $user }}-{{ $date }}-{{ md5(json_encode($map)) }}" wire:ignore
         x-data="{
            init() {
                const data = @js($map);
                const go = () => {
                    if (! window.L) { return setTimeout(go, 200); }
                    const map = L.map(this.$refs.map);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
                    const all = [];
                    if (data.line.length) {
                        const pts = data.line.map(p => [p[0], p[1]]);
                        L.polyline(pts, { color: '#0d9488', weight: 4, opacity: .8 }).addTo(map);
                        data.line.forEach(p => {
                            L.circleMarker([p[0], p[1]], { radius: p[3] ? 6 : 3, color: p[3] ? '#dc2626' : '#0d9488', fillOpacity: .9 })
                                .bindTooltip(p[2] + (p[3] ? ' · {{ __('নকল লোকেশন') }}' : '')).addTo(map);
                        });
                        all.push(...pts);
                    }
                    const colors = { start: '#2563eb', finish: '#059669', live: '#0d9488', old: '#9ca3af' };
                    data.marks.forEach(m => {
                        L.circleMarker([m.lat, m.lng], { radius: 9, color: m.mock ? '#dc2626' : colors[m.kind], weight: 3, fillOpacity: .5 })
                            .bindTooltip(m.label + (m.mock ? ' · {{ __('নকল লোকেশন') }}' : ''), { permanent: data.line.length === 0 }).addTo(map);
                        all.push([m.lat, m.lng]);
                    });
                    if (all.length) { map.fitBounds(all, { padding: [30, 30], maxZoom: 16 }); } else { map.setView([23.8, 90.4], 7); }
                };
                go();
            }
         }">
        <div x-ref="map" class="zl-map"></div>
    </div>

    @if ($route)
        <div class="zl-stats">
            <span>{{ __('লোকেশন') }} <strong>{{ $route['points']->count() }}</strong></span>
            <span>{{ __('চলেছেন') }} <strong>{{ number_format($route['metres'] / 1000, 1) }} {{ __('কিমি') }}</strong></span>
            <span>{{ __('কাজ') }} <strong>{{ $route['jobs']->count() }}</strong></span>
            @if ($route['mock'])
                <span class="zl-bad">{{ __('নকল লোকেশন') }} <strong class="zl-bad">{{ $route['mock'] }}</strong></span>
            @endif
            @if ($route['gaps'])
                <span class="zl-warn">{{ __('লোকেশন বন্ধ ছিল') }} <strong class="zl-warn">{{ count($route['gaps']) }}</strong></span>
            @endif
        </div>

        @if ($route['jobs']->isNotEmpty())
            <div class="zl-card">
                <table class="zl-table">
                    <tr><th>{{ __('টিকিট') }}</th><th>{{ __('শুরু') }}</th><th>{{ __('শেষ') }}</th></tr>
                    @foreach ($route['jobs'] as $j)
                        <tr>
                            <td>#{{ $j->ticket?->complain_id }} {{ $j->ticket?->customer_name }}<br><span class="zl-note">{{ $j->ticket?->category }}</span></td>
                            @foreach (['start' => $j->started_at, 'finish' => $j->finished_at] as $at => $time)
                                <td>
                                    {{ $time?->timezone('Asia/Dhaka')->format('g:i A') ?? '—' }}
                                    @if ($j->{"{$at}_lat"} === null)
                                        @if ($time)<br><span class="zl-note">{{ __('লোকেশন আসেনি') }}</span>@endif
                                    @else
                                        @if ($j->{"{$at}_mock"})<br><span class="zl-bad">{{ __('নকল লোকেশন') }}</span>@endif
                                        @if ($j->{"{$at}_distance"} !== null)
                                            <br><span class="{{ $j->{"{$at}_distance"} > 300 ? 'zl-bad' : 'zl-good' }}">{{ __('কাস্টমার থেকে :m মি', ['m' => number_format($j->{"{$at}_distance"})]) }}</span>
                                        @endif
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif

        @if ($route['gaps'])
            <div class="zl-card">
                <table class="zl-table">
                    <tr><th>{{ __('লোকেশন বন্ধ ছিল') }}</th><th>{{ __('কতক্ষণ') }}</th></tr>
                    @foreach ($route['gaps'] as $g)
                        <tr><td>{{ $g['from']->timezone('Asia/Dhaka')->format('g:i A') }} – {{ $g['to']->timezone('Asia/Dhaka')->format('g:i A') }}</td>
                            <td>{{ __(':m মিনিট', ['m' => $g['minutes']]) }}</td></tr>
                    @endforeach
                </table>
            </div>
        @endif
        <div class="zl-note">{{ __('ফোন বন্ধ, নেট নেই বা লোকেশন বন্ধ থাকলে লোকেশন বন্ধ দেখায়। নেট ফিরলে জমে থাকা লোকেশন পরে আসে।') }}</div>
    @else
        <div class="zl-card" wire:poll.60s>
            <table class="zl-table">
                <tr><th>{{ __('টেকনিশিয়ান') }}</th><th>{{ __('অবস্থা') }}</th><th>{{ __('শেষ লোকেশন') }}</th><th>{{ __('ব্যাটারি') }}</th></tr>
                @forelse ($board as $r)
                    <tr>
                        <td><button type="button" class="zl-link" wire:click="showRoute({{ $r['user_id'] }})">{{ $r['name'] }}</button></td>
                        <td>
                            @if (! $r['consent'])
                                <span class="zl-note">{{ __('সম্মতি দেননি') }}</span>
                            @elseif ($r['working'])
                                <span class="zl-good">{{ __('কাজ চলছে') }}</span>
                            @elseif ($r['on_duty_since'])
                                <span class="zl-good">{{ __('ডিউটিতে, :t থেকে', ['t' => $r['on_duty_since']->timezone('Asia/Dhaka')->format('g:i A')]) }}</span>
                            @else
                                <span class="zl-note">{{ __('ডিউটির বাইরে') }}</span>
                            @endif
                        </td>
                        <td>
                            @if ($r['at'])
                                {{ $r['at']->timezone('Asia/Dhaka')->format('d M, g:i A') }}
                                @if ($r['mock'])<br><span class="zl-bad">{{ __('নকল লোকেশন') }}</span>@endif
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $r['battery'] !== null ? $r['battery'].'%' : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="zl-note">{{ __('টিম পাতায় কোনো সদস্যকে বিলিংয়ের কর্মীর সাথে যুক্ত করা হয়নি') }}</td></tr>
                @endforelse
            </table>
        </div>
    @endif
</x-filament-panels::page>
