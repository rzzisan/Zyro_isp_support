@if ($error)
    <div style="color:#dc2626">{{ __('লাইভ তথ্য আনা যায়নি: :error', ['error' => $error]) }}</div>
@else
    @php
        $p = $live['pppoe'] ?? [];
        $o = $live['onu'] ?? null;
        $b = $live['bill'] ?? [];
        $cu = $live['customer'] ?? [];
        $online = strtolower((string) ($p['connectivity'] ?? '')) === 'connected' || str_contains(strtolower((string) ($p['connectivity'] ?? '')), 'online');
        $mk = $live['mikrotik'] ?? null;
        if ($mk) { $online = (bool) ($mk['online'] ?? false); }
        $rows = [
            __('MikroTik (এখন)') => $mk ? (($mk['online'] ?? false) ? __('অনলাইন · ').($mk['uptime'] ?? '').' · '.($mk['router'] ?? '') : __('অফলাইন · ').($mk['router'] ?? '')) : __('রাউটার থেকে উত্তর আসেনি'),
            __('IP / MAC (রাউটার)') => $mk && ($mk['online'] ?? false) ? (($mk['address'] ?? '—').' · '.($mk['caller_id'] ?? '—')) : '—',
            __('PPPoE (বিলিং)') => ($p['connectivity'] ?? '—'),
            'Uptime' => $p['uptime'] ?? '—',
            __('শেষ logout') => $p['last_logout'] ?? '—',
            'IP' => $p['ip'] ?? '—',
            __('ডাউনলোড') => $p['downloaded'] ?? '—',
            'ONU' => $o ? trim(($o['status'] ?? '—').' · '.($o['optical_power_dbm'] ?? '').' dBm · '.($o['olt'] ?? ''), ' ·') : __('তথ্য নেই'),
            __('ONU শেষ বন্ধ') => $o ? trim(($o['last_deregister'] ?? '').' '.($o['deregister_reason'] ?? '')) ?: '—' : '—',
            __('লাইন (বিলিং)') => ! empty($cu['disabled']) ? __('বন্ধ') : __('চালু'),
            __('বিলের অবস্থা') => $b['payment_status'] ?? '—',
            __('এই মাসে দেবেন / দিয়েছেন') => ($b['payable'] ?? '—').' / '.(($b['paid'] ?? '') ?: __('০')),
            __('বকেয়া') => ($b['due'] ?? '') ?: __('০'),
        ];
    @endphp
    <div style="display:grid; grid-template-columns: auto 1fr; gap:6px 16px; font-size:.9rem">
        @foreach ($rows as $k => $v)
            <div style="color:#6b7280">{{ $k }}</div>
            <div style="font-weight:600; {{ $k === __('MikroTik (এখন)') ? ($online ? 'color:#059669' : 'color:#dc2626') : '' }}">{{ $v }}</div>
        @endforeach
    </div>
    @if (! empty($live['payments']))
        <div style="margin-top:14px; font-weight:700; font-size:.85rem">{{ __('সাম্প্রতিক পেমেন্ট') }}</div>
        <table style="width:100%; font-size:.82rem; margin-top:4px; border-collapse:collapse">
            @foreach ($live['payments'] as $pay)
                <tr style="border-top:1px solid #e5e7eb">
                    <td style="padding:3px 0">{{ $pay['date'] ?? '' }}</td><td>{{ $pay['month'] ?? '' }}</td>
                    <td style="text-align:right">{{ $pay['paid'] ?? '' }} {{ __('টাকা') }}</td><td style="text-align:right; color:#6b7280">{{ $pay['method'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endif
