{{-- Live check: every 5 s while this tab is visible, ask if bills changed; refresh table + cards only when they did. --}}
<div x-data="{
        version: null, at: null, busy: false, ok: true,
        async check() {
            if (document.hidden || this.busy) return;
            this.busy = true;
            try {
                const r = await fetch(@js($url), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                const j = r.ok ? await r.json() : {};
                this.ok = r.ok && !! j.version;
                if (j.version) {
                    if (this.version && j.version !== this.version) Livewire.dispatch('daily-bills-changed');
                    this.version = j.version;
                    this.at = new Date().toLocaleTimeString('en-GB');
                }
            } catch (e) { this.ok = false; }
            this.busy = false;
        },
     }"
     x-init="check(); setInterval(() => check(), 5000); document.addEventListener('visibilitychange', () => { if (! document.hidden) check() })"
     style="font-size:.75rem; color:var(--z-muted,#6b7280); text-align:right">
    <span x-show="ok" style="color:#16a34a">●</span><span x-show="! ok" style="color:#d97706">●</span>
    <span x-text="ok ? @js(__('লাইভ')) + (at ? ' · ' + @js(__('শেষ দেখা')) + ' ' + at : '') : @js(__('লাইভ আপডেট থেমে আছে, পরে আবার চেষ্টা হবে'))"></span>
</div>
