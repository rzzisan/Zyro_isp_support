@php
    /** @var \App\Models\BillingTicket $t */
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
    $stateTone = ['pending' => 'bad', 'processing' => 'warn', 'solved' => 'good'][$t->state] ?? 'mute';
    $prioTone = ['high' => 'bad', 'medium' => 'warn'][$t->priority] ?? 'mute';
    $conn = $mk === null ? 'mute' : ($online ? 'good' : 'bad');
    $initial = mb_strtoupper(mb_substr(trim(preg_replace('/^(md|mohammad|mohammed|muhammad|mst|mosammat)\.?\s+/iu', '', trim((string) $t->customer_name))) ?: '?', 0, 1));
    $steps = [
        ['খোলা', $dt($t->opened_at), $t->created_by, true],
        ['দায়িত্বে', $t->assigned_to ? 'assign হয়েছে' : null, $t->assigned_to, (bool) $t->assigned_to],
        ['সমাধান', $dt($t->solved_at), $t->solved_by, ! $t->isOpen()],
    ];
@endphp

<style>
    .ztd { --ok:#059669; --warn:#d97706; --bad:#dc2626; --mute:#6b7280; display:flex; flex-direction:column; gap:14px; font-size:.875rem; color:var(--z-fg, inherit) }
    .ztd * { box-sizing:border-box }
    .ztd .tone-good { --tone:var(--ok) } .ztd .tone-warn { --tone:var(--warn) } .ztd .tone-bad { --tone:var(--bad) } .ztd .tone-mute { --tone:var(--mute) }
    .ztd-hero { position:relative; overflow:hidden; display:flex; flex-wrap:wrap; align-items:center; gap:14px; padding:16px 18px; border-radius:16px;
        border:1px solid var(--z-border, #e5e7eb);
        background:linear-gradient(135deg, color-mix(in srgb, var(--z-accent, #0f7c7b) 14%, var(--z-surface, #fff)) 0%, var(--z-surface, #fff) 60%) }
    .ztd-hero::after { content:''; position:absolute; right:-40px; top:-40px; width:160px; height:160px; border-radius:50%;
        background:radial-gradient(circle, color-mix(in srgb, var(--z-accent, #0f7c7b) 22%, transparent), transparent 70%); pointer-events:none }
    .ztd-avatar { width:48px; height:48px; flex:none; border-radius:14px; display:grid; place-items:center; font-weight:800; font-size:1.25rem; color:#fff;
        background:linear-gradient(135deg, var(--z-accent, #0f7c7b), color-mix(in srgb, var(--z-accent, #0f7c7b) 55%, #22d3ee)); box-shadow:0 6px 16px -6px var(--z-accent, #0f7c7b) }
    .ztd-name { font-weight:800; font-size:1.1rem; line-height:1.2 }
    .ztd-pills { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px }
    .ztd-pill { font-size:.72rem; padding:2px 9px; border-radius:999px; background:var(--z-surface, #fff); border:1px solid var(--z-border, #e5e7eb); color:var(--z-muted, #6b7280) }
    .ztd-pill b { color:var(--z-fg, inherit); font-weight:600 }
    .ztd-chips { margin-left:auto; display:flex; flex-wrap:wrap; gap:6px; position:relative; z-index:1 }
    .ztd-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:999px; font-size:.72rem; font-weight:700; color:var(--tone);
        background:color-mix(in srgb, var(--tone) 12%, transparent); border:1px solid color-mix(in srgb, var(--tone) 30%, transparent) }
    .ztd-dot { width:8px; height:8px; border-radius:50%; background:var(--tone); flex:none }
    .ztd-dot.live { animation:ztd-pulse 1.8s ease-out infinite }
    @keyframes ztd-pulse { 0% { box-shadow:0 0 0 0 color-mix(in srgb, var(--tone) 60%, transparent) } 80%,100% { box-shadow:0 0 0 8px transparent } }

    .ztd-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px }
    .ztd-stat { position:relative; padding:12px 14px; border-radius:14px; background:var(--z-surface, #fff); border:1px solid var(--z-border, #e5e7eb);
        transition:transform .15s ease, box-shadow .15s ease }
    .ztd-stat:hover { transform:translateY(-2px); box-shadow:0 10px 24px -14px rgba(0,0,0,.35) }
    .ztd-stat::before { content:''; position:absolute; left:0; top:12px; bottom:12px; width:3px; border-radius:0 3px 3px 0; background:var(--tone) }
    .ztd-label { font-size:.68rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--z-muted, #6b7280) }
    .ztd-big { font-size:1.2rem; font-weight:800; margin-top:4px; display:flex; align-items:center; gap:8px; color:var(--tone) }
    .ztd-sub { font-size:.74rem; color:var(--z-muted, #6b7280); margin-top:2px }
    .ztd-meter { height:6px; border-radius:999px; background:color-mix(in srgb, var(--z-muted, #6b7280) 18%, transparent); margin-top:8px; overflow:hidden }
    .ztd-meter span { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg, color-mix(in srgb, var(--tone) 55%, transparent), var(--tone)) }

    .ztd-card { padding:14px 16px; border-radius:14px; background:var(--z-surface, #fff); border:1px solid var(--z-border, #e5e7eb) }
    .ztd-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px }
    .ztd-title { font-weight:800; font-size:.9rem; display:flex; align-items:center; gap:8px }
    .ztd-ico { width:28px; height:28px; border-radius:9px; display:grid; place-items:center; font-size:.9rem; background:color-mix(in srgb, var(--z-accent, #0f7c7b) 12%, transparent) }
    .ztd-desc { white-space:pre-line; line-height:1.55; padding:10px 12px; border-radius:10px; background:var(--z-surface-soft, #fafafa); border-left:3px solid var(--tone) }
    .ztd-note { margin-top:8px; font-size:.8rem; color:var(--z-muted, #6b7280) }

    .ztd-steps { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-top:12px }
    .ztd-step { position:relative; padding:8px 10px; border-radius:10px; border:1px dashed var(--z-border, #e5e7eb); opacity:.55 }
    .ztd-step.done { opacity:1; border-style:solid; background:color-mix(in srgb, var(--z-accent, #0f7c7b) 6%, transparent) }
    .ztd-step .k { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--z-muted, #6b7280); display:flex; align-items:center; gap:6px }
    .ztd-step .k::before { content:''; width:7px; height:7px; border-radius:50%; background:var(--z-border, #d1d5db) }
    .ztd-step.done .k::before { background:var(--z-accent, #0f7c7b) }
    .ztd-step .v { font-weight:600; font-size:.8rem; margin-top:3px; overflow-wrap:anywhere }
    .ztd-step .s { font-size:.72rem; color:var(--z-muted, #6b7280) }

    .ztd-grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px }
    .ztd-rows { display:flex; flex-direction:column }
    .ztd-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-top:1px solid color-mix(in srgb, var(--z-border, #e5e7eb) 70%, transparent) }
    .ztd-row:first-child { border-top:0 }
    .ztd-row > span:first-child { color:var(--z-muted, #6b7280) }
    .ztd-row > span:last-child { font-weight:600; text-align:right; overflow-wrap:anywhere }
    .ztd-mono { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.78rem }
    .ztd-traffic { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px }
    .ztd-traffic div { padding:8px 10px; border-radius:10px; background:var(--z-surface-soft, #fafafa) }
    .ztd-traffic b { display:block; font-size:1rem; font-weight:800 }
    .ztd-empty { font-size:.82rem; color:var(--z-muted, #6b7280); padding:8px 0 }
    .ztd-err { padding:10px 12px; border-radius:10px; color:var(--bad); background:color-mix(in srgb, var(--bad) 8%, transparent); font-size:.82rem }
    @media (max-width: 640px) { .ztd-steps { grid-template-columns:1fr } .ztd-chips { margin-left:0 } }
</style>

<div class="ztd">
    {{-- Hero --}}
    <div class="ztd-hero">
        <div class="ztd-avatar">{{ $initial }}</div>
        <div style="min-width:0; position:relative; z-index:1">
            <div class="ztd-name">{{ $t->customer_name }}</div>
            <div class="ztd-pills">
                <span class="ztd-pill">ID <b>{{ $t->customer_id }}</b></span>
                @if ($t->username)<span class="ztd-pill">PPPoE <b>{{ $t->username }}</b></span>@endif
                @if ($t->mobile)<span class="ztd-pill">📞 <b>{{ $t->mobile }}</b></span>@endif
                @if ($t->complainNumber() && $t->complainNumber() !== $t->mobile)<span class="ztd-pill">অভিযোগ <b>{{ $t->complainNumber() }}</b></span>@endif
                @if ($z = collect([$t->zone, $t->subzone, $t->box])->filter()->join(' / '))<span class="ztd-pill">📍 <b>{{ $z }}</b></span>@endif
            </div>
        </div>
        <div class="ztd-chips">
            <span class="ztd-chip tone-{{ $stateTone }}"><span class="ztd-dot {{ $t->isOpen() ? 'live' : '' }}"></span>{{ \App\Models\BillingTicket::STATES[$t->state] ?? $t->state }}</span>
            @if ($t->priority)
                <span class="ztd-chip tone-{{ $prioTone }}">{{ \App\Models\BillingTicket::PRIORITIES[$t->priority] ?? $t->priority }}</span>
            @endif
        </div>
    </div>

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
        <div class="ztd-empty">এই টিকিটে কাস্টমারের বিলিং ID নেই, তাই লাইনের তথ্য আনা যায়নি</div>
    @endif

    {{-- Problem --}}
    <div class="ztd-card tone-{{ $stateTone }}">
        <div class="ztd-head">
            <div class="ztd-title"><span class="ztd-ico">🛠️</span>{{ $t->category ?: 'সমস্যা' }}</div>
            <span class="ztd-sub">{{ $t->duration() ? ($t->isOpen() ? 'খোলা আছে ' : 'সময় লেগেছে ').$t->duration() : '' }}</span>
        </div>
        <div class="ztd-desc">{{ $val($t->description) }}</div>
        @if ($t->note)
            <div class="ztd-note">💬 {{ $t->note }}</div>
        @endif
        <div class="ztd-steps">
            @foreach ($steps as [$label, $when, $who, $done])
                <div class="ztd-step {{ $done ? 'done' : '' }}">
                    <div class="k">{{ $label }}</div>
                    <div class="v">{{ $val($who) }}</div>
                    <div class="s">{{ $when ?? '' }}</div>
                </div>
            @endforeach
        </div>
    </div>

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
</div>
