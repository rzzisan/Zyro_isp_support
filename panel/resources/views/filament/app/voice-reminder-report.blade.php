<x-filament-panels::page>
    @php
        $r = $this->report();
        $t = $r['total'];
        $n = fn ($v) => number_format((int) $v);
        $pct = fn ($a, $b) => $b ? round($a * 100 / $b).'%' : '—';
        $periods = ['7' => __('৭ দিন'), '30' => __('৩০ দিন'), '90' => __('৯০ দিন')];
        $keyNames = ['0' => __('আবার শোনা'), '1' => __('SMS'), '2' => __('স্টাফ'), '3' => __('লাইন চালু'), '9' => __('আর কল নয়')];
    @endphp
    <style>
        .zr-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
        .zr-card { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; padding: 14px 16px; color: var(--z-fg); }
        .zr-card b { font-size: .85rem; color: var(--z-muted); font-weight: 600; }
        .zr-card .zr-big { font-size: 1.6rem; font-weight: 700; margin: 4px 0; }
        .zr-card .zr-sub { font-size: .8rem; color: var(--z-muted); line-height: 1.5; }
        .zr-box { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; padding: 6px 0; overflow-x: auto; }
        .zr-box h3 { font-weight: 600; padding: 8px 16px; }
        .zr-t { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .zr-t th { text-align: left; font-weight: 600; color: var(--z-muted); padding: 6px 12px; white-space: nowrap; }
        .zr-t td { padding: 8px 12px; border-top: 1px solid var(--z-border); white-space: nowrap; }
        .zr-t td.num, .zr-t th.num { text-align: right; }
        .zr-tabs { display: flex; gap: 8px; }
        .zr-tabs button { border: 1px solid var(--z-border); border-radius: 999px; padding: 4px 14px; font-size: .85rem; }
        .zr-tabs button.on { border-color: var(--z-accent); background: color-mix(in srgb, var(--z-accent) 15%, transparent); }
        .zr-note { font-size: .8rem; color: var(--z-muted); }
        .zr-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 4px 16px 12px; }
        .zr-chip { border: 1px solid var(--z-border); border-radius: 999px; padding: 3px 12px; font-size: .82rem; }
    </style>

    <div class="zr-tabs">
        @foreach ($periods as $p => $label)
            <button type="button" wire:click="setPeriod('{{ $p }}')" class="{{ $this->period === $p ? 'on' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="zr-cards">
        <div class="zr-card"><b>{{ __('কল') }}</b><div class="zr-big">{{ $n($t['calls']) }}</div>
            <div class="zr-sub">{{ __(':c জন কাস্টমার', ['c' => $n($t['customers'])]) }}@if ($t['alt']) · {{ __('বিকল্প নম্বরে :a', ['a' => $n($t['alt'])]) }}@endif</div></div>
        <div class="zr-card"><b>{{ __('ধরেছেন') }}</b><div class="zr-big">{{ $pct($t['answered'], $t['calls']) }}</div>
            <div class="zr-sub">{{ __(':a কল · :r জন কাস্টমারকে পাওয়া গেছে', ['a' => $n($t['answered']), 'r' => $n($t['reached'])]) }}@if ($t['alt_answered']) · {{ __('বিকল্প নম্বরে :a', ['a' => $n($t['alt_answered'])]) }}@endif</div></div>
        <div class="zr-card"><b>{{ __('৩ দিনের মধ্যে পেমেন্ট') }}</b><div class="zr-big">{{ $pct($t['paid'], $t['reached']) }}</div>
            <div class="zr-sub">{{ __('যাদের পাওয়া গেছে তাদের :p জন', ['p' => $n($t['paid'])]) }}</div></div>
        <div class="zr-card"><b>{{ __('ফোন খরচ (আনুমানিক)') }}</b><div class="zr-big">{{ number_format($t['cost']) }} {{ __('টাকা') }}</div>
            <div class="zr-sub">{{ __(':m মিনিট × :r টাকা', ['m' => $n($t['minutes']), 'r' => $r['rate']]) }}</div></div>
        <div class="zr-card"><b>{{ __('SMS') }}</b><div class="zr-big">{{ $n($t['sms_saved']) }}</div>
            <div class="zr-sub">{{ __('SMS লাগেনি (কলে জানানো হয়েছে) · ব্যাকআপ SMS গেছে :b', ['b' => $n($t['sms_backup'])]) }}</div></div>
    </div>

    <div class="zr-box">
        <h3>{{ __('কোন বাটন কতবার') }}</h3>
        <div class="zr-chips">
            @forelse ($r['keys'] as $k => $v)
                <span class="zr-chip">{{ $k }}@isset($keyNames[$k]) ({{ $keyNames[$k] }})@endisset: {{ $n($v) }}</span>
            @empty
                <span class="zr-note">{{ __('এখনো কোনো বাটন চাপা হয়নি') }}</span>
            @endforelse
        </div>
        <h3>{{ __('ফলাফল') }}</h3>
        <div class="zr-chips">
            @forelse ($r['results'] as $k => $v)
                <span class="zr-chip">{{ __(\App\Models\VoiceReminderCall::RESULTS[$k] ?? $k) }}: {{ $n($v) }}</span>
            @empty
                <span class="zr-note">—</span>
            @endforelse
        </div>
    </div>

    <div class="zr-box">
        <h3>{{ __('দিন অনুযায়ী') }}</h3>
        <table class="zr-t">
            <tr><th>{{ __('দিন') }}</th><th class="num">{{ __('কল') }}</th><th class="num">{{ __('ধরেছেন') }}</th><th class="num">{{ __('কাস্টমার পাওয়া') }}</th>
                <th class="num">{{ __('বিকল্প নম্বর') }}</th><th class="num">{{ __('৩ দিনে পেমেন্ট') }}</th><th class="num">{{ __('মিনিট') }}</th>
                <th class="num">{{ __('খরচ') }}</th><th class="num">{{ __('SMS লাগেনি / ব্যাকআপ') }}</th></tr>
            @forelse ($r['days'] as $day => $d)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($day)->format('d M, D') }}</td>
                    <td class="num">{{ $n($d['calls']) }}</td>
                    <td class="num">{{ $n($d['answered']) }} <span class="zr-note">({{ $pct($d['answered'], $d['calls']) }})</span></td>
                    <td class="num">{{ $n($d['reached']) }} / {{ $n($d['customers']) }}</td>
                    <td class="num">{{ $n($d['alt_answered']) }} / {{ $n($d['alt']) }}</td>
                    <td class="num">{{ $n($d['paid']) }}</td>
                    <td class="num">{{ $n($d['minutes']) }}</td>
                    <td class="num">{{ number_format($d['cost']) }}</td>
                    <td class="num">{{ $n($d['sms_saved']) }} / {{ $n($d['sms_backup']) }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="zr-note">{{ __('এই সময়ে কোনো রিমাইন্ডার কল যায়নি (টেস্ট কল এখানে গোনা হয় না)') }}</td></tr>
            @endforelse
        </table>
    </div>
    <p class="zr-note">{{ __('টেস্ট কল বাদ। খরচ: যে কল ধরা হয়েছে তার প্রতিটা শুরু হওয়া মিনিট × রিমাইন্ডার সেটিংসের মিনিট রেট। পেমেন্ট: কল পাওয়ার দিন থেকে ৩ দিনের মধ্যে বিলিংয়ের শেষ পেমেন্ট, অনলাইন পেমেন্ট বা Desk-এ বিল নেওয়া।') }}</p>
</x-filament-panels::page>
