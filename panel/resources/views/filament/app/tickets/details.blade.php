@php
    /** @var \App\Models\BillingTicket $t */
    $c = $info['customer'] ?? [];
    $mk = $info['mikrotik'] ?? null;
    $onu = $info['onu'] ?? null;
    $last = $info['last_seen'] ?? [];
    $online = (bool) ($mk['online'] ?? false);
    $onuOwn = ($onu['source'] ?? null) === 'olt';
    $onuOnline = strtolower((string) ($onu['OnuStatus'] ?? '')) === 'online';
    $rx = isset($onu['OpticalPower']) && $onu['OpticalPower'] !== '' ? (float) $onu['OpticalPower'] : null;
    $rxColor = $rx === null ? '' : ($rx < -27 ? '#dc2626' : ($rx < -24 ? '#d97706' : '#059669'));
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone('Asia/Dhaka')->format('d M, g:i A') : null;
    $stateColor = ['pending' => '#dc2626', 'processing' => '#d97706', 'solved' => '#059669'][$t->state] ?? '#6b7280';
    $card = 'border:1px solid var(--z-border,#e5e7eb); border-radius:10px; padding:8px 10px; background:var(--z-surface-soft,#fafafa); min-width:0';
    $title = 'font-weight:700; font-size:.72rem; text-transform:uppercase; letter-spacing:.03em; color:var(--z-muted,#6b7280); margin-bottom:4px; display:flex; justify-content:space-between; gap:6px';
    $kv = 'display:grid; grid-template-columns:auto 1fr; gap:2px 10px; font-size:.8rem; line-height:1.35';
    $k = 'color:var(--z-muted,#6b7280); white-space:nowrap';
    $chip = fn ($text, $color) => '<span style="display:inline-block; padding:1px 8px; border-radius:999px; font-size:.72rem; font-weight:700; color:'.$color.'; background:color-mix(in srgb, '.$color.' 12%, transparent)">'.e($text).'</span>';
    $val = fn ($v) => $v === null || $v === '' ? '—' : $v;
    $bytes = function ($b) {
        if ($b === null) {
            return '—';
        }
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $u => $n) {
            if ($b >= $n) {
                return round($b / $n, $b >= 10 * $n ? 1 : 2).' '.$u;
            }
        }

        return $b.' B';
    };
@endphp

