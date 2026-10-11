@php
    $box = 'border:1px solid var(--z-border, #e5e7eb); border-radius:12px; padding:10px 12px; background:var(--z-surface-soft, #fafafa)';
    $row = fn ($k, $v, $style = '') => '<div style="color:var(--z-muted,#6b7280)">'.e($k).'</div><div style="font-weight:600;'.$style.'">'.e($v === null || $v === '' ? '—' : $v).'</div>';
    $grid = 'display:grid; grid-template-columns:auto 1fr; gap:3px 12px; font-size:.82rem';
    $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').' টাকা';
@endphp
@if ($error)
    <div style="color:#dc2626; font-size:.85rem">বিলিং থেকে ফর্ম আনা যায়নি: {{ $error }}</div>
@elseif ($info)
    <div style="{{ $box }}">
        <div style="{{ $grid }}">
            {!! $row('PPPoE', $info['username'] ?? null) !!}
            {!! $row('Billing Status', $info['status'] ?? null) !!}
            {!! $row('মাসিক বিল', $tk($info['monthly'] ?? 0)) !!}
            {!! $row('বকেয়া (বিলিং-এ এখন)', $tk($info['payable'] ?? 0), ($info['payable'] ?? 0) > 0 ? 'color:#dc2626' : 'color:#16a34a') !!}
            {!! $row('বিলের মাস', $info['bill_month'] ?? null) !!}
        </div>
    </div>
    @foreach ($info['pending'] ?? [] as $p)
        <div style="margin-top:8px; color:#b45309; font-size:.85rem; font-weight:600">
            এই কাস্টমারের {{ $p['amount'] }} টাকার একটা বিল ({{ $p['date'] }}, {{ $p['created_by'] }}) এখনো approve হয়নি।
            approve না হওয়া পর্যন্ত বকেয়া কমে না, তাই এখন আবার নেওয়া যাবে না।
        </div>
    @endforeach
    @if (empty($info['pending']) && ($info['payable'] ?? 0) <= 0)
        <div style="margin-top:8px; color:#16a34a; font-size:.85rem; font-weight:600">বিলিং-এ কোনো বকেয়া নেই।</div>
    @endif
@endif
