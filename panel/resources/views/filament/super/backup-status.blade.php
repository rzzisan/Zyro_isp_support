@php
    $fmt = fn ($t) => $t ? $t->timezone('Asia/Dhaka')->format('d M, h:i A') : '—';
    $size = fn ($b) => $b ? \Illuminate\Support\Number::fileSize((int) $b, 1) : '—';
    $card = 'border:1px solid var(--z-border, #e5e7eb); border-radius:12px; padding:14px 16px; background:var(--z-surface, #fff)';
    $bad = 'border:1px solid #fca5a5; border-radius:12px; padding:14px 16px; background:var(--z-surface, #fff)';
    $label = 'font-size:.75rem; color:var(--z-muted, #6b7280)';
    $big = 'margin-top:4px; font-size:1.1rem; font-weight:600';
    $note = 'margin-bottom:12px; border-radius:10px; padding:10px 14px; font-size:.875rem';
    $settings = \App\Filament\Super\Pages\SystemBackupSettings::getUrl(panel: 'super');
@endphp
<x-filament-widgets::widget>
    @if (! $s->connected())
        <div style="{{ $note }}; border:1px solid #fcd34d; background:#fffbeb; color:#92400e">
            {{ __('Google Drive এখনো সংযুক্ত নয়।') }}
            <a href="{{ $settings }}" style="text-decoration:underline; font-weight:600">{{ __('ব্যাকআপ সেটিংসে গিয়ে সংযোগ দিন') }}</a>
        </div>
    @elseif ($s->google_error)
        <div style="{{ $note }}; border:1px solid #fca5a5; background:#fef2f2; color:#991b1b">❌ {{ $s->google_error }}
            <a href="{{ $settings }}" style="text-decoration:underline; font-weight:600">{{ __('ব্যাকআপ সেটিংস') }}</a></div>
    @elseif (! $s->crypt_key_confirmed_at)
        <div style="{{ $note }}; border:1px solid #fcd34d; background:#fffbeb; color:#92400e">
            {{ __('রিকভারি কী এখনো লিখে রাখা হয়নি, তাই অটো ব্যাকআপ বন্ধ।') }}
            <a href="{{ $settings }}" style="text-decoration:underline; font-weight:600">{{ __('ব্যাকআপ সেটিংস') }}</a></div>
    @elseif (! $s->enabled)
        <div style="{{ $note }}; border:1px solid var(--z-border, #e5e7eb); background:var(--z-surface-soft, #f9fafb)">{{ __('অটো ব্যাকআপ বন্ধ আছে। হাতে ব্যাকআপ নেওয়া যাবে।') }}</div>
    @endif
    <div style="display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr))">
        <div style="{{ $stale ? $bad : $card }}">
            <div style="{{ $label }}">{{ __('শেষ সফল ব্যাকআপ') }}</div>
            <div style="{{ $big }}; {{ $stale ? 'color:#dc2626' : '' }}">{{ $last ? $last->started_at->diffForHumans() : __('এখনো হয়নি') }}</div>
            <div style="{{ $label }}">{{ $last ? $fmt($last->started_at).' · '.$size($last->size) : '' }}@if ($lastFull)<br>{{ __('পুরো') }}: {{ $fmt($lastFull->started_at) }}@endif</div>
        </div>
        <div style="{{ $card }}">
            <div style="{{ $label }}">{{ __('পরের পুরো ব্যাকআপ') }}</div>
            <div style="{{ $big }}">{{ $next ? $next->format('d M, h:i A') : __('বন্ধ') }}</div>
            <div style="{{ $label }}">{{ $s->db_every_hours ? __('ডাটাবেস প্রতি :h ঘণ্টায়', ['h' => $s->db_every_hours]) : __('মাঝের ডাটাবেস ব্যাকআপ বন্ধ') }}</div>
        </div>
        <div style="{{ $card }}">
            <div style="{{ $label }}">Google Drive</div>
            <div style="{{ $big }}">{{ $s->drive_total ? $size(max(0, $s->drive_total - $s->drive_used)).' '.__('খালি') : '—' }}</div>
            <div style="{{ $label }}">{{ $s->google_email ?: __('সংযোগ নেই') }}@if ($s->drive_total) · {{ $size($s->drive_used) }} / {{ $size($s->drive_total) }}@endif</div>
        </div>
        <div style="{{ $test?->status === 'failed' ? $bad : $card }}">
            <div style="{{ $label }}">{{ __('শেষ রিস্টোর টেস্ট') }}</div>
            <div style="{{ $big }}">{{ $test ? ($test->status === 'ok' ? '✅ '.__('ঠিক আছে') : '❌ '.__('ফেল')) : __('এখনো হয়নি') }}</div>
            <div style="{{ $label }}">{{ $test ? $fmt($test->started_at) : '' }}</div>
        </div>
    </div>
</x-filament-widgets::widget>