<div style="display:flex; flex-direction:column; gap:10px">
    {{-- Header: who, what, state --}}
    <div style="display:flex; flex-wrap:wrap; align-items:center; gap:6px 10px">
        <div style="font-weight:700; font-size:1rem">{{ $t->customer_name }}</div>
        <div style="font-size:.8rem; color:var(--z-muted,#6b7280)">ID {{ $t->customer_id }}@if ($t->username) · {{ $t->username }}@endif @if ($t->mobile) · {{ $t->mobile }}@endif</div>
        <div style="margin-left:auto; display:flex; gap:6px; flex-wrap:wrap">
            {!! $chip(\App\Models\BillingTicket::STATES[$t->state] ?? $t->state, $stateColor) !!}
            @if ($t->priority)
                {!! $chip(\App\Models\BillingTicket::PRIORITIES[$t->priority] ?? $t->priority, ['high' => '#dc2626', 'medium' => '#d97706'][$t->priority] ?? '#6b7280') !!}
            @endif
            @if ($info)
                {!! $chip($mk === null ? 'রাউটার উত্তর দেয়নি' : ($online ? 'MikroTik অনলাইন' : 'MikroTik অফলাইন'), $mk === null ? '#6b7280' : ($online ? '#059669' : '#dc2626')) !!}
            @endif
        </div>
    </div>

    {{-- Problem --}}
    <div style="{{ $card }}">
        <div style="{{ $title }}"><span>#{{ $t->complain_id }} · {{ $t->category }}</span><span style="text-transform:none; letter-spacing:0">{{ $dt($t->opened_at) }}@if ($t->duration()) · {{ $t->duration() }}@endif</span></div>
        <div style="font-size:.85rem; white-space:pre-line">{{ $val($t->description) }}</div>
        @if ($t->note)
            <div style="font-size:.8rem; margin-top:4px; color:var(--z-muted,#6b7280)">মন্তব্য: {{ $t->note }}</div>
        @endif
        <div style="{{ $kv }}; margin-top:6px; grid-template-columns:auto 1fr auto 1fr">
            <span style="{{ $k }}">দায়িত্বে</span><span style="font-weight:600">{{ $val($t->assigned_to) }}</span>
            <span style="{{ $k }}">খুলেছেন</span><span>{{ $val($t->created_by) }}</span>
            <span style="{{ $k }}">Zone</span><span>{{ $val(collect([$t->zone, $t->subzone, $t->box])->filter()->join(' / ')) }}</span>
            @if ($t->complainNumber() && $t->complainNumber() !== $t->mobile)
                <span style="{{ $k }}">অভিযোগের নম্বর</span><span>{{ $t->complainNumber() }}</span>
            @elseif ($t->solved_by)
                <span style="{{ $k }}">সমাধান</span><span>{{ $t->solved_by }} · {{ $dt($t->solved_at) }}</span>
            @endif
        </div>
    </div>

    {{-- Live line --}}
    @if ($error)
        <div style="color:#dc2626; font-size:.82rem">লাইনের তথ্য আনা যায়নি: {{ $error }}</div>
    @elseif (! $info)
        <div style="font-size:.82rem; color:var(--z-muted,#6b7280)">এই টিকিটে কাস্টমারের বিলিং ID নেই, তাই লাইনের তথ্য আনা যায়নি</div>
    @else
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:8px">
            <div style="{{ $card }}">
                <div style="{{ $title }}"><span>MikroTik</span><span>{{ $mk['router'] ?? '' }}</span></div>
                <div style="{{ $kv }}">
                    <span style="{{ $k }}">অবস্থা</span><span style="font-weight:700; color:{{ $online ? '#059669' : '#dc2626' }}">{{ $mk === null ? 'উত্তর আসেনি' : ($online ? 'অনলাইন' : 'অফলাইন') }}</span>
                    @if ($online)
                        <span style="{{ $k }}">Uptime</span><span>{{ $val($mk['uptime'] ?? null) }}</span>
                        <span style="{{ $k }}">IP</span><span>{{ $val($mk['address'] ?? null) }}</span>
                        @if (($mk['download_bytes'] ?? null) !== null || ($mk['upload_bytes'] ?? null) !== null)
                            <span style="{{ $k }}">ডাউনলোড</span><span style="font-weight:600">{{ $bytes($mk['download_bytes'] ?? null) }}</span>
                            <span style="{{ $k }}">আপলোড</span><span style="font-weight:600">{{ $bytes($mk['upload_bytes'] ?? null) }}</span>
                        @endif
                    @elseif (! empty($last['seen_at']))
                        <span style="{{ $k }}">শেষ অনলাইন</span><span>{{ $dt(\Illuminate\Support\Carbon::parse($last['seen_at'], 'UTC')) }}</span>
                    @endif
                    <span style="{{ $k }}">MAC</span><span style="font-family:monospace; font-size:.75rem">{{ $val($info['mac'] ?? null) }}</span>
                </div>
            </div>
            <div style="{{ $card }}">
                <div style="{{ $title }}"><span>OLT / ONU</span><span style="text-transform:none; letter-spacing:0">{{ $onu ? ($onuOwn ? 'OLT থেকে, এখন' : 'বিলিং থেকে') : '' }}</span></div>
                @if ($onu)
                    <div style="{{ $kv }}">
                        <span style="{{ $k }}">ONU</span><span style="font-weight:700; color:{{ $onuOnline ? '#059669' : '#dc2626' }}">{{ $val($onu['OnuStatus'] ?? null) }}</span>
                        <span style="{{ $k }}">OLT</span><span>{{ $val(trim(($onu['OLTName'] ?? '').(! empty($onu['OLTPort']) ? ' · '.$onu['OLTPort'] : ''))) }}</span>
                        <span style="{{ $k }}">Rx</span><span style="font-weight:600; color:{{ $rxColor }}">{{ $rx === null ? '—' : $rx.' dBm' }}@if (isset($onu['TxPower']) && $onu['TxPower'] !== null) <span style="color:var(--z-muted,#6b7280); font-weight:400">· Tx {{ $onu['TxPower'] }}</span>@endif</span>
                        <span style="{{ $k }}">দূরত্ব</span><span>{{ isset($onu['Distance']) && $onu['Distance'] !== '' && $onu['Distance'] !== null ? $onu['Distance'].' m' : '—' }}@if (isset($onu['Temperature']) && $onu['Temperature'] !== null) <span style="color:var(--z-muted,#6b7280)">· {{ $onu['Temperature'] }}°C</span>@endif</span>
                        @if ($onuOwn && ! empty($onu['LastChange']))
                            <span style="{{ $k }}">শেষ বদল</span><span>{{ $dt(\Illuminate\Support\Carbon::parse($onu['LastChange'], 'UTC')) }}</span>
                        @elseif (! $onuOwn && trim(($onu['LastDeregisterTime'] ?? '').' '.($onu['DeregisterReason'] ?? '')))
                            <span style="{{ $k }}">শেষ বন্ধ</span><span>{{ trim(($onu['LastDeregisterTime'] ?? '').' '.($onu['DeregisterReason'] ?? '')) }}</span>
                        @endif
                    </div>
                @else
                    <div style="font-size:.8rem; color:var(--z-muted,#6b7280)">{{ ($info['mac'] ?? null) ? 'এই MAC কোনো OLT-এ পাওয়া যায়নি' : 'MAC জানা নেই, তাই ONU খোঁজা যায়নি' }}</div>
                @endif
            </div>
            <div style="{{ $card }}">
                <div style="{{ $title }}"><span>বিল</span><span>{{ $c['package'] ?? '' }}</span></div>
                <div style="{{ $kv }}">
                    <span style="{{ $k }}">মাসিক</span><span>{{ isset($c['monthly_bill']) ? $c['monthly_bill'].' টাকা' : '—' }}</span>
                    <span style="{{ $k }}">বকেয়া</span><span style="font-weight:700; {{ ($c['due'] ?? 0) > 0 ? 'color:#dc2626' : '' }}">{{ ($c['due'] ?? null) ? $c['due'].' টাকা' : '০' }}</span>
                    <span style="{{ $k }}">Status</span><span style="{{ ! empty($c['disabled']) ? 'color:#dc2626; font-weight:700' : '' }}">{{ $val($c['status'] ?? null) }}{{ ! empty($c['disabled']) ? ' · লাইন বন্ধ' : '' }}</span>
                </div>
            </div>
        </div>
    @endif
</div>
