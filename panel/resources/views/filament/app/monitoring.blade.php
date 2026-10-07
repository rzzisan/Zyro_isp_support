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
        .zm-bar { height: 5px; border-radius: 999px; background: color-mix(in srgb, #dc2626 25%, transparent); margin-top: 8px; overflow: hidden; }
        .zm-bar i { display: block; height: 100%; background: #10b981; }
    </style>
    <div class="zm-tabs" wire:poll.60s>
        @foreach ($counts as $server => $c)
            <button type="button" wire:click="setServer('{{ $server }}')" class="zm-tab {{ $this->server === (string) $server ? 'on' : '' }}">
                <b>{{ $server === '' ? 'সব সার্ভার' : $server }}</b>
                <div class="zm-nums">
                    <span>মোট <strong>{{ number_format($c['total']) }}</strong></span>
                    <span class="zm-on">অনলাইন <strong>{{ number_format($c['online']) }}</strong></span>
                    <span class="zm-off">অফলাইন <strong>{{ number_format($c['total'] - $c['online']) }}</strong></span>
                </div>
                <div class="zm-bar"><i style="width: {{ $c['total'] ? round(100 * $c['online'] / $c['total']) : 0 }}%"></i></div>
            </button>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
