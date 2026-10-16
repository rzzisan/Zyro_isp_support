{{-- Inbox details column: the customer's line now and their tickets (CustomerLine widget). --}}
@php
    $mk = $info['mikrotik'] ?? null;
    $onu = $info['onu'] ?? null;
    $last = $info['last_seen'] ?? [];
    $c = $info['customer'] ?? [];
    $online = (bool) ($mk['online'] ?? false);
    // connected on a block profile (billing disabled the line): no internet
    $blocked = (bool) ($mk['blocked'] ?? false);
    $conn = $mk === null ? 'mute' : ($online ? 'good' : ($blocked ? 'warn' : 'bad'));
    $onuOnline = strtolower((string) ($onu['OnuStatus'] ?? '')) === 'online';
    $rx = isset($onu['OpticalPower']) && $onu['OpticalPower'] !== '' && $onu['OpticalPower'] !== null ? (float) $onu['OpticalPower'] : null;
    $onuTone = ! $onu ? 'mute' : (! $onuOnline ? 'bad' : ($rx === null ? 'good' : ($rx < -27 ? 'bad' : ($rx < -24 ? 'warn' : 'good'))));
    $due = (float) ($c['due'] ?? 0);
@endphp
<div class="zi-line">
    @if ($customer)
        <div class="zi-info-head zi-line-head">
            <span>{{ __('লাইনের অবস্থা') }}</span>
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" class="zi-line-btn" title="{{ __('আবার দেখুন') }}">
                <x-filament::icon icon="heroicon-o-arrow-path" class="zi-line-ico" wire:loading.class="zi-spin" wire:target="refresh" />
            </button>
        </div>
        @if ($error)
            <div class="zi-err">{{ __('লাইনের তথ্য আনা যায়নি: :error', ['error' => $error]) }}</div>
        @elseif ($info)
            <div class="zi-st tone-{{ $conn }}">
                <span class="zi-st-dot"></span>
                <div class="zi-st-body">
                    <b>MikroTik · {{ $mk === null ? __('অজানা') : ($online ? __('অনলাইন') : ($blocked ? __('ব্লক') : __('অফলাইন'))) }}</b>
                    <small>
                        @if ($online) Uptime {{ $mk['uptime'] ?? '—' }}@if (! empty($mk['address'])) · {{ $mk['address'] }}@endif
                        @elseif ($blocked) {{ __('ইন্টারনেট নেই') }} · {{ $mk['profile'] ?? '' }}
                        @elseif (! empty($last['seen_at'])) {{ __('শেষ দেখা') }} {{ \Illuminate\Support\Carbon::parse($last['seen_at'], 'UTC')->locale(app()->getLocale())->diffForHumans() }}
                        @else {{ $mk === null ? __('রাউটার উত্তর দেয়নি') : ($mk['router'] ?? '') }}
                        @endif
                    </small>
                </div>
            </div>
            <div class="zi-st tone-{{ $onuTone }}">
                <span class="zi-st-dot"></span>
                <div class="zi-st-body">
                    <b>ONU · {{ ! $onu ? __('অজানা') : ($onuOnline ? ($rx === null ? __('অনলাইন') : $rx.' dBm') : (($onu['OnuStatus'] ?? '') ?: __('অফলাইন'))) }}</b>
                    <small>
                        @if ($onu) {{ collect([$onu['OLTName'] ?? null, $onu['OLTPort'] ?? null])->filter()->join(' · ') ?: '—' }}
                        @else {{ ($info['mac'] ?? null) ? __('এই MAC কোনো OLT-এ পাওয়া যায়নি') : __('রাউটারের MAC জানা নেই, তাই ONU খোঁজা যায়নি') }}
                        @endif
                    </small>
                </div>
            </div>
            <div class="zi-st tone-{{ $due > 0 || ! empty($c['disabled']) ? 'bad' : 'good' }}">
                <span class="zi-st-dot"></span>
                <div class="zi-st-body">
                    <b>{{ __('বকেয়া') }} · {{ $due > 0 ? $c['due'].' ৳' : __('নেই') }}</b>
                    <small>{{ ! empty($c['disabled']) ? __('লাইন বন্ধ') : (isset($c['monthly_bill']) ? __('মাসিক :amount ৳', ['amount' => $c['monthly_bill']]) : '') }}{{ ! empty($c['package']) ? ' · '.$c['package'] : '' }}</small>
                </div>
            </div>
        @else
            <div class="zi-muted">{{ __('লাইনের তথ্য পাওয়া যায়নি') }}</div>
        @endif
        <a class="zi-line-link" href="{{ \App\Filament\App\Resources\Customers\CustomerResource::getUrl('view', ['record' => $customer]) }}" wire:navigate>{{ __('কাস্টমারের পুরো তথ্য') }} →</a>
    @endif

    <div class="zi-info-head">{{ __('বিলিংয়ের টিকিট') }}</div>
    @forelse ($tickets as $t)
        <div class="zi-ticket" wire:key="t{{ $t->id }}">
            <span class="zi-tstate {{ $t->state }}">{{ __(\App\Models\BillingTicket::STATES[$t->state] ?? $t->state) }}</span>
            <b>#{{ $t->complain_id }}</b> {{ $t->category }}
            <small>{{ $t->opened_at?->timezone('Asia/Dhaka')->format('d M, g:i A') }}
                @if ($t->solved_by) · {{ $t->solved_by }} @elseif ($t->assigned_to) · {{ $t->assigned_to }} @endif</small>
            @if ($t->isOpen())
                <div class="zi-ticket-act">{{ ($this->solveAction)(['ticket' => $t->id]) }}</div>
            @endif
        </div>
    @empty
        <div class="zi-muted">{{ $customerId !== '' ? __('কোনো টিকিট নেই') : __('কাস্টমার চেনা গেলে টিকিট দেখাবে') }}</div>
    @endforelse

    <x-filament-actions::modals />
</div>
