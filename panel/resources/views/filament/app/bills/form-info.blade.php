@php
    $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').__(' টাকা');
    $muted = 'color:var(--z-muted,#6b7280)';
@endphp
@if ($error)
    <div style="color:#dc2626; font-size:.85rem">{{ __('বিলিং থেকে ফর্ম আনা যায়নি: :error', ['error' => $error]) }}</div>
@elseif ($info)
    <div style="display:flex; flex-wrap:wrap; gap:4px 16px; align-items:baseline; font-size:.85rem; border:1px solid var(--z-border, #e5e7eb); border-radius:10px; padding:6px 10px; background:var(--z-surface-soft, #fafafa)">
        <span><span style="{{ $muted }}">PPPoE</span> <b>{{ $info['username'] ?? '—' }}</b></span>
        <span><span style="{{ $muted }}">Billing Status</span> <b>{{ $info['status'] ?? '—' }}</b></span>
        <span><span style="{{ $muted }}">{{ __('মাসিক বিল') }}</span> <b>{{ $tk($info['monthly'] ?? 0) }}</b></span>
        <span><span style="{{ $muted }}">{{ __('বকেয়া (বিলিং-এ এখন)') }}</span> <b style="{{ ($info['payable'] ?? 0) > 0 ? 'color:#dc2626' : 'color:#16a34a' }}">{{ $tk($info['payable'] ?? 0) }}</b></span>
        <span><span style="{{ $muted }}">{{ __('বিলের মাস') }}</span> <b>{{ $info['bill_month'] ?? '—' }}</b></span>
    </div>
    @if (! empty($info['blocked']))
        @php
            $bl = $info['blocked'];
            $since = ! empty($bl['since']) ? \Illuminate\Support\Carbon::parse($bl['since'])->timezone('Asia/Dhaka') : null;
            // how long, as months:days (calendar months)
            $gap = $since ? $since->copy()->startOfDay()->diff(now('Asia/Dhaka')->startOfDay()) : null;
        @endphp
        <div style="margin-top:6px; color:#dc2626; font-size:.85rem; font-weight:600">
            @if ($since)
                {{ __('লাইন ব্লক :months মাস :days দিন ধরে', ['months' => $gap->y * 12 + $gap->m, 'days' => $gap->d]) }}
                <span style="{{ $muted }}; font-weight:400">({{ __(':date থেকে', ['date' => $since->format('d M Y, g:i A')]) }}{{ ! empty($bl['by']) ? ' · '.$bl['by'] : '' }}{{ ! empty($bl['profile']) ? ' · '.$bl['profile'] : '' }})</span>
            @else
                {{ __('লাইন ব্লক (কবে থেকে বিলিংয়ের লগে পাওয়া যায়নি)') }}
            @endif
        </div>
    @endif
    @foreach ($info['pending'] ?? [] as $p)
        <div style="margin-top:6px; color:#b45309; font-size:.85rem; font-weight:600">
            {{ __('এই কাস্টমারের :amount টাকার একটা বিল (:date, :by) এখনো approve হয়নি।', ['amount' => $p['amount'], 'date' => $p['date'], 'by' => $p['created_by']]) }}
            {{ __('approve না হওয়া পর্যন্ত বকেয়া কমে না, তাই এখন আবার নেওয়া যাবে না।') }}
        </div>
    @endforeach
    @if (empty($info['pending']) && ($info['payable'] ?? 0) <= 0)
        <div style="margin-top:6px; color:#16a34a; font-size:.85rem; font-weight:600">{{ __('বিলিং-এ কোনো বকেয়া নেই।') }}</div>
    @endif
@endif
