@if ($error)
    <div style="color:#dc2626">লাইভ তথ্য আনা যায়নি: {{ $error }}</div>
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
            'MikroTik (এখন)' => $mk ? (($mk['online'] ?? false) ? 'অনলাইন · '.($mk['uptime'] ?? '').' · '.($mk['router'] ?? '') : 'অফলাইন · '.($mk['router'] ?? '')) : 'রাউটার থেকে উত্তর আসেনি',
            'IP / MAC (রাউটার)' => $mk && ($mk['online'] ?? false) ? (($mk['address'] ?? '—').' · '.($mk['caller_id'] ?? '—')) : '—',
            'PPPoE (বিলিং)' => ($p['connectivity'] ?? '—'),
            'Uptime' => $p['uptime'] ?? '—',
            'শেষ logout' => $p['last_logout'] ?? '—',
            'IP' => $p['ip'] ?? '—',
            'ডাউনলোড' => $p['downloaded'] ?? '—',
            'ONU' => $o ? trim(($o['status'] ?? '—').' · '.($o['optical_power_dbm'] ?? '').' dBm · '.($o['olt'] ?? ''), ' ·') : 'তথ্য নেই',
            'ONU শেষ বন্ধ' => $o ? trim(($o['last_deregister'] ?? '').' '.($o['deregister_reason'] ?? '')) ?: '—' : '—',
            'লাইন (বিলিং)' => ! empty($cu['disabled']) ? 'বন্ধ' : 'চালু',
            'বিলের অবস্থা' => $b['payment_status'] ?? '—',
            'এই মাসে দেবেন / দিয়েছেন' => ($b['payable'] ?? '—').' / '.(($b['paid'] ?? '') ?: '০'),
            'বকেয়া' => ($b['due'] ?? '') ?: '০',
        ];
    @endphp
    <div style="display:grid; grid-template-columns: auto 1fr; gap:6px 16px; font-size:.9rem">
        @foreach ($rows as $k => $v)
            <div style="color:#6b7280">{{ $k }}</div>
            <div style="font-weight:600; {{ $k === 'MikroTik (এখন)' ? ($online ? 'color:#059669' : 'color:#dc2626') : '' }}">{{ $v }}</div>
        @endforeach
    </div>
    @if (! empty($live['payments']))
        <div style="margin-top:14px; font-weight:700; font-size:.85rem">সাম্প্রতিক পেমেন্ট</div>
        <table style="width:100%; font-size:.82rem; margin-top:4px; border-collapse:collapse">
            @foreach ($live['payments'] as $pay)
                <tr style="border-top:1px solid #e5e7eb">
                    <td style="padding:3px 0">{{ $pay['date'] ?? '' }}</td><td>{{ $pay['month'] ?? '' }}</td>
                    <td style="text-align:right">{{ $pay['paid'] ?? '' }} টাকা</td><td style="text-align:right; color:#6b7280">{{ $pay['method'] ?? '' }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endif
