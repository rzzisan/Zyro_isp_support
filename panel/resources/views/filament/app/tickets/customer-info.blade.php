{{-- New-ticket form: the chosen customer, same look as the ticket details. Needs $info, $error. --}}
@php
    $c = $info['customer'] ?? [];
    $initial = mb_strtoupper(mb_substr(trim(preg_replace('/^(md|mohammad|mohammed|muhammad|mst|mosammat)\.?\s+/iu', '', trim((string) ($c['name'] ?? '')))) ?: '?', 0, 1));
    $disabled = ! empty($c['disabled']);
@endphp

@include('filament.app.tickets.partials.style')

<div class="ztd">
    @if ($info)
        <div class="ztd-hero">
            <div class="ztd-avatar">{{ $initial }}</div>
            <div style="min-width:0; position:relative; z-index:1">
                <div class="ztd-name">{{ $c['name'] ?? '' }}</div>
                <div class="ztd-pills">
                    <span class="ztd-pill">ID <b>{{ $c['customer_id'] ?? '' }}</b></span>
                    @if (! empty($c['username']))<span class="ztd-pill">PPPoE <b>{{ $c['username'] }}</b></span>@endif
                    @if (! empty($c['mobile']))<span class="ztd-pill">📞 <b>{{ $c['mobile'] }}</b></span>@endif
                    @if ($z = collect([$c['zone'] ?? null, $c['subzone'] ?? null, $c['box'] ?? null])->filter()->join(' / '))<span class="ztd-pill">📍 <b>{{ $z }}</b></span>@endif
                </div>
            </div>
            <div class="ztd-chips">
                @if (! empty($c['status']))
                    <span class="ztd-chip tone-{{ $disabled || strtolower((string) $c['status']) === 'left' ? 'bad' : 'good' }}">{{ $c['status'] }}{{ $disabled ? ' · লাইন বন্ধ' : '' }}</span>
                @endif
            </div>
        </div>
    @endif

    @include('filament.app.tickets.partials.line', ['part' => 'tiles', 'emptyText' => 'কাস্টমারের তথ্য পাওয়া যায়নি'])
    @include('filament.app.tickets.partials.line', ['part' => 'cards'])
</div>
