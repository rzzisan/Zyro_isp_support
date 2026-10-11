@php
    $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').' টাকা';
    $balance = round($payable - $discount - $paid, 2);
    $cell = 'display:flex; justify-content:space-between; padding:6px 12px; font-size:.85rem';
    $line = 'border-top:1px solid var(--z-border, #e5e7eb)';
@endphp
<div style="border:1px solid var(--z-border, #e5e7eb); border-radius:12px; overflow:hidden">
    <div style="{{ $cell }}"><span style="color:var(--z-muted,#6b7280)">মোট বকেয়া</span><span style="font-weight:600">{{ $tk($payable) }}</span></div>
    <div style="{{ $cell }}; {{ $line }}"><span style="color:var(--z-muted,#6b7280)">ডিসকাউন্ট</span><span style="font-weight:600">− {{ $tk($discount) }}</span></div>
    <div style="{{ $cell }}; {{ $line }}"><span style="color:var(--z-muted,#6b7280)">টাকা নিলেন</span><span style="font-weight:600">− {{ $tk($paid) }}</span></div>
    <div style="{{ $cell }}; {{ $line }}; background:var(--z-surface-soft, #fafafa); font-size:.95rem">
        <span style="font-weight:700">বাকি বকেয়া</span>
        @if ($balance < 0)
            <span style="font-weight:700; color:#d97706">{{ $tk(-$balance) }} বেশি নেওয়া হচ্ছে</span>
        @else
            <span style="font-weight:700; color:{{ $balance > 0 ? '#dc2626' : '#16a34a' }}">{{ $tk($balance) }}</span>
        @endif
    </div>
</div>
