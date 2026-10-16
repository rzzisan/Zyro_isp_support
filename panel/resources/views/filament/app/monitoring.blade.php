<x-filament-panels::page>
    @php($counts = $this->counts())
    <style>
        .zm-tabs { display: flex; gap: 10px; flex-wrap: wrap; }
        .zm-tab { flex: 1 1 180px; text-align: left; border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px;
            padding: 12px 14px; cursor: pointer; color: var(--z-fg); box-shadow: 0 6px 16px rgba(17, 27, 44, .05); }
        .zm-tab.on { border-color: var(--z-accent); box-shadow: 0 0 0 2px color-mix(in srgb, var(--z-accent) 35%, transparent); }
        .zm-tab b { display: block; font-size: .95rem; }
        .zm-nums { display: flex; gap: 12px; margin-top: 6px; font-size: .8rem; color: var(--z-muted); flex-wrap: wrap; }
        .zm-nums span strong { font-size: 1.05rem; color: var(--z-fg); }
        .zm-on strong { color: #059669 !important; }
        .zm-off strong { color: #dc2626 !important; }
        .zm-blk strong { color: #d97706 !important; }
        .zm-bar { height: 5px; border-radius: 999px; background: color-mix(in srgb, #dc2626 25%, transparent); margin-top: 8px; overflow: hidden; }
        .zm-bar i { display: block; height: 100%; background: #10b981; }
        .zm-note { font-size: .8rem; color: var(--z-muted); margin-bottom: -4px; }
        .zm-off-for { color: #dc2626; font-variant-numeric: tabular-nums; white-space: nowrap; }
    </style>
    @if ($this->refreshing)
        <div wire:poll.5s="checkRefresh" style="display:none"></div>
    @endif
    @if ($this->cardsFiltered())
        <div class="zm-note">{{ __('ফিল্টার অনুযায়ী গণনা দেখানো হচ্ছে') }}</div>
    @endif
    <div class="zm-tabs" wire:poll.60s>
        @foreach ($counts as $server => $c)
            <button type="button" wire:click="setServer('{{ $server }}')" class="zm-tab {{ $this->server === (string) $server ? 'on' : '' }}">
                <b>{{ $server === '' ? __('সব সার্ভার') : $server }}</b>
                <div class="zm-nums">
                    <span>{{ __('মোট') }} <strong>{{ number_format($c['total']) }}</strong></span>
                    <span class="zm-on">{{ __('অনলাইন') }} <strong>{{ number_format($c['online']) }}</strong></span>
                    <span class="zm-off">{{ __('অফলাইন') }} <strong>{{ number_format($c['total'] - $c['online'] - $c['blocked']) }}</strong></span>
                    @if ($c['blocked'])
                        <span class="zm-blk" title="{{ __('কানেক্ট আছে কিন্তু MikroTik-এ ব্লক প্রোফাইলে, ইন্টারনেট নেই') }}">{{ __('ব্লক') }} <strong>{{ number_format($c['blocked']) }}</strong></span>
                    @endif
                </div>
                <div class="zm-bar"><i style="width: {{ $c['total'] ? round(100 * $c['online'] / $c['total']) : 0 }}%"></i></div>
            </button>
        @endforeach
    </div>

    {{ $this->table }}

    <script>
        // offline-for counters: tick every second from data-off-since (unix time of last seen online)
        if (! window.zmOffTimer) {
            window.zmOffTimer = setInterval(() => {
                const now = Math.floor(Date.now() / 1000);
                document.querySelectorAll('.zm-off-for[data-off-since]').forEach((el) => {
                    const t = Math.max(0, now - Number(el.dataset.offSince)), d = Math.floor(t / 86400);
                    const p = (n) => String(n).padStart(2, '0');
                    el.textContent = (d ? d + ' ' + @js(__('দিন')) + ' ' : '') + p(Math.floor(t % 86400 / 3600)) + ':' + p(Math.floor(t % 3600 / 60)) + ':' + p(t % 60);
                });
            }, 1000);
        }
    </script>
</x-filament-panels::page>
