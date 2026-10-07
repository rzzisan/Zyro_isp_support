@php
    $c = $info['customer'] ?? [];
    $mk = $info['mikrotik'] ?? null;
    $onu = $info['onu'] ?? null;
    $last = $info['last_seen'] ?? [];
    $online = (bool) ($mk['online'] ?? false);
    $box = 'border:1px solid var(--z-border, #e5e7eb); border-radius:12px; padding:10px 12px; background:var(--z-surface-soft, #fafafa)';
    $row = fn ($k, $v, $style = '') => '<div style="color:var(--z-muted,#6b7280)">'.e($k).'</div><div style="font-weight:600;'.$style.'">'.e($v === null || $v === '' ? '—' : $v).'</div>';
    $grid = 'display:grid; grid-template-columns:auto 1fr; gap:3px 12px; font-size:.82rem';
@endphp
@if ($error)
    <div style="color:#dc2626; font-size:.85rem">তথ্য আনা যায়নি: {{ $error }}</div>
@elseif ($info)
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(230px, 1fr)); gap:10px">
        <div style="{{ $box }}">
            <div style="font-weight:700; font-size:.8rem; margin-bottom:6px">কাস্টমার ও বিল</div>
            <div style="{{ $grid }}">
                {!! $row('নাম', ($c['name'] ?? '').' (ID '.($c['customer_id'] ?? '').')') !!}
                {!! $row('PPPoE', $c['username'] ?? null) !!}
                {!! $row('মোবাইল', $c['mobile'] ?? null) !!}
                {!! $row('Zone', collect([$c['zone'] ?? null, $c['subzone'] ?? null, $c['box'] ?? null])->filter()->join(' / ')) !!}
                {!! $row('প্যাকেজ', $c['package'] ?? null) !!}
                {!! $row('মাসিক বিল', isset($c['monthly_bill']) ? $c['monthly_bill'].' টাকা' : null) !!}
                {!! $row('বকেয়া', ($c['due'] ?? null) ? $c['due'].' টাকা' : '০', ($c['due'] ?? 0) > 0 ? 'color:#dc2626' : '') !!}
                {!! $row('Billing Status', ($c['status'] ?? '').(! empty($c['disabled']) ? ' · লাইন বন্ধ' : ''), ! empty($c['disabled']) ? 'color:#dc2626' : '') !!}
            </div>
        </div>
        <div style="{{ $box }}">
            <div style="font-weight:700; font-size:.8rem; margin-bottom:6px">সংযোগ (MikroTik, এখন)</div>
            <div style="{{ $grid }}">
                {!! $row('অবস্থা', $mk === null ? 'রাউটার থেকে উত্তর আসেনি' : ($online ? 'অনলাইন' : 'অফলাইন'), $online ? 'color:#059669' : 'color:#dc2626') !!}
                {!! $row('রাউটার', $mk['router'] ?? null) !!}
                {!! $row('Uptime', $online ? ($mk['uptime'] ?? null) : null) !!}
                {!! $row('IP', $online ? ($mk['address'] ?? null) : null) !!}
                {!! $row('MAC', $info['mac'] ?? null) !!}
                @if (! $online && ! empty($last['seen_at']))
                    {!! $row('শেষ অনলাইন', \Illuminate\Support\Carbon::parse($last['seen_at'], 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A')) !!}
                @endif
            </div>
        </div>
        <div style="{{ $box }}">
            <div style="font-weight:700; font-size:.8rem; margin-bottom:6px">OLT / ONU (বিলিং থেকে)</div>
            @if ($onu)
                @php($onuOnline = strtolower((string) ($onu['OnuStatus'] ?? '')) === 'online')
                <div style="{{ $grid }}">
                    {!! $row('OLT', ($onu['OLTName'] ?? '').(! empty($onu['OLTPort']) ? ' · '.$onu['OLTPort'] : '')) !!}
                    {!! $row('ONU', $onu['OnuStatus'] ?? null, $onuOnline ? 'color:#059669' : 'color:#dc2626') !!}
                    {!! $row('Optical Power', isset($onu['OpticalPower']) ? $onu['OpticalPower'].' dBm' : null) !!}
                    {!! $row('Distance', isset($onu['Distance']) && $onu['Distance'] !== '' ? $onu['Distance'].' m' : null) !!}
                    {!! $row('ONU MAC', $onu['Onumacaddress'] ?? null) !!}
                    {!! $row('শেষ বন্ধ', trim(($onu['LastDeregisterTime'] ?? '').' '.($onu['DeregisterReason'] ?? ''))) !!}
                </div>
            @else
                <div style="font-size:.8rem; color:var(--z-muted,#6b7280)">{{ ($info['mac'] ?? null) ? 'এই MAC কোনো OLT-এ পাওয়া যায়নি' : 'কাস্টমারের MAC জানা নেই, তাই ONU খোঁজা যায়নি' }}</div>
            @endif
        </div>
    </div>
@endif
