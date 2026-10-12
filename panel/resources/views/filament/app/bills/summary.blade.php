@php
    $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').' টাকা';
    $balance = round($payable - $discount - $paid, 2);
    $muted = 'color:var(--z-muted,#6b7280)';
@endphp
<div style="display:flex; flex-wrap:wrap; gap:4px 14px; align-items:baseline; font-size:.9rem; border:1px solid var(--z-border, #e5e7eb); border-radius:10px; padding:6px 10px">
    <span><span style="{{ $muted }}">মোট বকেয়া</span> <b>{{ $tk($payable) }}</b></span>
    <span><span style="{{ $muted }}">ডিসকাউন্ট</span> <b>− {{ $tk($discount) }}</b></span>
    <span><span style="{{ $muted }}">টাকা নিলেন</span> <b>− {{ $tk($paid) }}</b></span>
    <span style="margin-left:auto"><b>বাকি বকেয়া</b>
        @if ($balance < 0)
            <b style="color:#d97706">{{ $tk(-$balance) }} বেশি নেওয়া হচ্ছে</b>
        @else
            <b style="color:{{ $balance > 0 ? '#dc2626' : '#16a34a' }}">{{ $tk($balance) }}</b>
        @endif
    </span>
</div>
