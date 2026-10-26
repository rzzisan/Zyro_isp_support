@php
    $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').__(' টাকা');
    $balance = round($payable - $discount - $paid, 2);
    $muted = 'color:var(--z-muted,#6b7280)';
@endphp
<div style="display:flex; flex-wrap:wrap; gap:4px 14px; align-items:baseline; font-size:.9rem; border:1px solid var(--z-border, #e5e7eb); border-radius:10px; padding:6px 10px">
    <span><span style="{{ $muted }}">{{ __('মোট বকেয়া') }}</span> <b>{{ $tk($payable) }}</b></span>
    <span><span style="{{ $muted }}">{{ __('ডিসকাউন্ট') }}</span> <b>− {{ $tk($discount) }}</b></span>
    <span><span style="{{ $muted }}">{{ __('টাকা নিলেন') }}</span> <b>− {{ $tk($paid) }}</b></span>
    <span style="margin-left:auto"><b>{{ __('বাকি বকেয়া') }}</b>
        @if ($balance < 0)
            {{-- more than the due: billing keeps it as an advance, the receipt shows it under Advance --}}
            <b style="color:#16a34a">{{ $tk(0) }}</b> · <span style="{{ $muted }}">{{ __('অগ্রিম') }}</span> <b style="color:#2563eb">{{ $tk(-$balance) }}</b>
        @else
            <b style="color:{{ $balance > 0 ? '#dc2626' : '#16a34a' }}">{{ $tk($balance) }}</b>
        @endif
    </span>
</div>
