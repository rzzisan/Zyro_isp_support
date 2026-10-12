@php
    $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').' টাকা';
    $muted = 'color:var(--z-muted,#6b7280)';
@endphp
@if ($error)
    <div style="color:#dc2626; font-size:.85rem">বিলিং থেকে ফর্ম আনা যায়নি: {{ $error }}</div>
@elseif ($info)
    <div style="display:flex; flex-wrap:wrap; gap:4px 16px; align-items:baseline; font-size:.85rem; border:1px solid var(--z-border, #e5e7eb); border-radius:10px; padding:6px 10px; background:var(--z-surface-soft, #fafafa)">
        <span><span style="{{ $muted }}">PPPoE</span> <b>{{ $info['username'] ?? '—' }}</b></span>
        <span><span style="{{ $muted }}">Billing Status</span> <b>{{ $info['status'] ?? '—' }}</b></span>
        <span><span style="{{ $muted }}">মাসিক বিল</span> <b>{{ $tk($info['monthly'] ?? 0) }}</b></span>
        <span><span style="{{ $muted }}">বকেয়া (বিলিং-এ এখন)</span> <b style="{{ ($info['payable'] ?? 0) > 0 ? 'color:#dc2626' : 'color:#16a34a' }}">{{ $tk($info['payable'] ?? 0) }}</b></span>
        <span><span style="{{ $muted }}">বিলের মাস</span> <b>{{ $info['bill_month'] ?? '—' }}</b></span>
    </div>
    @foreach ($info['pending'] ?? [] as $p)
        <div style="margin-top:6px; color:#b45309; font-size:.85rem; font-weight:600">
            এই কাস্টমারের {{ $p['amount'] }} টাকার একটা বিল ({{ $p['date'] }}, {{ $p['created_by'] }}) এখনো approve হয়নি।
            approve না হওয়া পর্যন্ত বকেয়া কমে না, তাই এখন আবার নেওয়া যাবে না।
        </div>
    @endforeach
    @if (empty($info['pending']) && ($info['payable'] ?? 0) <= 0)
        <div style="margin-top:6px; color:#16a34a; font-size:.85rem; font-weight:600">বিলিং-এ কোনো বকেয়া নেই।</div>
    @endif
@endif
