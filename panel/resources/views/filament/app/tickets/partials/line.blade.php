{{-- The customer's line now: $part = 'tiles' (status tiles) or 'cards' (MikroTik + OLT/ONU). Needs $info, $error. --}}
@php
    $c = $info['customer'] ?? [];
    $mk = $info['mikrotik'] ?? null;
    $onu = $info['onu'] ?? null;
    $last = $info['last_seen'] ?? [];
    $online = (bool) ($mk['online'] ?? false);
    $onuOwn = ($onu['source'] ?? null) === 'olt';
    $onuOnline = strtolower((string) ($onu['OnuStatus'] ?? '')) === 'online';
    $rx = isset($onu['OpticalPower']) && $onu['OpticalPower'] !== '' && $onu['OpticalPower'] !== null ? (float) $onu['OpticalPower'] : null;
    // signal quality: -8 dBm (full) .. -30 dBm (empty)
    $rxTone = $rx === null ? 'mute' : ($rx < -27 ? 'bad' : ($rx < -24 ? 'warn' : 'good'));
    $rxPct = $rx === null ? 0 : (int) max(4, min(100, round(($rx + 30) / 22 * 100)));
    $due = (float) ($c['due'] ?? 0);
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone('Asia/Dhaka')->format('d M, g:i A') : null;
    $ago = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->locale('bn')->diffForHumans() : null;
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
    $conn = $mk === null ? 'mute' : ($online ? 'good' : 'bad');
@endphp
@if ($part === 'tiles')
    @if ($error)
        <div class="ztd-err">লাইনের তথ্য আনা যায়নি: {{ $error }}</div>
    @elseif ($info)
        {{-- At a glance --}}
        <div class="ztd-stats">
            <div class="ztd-stat tone-{{ $conn }}">
                <div class="ztd-label">সংযোগ</div>
                <div class="ztd-big"><span class="ztd-dot {{ $online ? 'live' : '' }}"></span>{{ $mk === null ? 'অজানা' : ($online ? 'অনলাইন' : 'অফলাইন') }}</div>
                <div class="ztd-sub">
                    @if ($online) Uptime {{ $mk['uptime'] ?? '—' }}
                    @elseif (! empty($last['seen_at'])) শেষ দেখা {{ $ago(\Illuminate\Support\Carbon::parse($last['seen_at'], 'UTC')) }}
                    @else {{ $mk === null ? 'রাউটার উত্তর দেয়নি' : 'MikroTik' }}
                    @endif
                </div>
            </div>
            <div class="ztd-stat tone-{{ $onu ? ($onuOnline ? $rxTone : 'bad') : 'mute' }}">
                <div class="ztd-label">ONU সিগন্যাল</div>
                <div class="ztd-big">{{ $rx === null ? ($onu ? ($onuOnline ? '—' : 'অফলাইন') : '—') : $rx.' dBm' }}</div>
                <div class="ztd-meter"><span style="width:{{ $onuOnline ? $rxPct : 0 }}%"></span></div>
            </div>
            <div class="ztd-stat tone-{{ $online && ($mk['download_bytes'] ?? null) !== null ? 'good' : 'mute' }}">
                <div class="ztd-label">এই সেশনে ব্যবহার</div>
                <div class="ztd-big" style="color:inherit">{{ $online ? $bytes($mk['download_bytes'] ?? null) : '—' }}</div>
                <div class="ztd-sub">{{ $online ? '↑ আপলোড '.$bytes($mk['upload_bytes'] ?? null) : 'অনলাইন হলে দেখাবে' }}</div>
            </div>
            <div class="ztd-stat tone-{{ $due > 0 || ! empty($c['disabled']) ? 'bad' : 'good' }}">
                <div class="ztd-label">বকেয়া</div>
                <div class="ztd-big">{{ $due > 0 ? $c['due'].' ৳' : 'নেই' }}</div>
                <div class="ztd-sub">{{ ! empty($c['disabled']) ? 'লাইন বন্ধ' : (isset($c['monthly_bill']) ? 'মাসিক '.$c['monthly_bill'].' ৳' : '') }}{{ ! empty($c['package']) ? ' · '.$c['package'] : '' }}</div>
            </div>
        </div>
    @else
        <div class="ztd-empty">{{ $emptyText ?? 'লাইনের তথ্য পাওয়া যায়নি' }}</div>
    @endif
@else
    @if ($info)
        <div class="ztd-grid2">
            {{-- MikroTik --}}
            <div class="ztd-card">
                <div class="ztd-head">
                    <div class="ztd-title"><span class="ztd-ico">📡</span>MikroTik</div>
                    <span class="ztd-chip tone-{{ $conn }}"><span class="ztd-dot {{ $online ? 'live' : '' }}"></span>{{ $mk['router'] ?? 'রাউটার' }}</span>
                </div>
                <div class="ztd-rows">
                    @if ($online)
                        <div class="ztd-row"><span>IP</span><span class="ztd-mono">{{ $val($mk['address'] ?? null) }}</span></div>
                        <div class="ztd-row"><span>Uptime</span><span>{{ $val($mk['uptime'] ?? null) }}</span></div>
                    @elseif (! empty($last['seen_at']))
                        <div class="ztd-row"><span>শেষ অনলাইন</span><span>{{ $dt(\Illuminate\Support\Carbon::parse($last['seen_at'], 'UTC')) }}</span></div>
                        @if (! empty($last['address']))<div class="ztd-row"><span>শেষ IP</span><span class="ztd-mono">{{ $last['address'] }}</span></div>@endif
                    @endif
                    <div class="ztd-row"><span>রাউটার MAC</span><span class="ztd-mono">{{ $val($info['mac'] ?? null) }}</span></div>
                </div>
                @if ($online && (($mk['download_bytes'] ?? null) !== null || ($mk['upload_bytes'] ?? null) !== null))
                    <div class="ztd-traffic">
                        <div><span class="ztd-label">↓ ডাউনলোড</span><b>{{ $bytes($mk['download_bytes'] ?? null) }}</b></div>
                        <div><span class="ztd-label">↑ আপলোড</span><b>{{ $bytes($mk['upload_bytes'] ?? null) }}</b></div>
                    </div>
                @endif
            </div>

            {{-- OLT / ONU --}}
            <div class="ztd-card">
                <div class="ztd-head">
                    <div class="ztd-title"><span class="ztd-ico">💡</span>OLT / ONU</div>
                    @if ($onu)
                        <span class="ztd-chip tone-{{ $onuOnline ? 'good' : 'bad' }}"><span class="ztd-dot {{ $onuOnline ? 'live' : '' }}"></span>{{ $onuOnline ? 'online' : ($onu['OnuStatus'] ?? 'offline') }}</span>
                    @endif
                </div>
                @if ($onu)
                    <div class="ztd-rows">
                        <div class="ztd-row"><span>OLT</span><span>{{ $val($onu['OLTName'] ?? null) }}</span></div>
                        <div class="ztd-row"><span>পোর্ট</span><span class="ztd-mono">{{ $val($onu['OLTPort'] ?? null) }}</span></div>
                        <div class="ztd-row"><span>Rx / Tx</span><span>{{ $rx === null ? '—' : $rx.' dBm' }}{{ isset($onu['TxPower']) && $onu['TxPower'] !== null ? ' / '.$onu['TxPower'].' dBm' : '' }}</span></div>
                        <div class="ztd-row"><span>দূরত্ব</span><span>{{ isset($onu['Distance']) && $onu['Distance'] !== '' && $onu['Distance'] !== null ? $onu['Distance'].' m' : '—' }}{{ isset($onu['Temperature']) && $onu['Temperature'] !== null ? ' · '.$onu['Temperature'].'°C' : '' }}</span></div>
                        <div class="ztd-row"><span>ONU MAC</span><span class="ztd-mono">{{ $val($onu['Onumacaddress'] ?? null) }}</span></div>
                        @if ($onuOwn && ! empty($onu['LastChange']))
                            <div class="ztd-row"><span>শেষ অবস্থা বদল</span><span>{{ $dt(\Illuminate\Support\Carbon::parse($onu['LastChange'], 'UTC')) }}</span></div>
                        @elseif (! $onuOwn && trim(($onu['LastDeregisterTime'] ?? '').' '.($onu['DeregisterReason'] ?? '')))
                            <div class="ztd-row"><span>শেষ বন্ধ</span><span>{{ trim(($onu['LastDeregisterTime'] ?? '').' '.($onu['DeregisterReason'] ?? '')) }}</span></div>
                        @endif
                    </div>
                    <div class="ztd-sub" style="margin-top:8px">{{ $onuOwn ? 'আমাদের OLT থেকে, এখন পড়া' : 'বিলিং থেকে' }}</div>
                @else
                    <div class="ztd-empty">{{ ($info['mac'] ?? null) ? 'এই MAC কোনো OLT-এ পাওয়া যায়নি' : 'রাউটারের MAC জানা নেই, তাই ONU খোঁজা যায়নি' }}</div>
                @endif
            </div>
        </div>
    @endif
@endif
