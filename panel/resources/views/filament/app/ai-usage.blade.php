<x-filament-panels::page>
    @php
        $totals = $this->totals();
        $money = fn ($v, $priced = true) => '$'.number_format($v, $v < 1 ? 4 : 2).($priced ? '' : '*');
        $n = fn ($v) => number_format((int) $v);
        $periods = ['today' => 'আজ', '7' => '৭ দিন', '30' => '৩০ দিন'];
    @endphp
    <style>
        .za-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
        .za-card { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; padding: 14px 16px; color: var(--z-fg); }
        .za-card b { font-size: .9rem; }
        .za-card .za-cost { font-size: 1.6rem; font-weight: 700; margin: 4px 0; }
        .za-card .za-sub { font-size: .8rem; color: var(--z-muted); line-height: 1.5; }
        .za-box { border: 1px solid var(--z-border); background: var(--z-surface); border-radius: 14px; padding: 6px 0; overflow-x: auto; }
        .za-box h3 { font-weight: 600; padding: 8px 16px; }
        .za-t { width: 100%; font-size: .85rem; border-collapse: collapse; }
        .za-t th { text-align: left; font-weight: 600; color: var(--z-muted); padding: 6px 16px; white-space: nowrap; }
        .za-t td { padding: 8px 16px; border-top: 1px solid var(--z-border); vertical-align: top; }
        .za-t td.num, .za-t th.num { text-align: right; white-space: nowrap; }
        .za-tabs { display: flex; gap: 8px; }
        .za-tabs button { border: 1px solid var(--z-border); border-radius: 999px; padding: 4px 14px; font-size: .85rem; }
        .za-tabs button.on { border-color: var(--z-accent); background: color-mix(in srgb, var(--z-accent) 15%, transparent); }
        .za-note { font-size: .8rem; color: var(--z-muted); }
        .za-q { font-size: .78rem; color: var(--z-muted); }
        .za-bad { color: #dc2626; }
    </style>

    <div class="za-cards" wire:poll.60s>
        @foreach ($totals as $t)
            <div class="za-card">
                <b>{{ $t['label'] }}</b>
                <div class="za-cost">{{ $money($t['cost'], $t['priced']) }}</div>
                <div class="za-sub">
                    {{ $n($t['input'] + $t['output']) }} টোকেন (ইনপুট {{ $n($t['input']) }}@if ($t['cached']), এর মধ্যে cache থেকে {{ $n($t['cached']) }}@endif · আউটপুট {{ $n($t['output']) }})<br>
                    {{ $n($t['calls']) }} বার AI ডাকা হয়েছে
                    @if ($t['failed']) · <span class="za-bad">{{ $n($t['failed']) }} বার ব্যর্থ (লিমিট/এরর)</span> @endif
                </div>
            </div>
        @endforeach
    </div>
    <p class="za-note">খরচ আনুমানিক: টোকেন × নিচের মডেলের দাম। ফ্রি টিয়ারের key-তে আসল বিল শূন্য, এটা পেইড হলে কত হতো সেই হিসাব। * মানে কোনো মডেলের দাম সেট নেই। হিসাব শুরু হয়েছে এই ফিচার চালুর পর থেকে।</p>

    <div class="za-tabs">
        @foreach ($periods as $p => $label)
            <button type="button" wire:click="setPeriod('{{ $p }}')" class="{{ $this->period === $p ? 'on' : '' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="za-box">
        <h3>key অনুযায়ী</h3>
        <table class="za-t">
            <tr><th>Key</th><th class="num">কল</th><th class="num">টোকেন</th><th class="num">খরচ</th><th>প্রোভাইডারের লিমিট (শেষ জানা)</th></tr>
            @forelse ($this->byKey() as $k)
                <tr>
                    <td>{{ $k['name'] }}<div class="za-q">{{ $k['masked'] }} @if ($k['cooling']) · <span class="za-bad">এখন বিরতিতে</span> @endif</div></td>
                    <td class="num">{{ $n($k['usage']['calls'] ?? 0) }}@if ($k['usage']['failed'] ?? 0)<div class="za-q za-bad">{{ $n($k['usage']['failed']) }} ব্যর্থ</div>@endif</td>
                    <td class="num">{{ $n(($k['usage']['input'] ?? 0) + ($k['usage']['output'] ?? 0)) }}<div class="za-q">আজ {{ $n($k['today_tokens']) }}</div></td>
                    <td class="num">{{ $k['usage'] ? $money($k['usage']['cost'], $k['usage']['priced']) : '—' }}</td>
                    <td>
                        @forelse ($k['quota'] as $line)
                            <div class="za-q">{{ $line }}</div>
                        @empty
                            <div class="za-q">প্রোভাইডার জানায়নি (Gemini লিমিট জানায় না)</div>
                        @endforelse
                        @if ($k['quota_at'] && $k['quota'])<div class="za-q">আপডেট {{ $k['quota_at'] }}</div>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="za-note">কোনো AI key নেই</td></tr>
            @endforelse
        </table>
    </div>

    <div class="za-box">
        <h3>মডেল অনুযায়ী</h3>
        <table class="za-t">
            <tr><th>মডেল</th><th class="num">কল</th><th class="num">ইনপুট</th><th class="num">আউটপুট</th><th class="num">দাম (USD/1M)</th><th class="num">খরচ</th></tr>
            @forelse ($this->byModel() as $m)
                <tr>
                    <td>{{ $m['model'] }}<div class="za-q">{{ \App\Models\AiKey::PROVIDERS[$m['provider']][0] ?? $m['provider'] }}</div></td>
                    <td class="num">{{ $n($m['calls']) }}</td>
                    <td class="num">{{ $n($m['input']) }}@if (str_contains($m['model'], 'whisper'))<div class="za-q">সেকেন্ড অডিও</div>@elseif ($m['cached'])<div class="za-q">cache থেকে {{ $n($m['cached']) }}</div>@endif</td>
                    <td class="num">{{ $n($m['output']) }}</td>
                    <td class="num">
                        {{ $m['price'] ? rtrim(rtrim(number_format($m['price'][0], 4), '0'), '.').' / '.rtrim(rtrim(number_format($m['price'][1], 4), '0'), '.') : 'সেট নেই' }}
                        @if ($m['price'] && ! $m['price'][2])<div class="za-q">ডিফল্ট</div>@endif
                        <div>{{ ($this->editPriceAction)(['provider' => $m['provider'], 'model' => $m['model']]) }}</div>
                    </td>
                    <td class="num">{{ $m['priced'] ? $money($m['cost']) : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="za-note">এই সময়ে কোনো AI কল নেই</td></tr>
            @endforelse
        </table>
    </div>

    <div class="za-cards">
        <div class="za-box">
            <h3>কোন কাজে</h3>
            <table class="za-t">
                <tr><th>কাজ</th><th class="num">কল</th><th class="num">টোকেন</th><th class="num">খরচ</th></tr>
                @foreach ($this->byPurpose() as $r)
                    <tr>
                        <td>{{ $this::PURPOSES[$r['purpose']] ?? $r['purpose'] }}</td>
                        <td class="num">{{ $n($r['calls']) }}</td>
                        <td class="num">{{ $r['purpose'] === 'voice' ? $n($r['input']).' সে.' : $n($r['input'] + $r['output']) }}</td>
                        <td class="num">{{ $money($r['cost'], $r['priced']) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
        <div class="za-box">
            <h3>গত ১৪ দিন</h3>
            <table class="za-t">
                <tr><th>দিন</th><th class="num">কল</th><th class="num">টোকেন</th><th class="num">খরচ</th></tr>
                @forelse ($this->daily() as $d)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($d['day'])->format('j M') }}</td>
                        <td class="num">{{ $n($d['calls']) }}</td>
                        <td class="num">{{ $n($d['tokens']) }}</td>
                        <td class="num">{{ $money($d['cost']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="za-note">এখনো কোনো হিসাব নেই</td></tr>
                @endforelse
            </table>
        </div>
    </div>
</x-filament-panels::page>
