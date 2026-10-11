{{-- Customer page: identity, connection and bill as cards, same look as the live MikroTik / OLT cards. --}}
@php
    /** @var \App\Models\BillingCustomer $r */
    $r = $getRecord();
    $val = fn ($v) => $v === null || $v === '' ? '—' : $v;
    $date = fn ($d) => $d ? $d->format('d M Y') : '—';
    $tk = fn ($v) => $v === null ? '—' : number_format((float) $v, 2).' ৳';
    $due = (float) ($r->due ?? 0);
    $address = collect([$r->house, $r->road, $r->address, $r->thana, $r->district])->filter()->join(', ');
    $statusTone = $r->gone_at || $r->is_left ? 'warn' : ($r->disabled ? 'bad' : (strtolower((string) $r->status) === 'active' ? 'good' : 'mute'));
    $statusText = $r->gone_at ? 'বিলিংয়ে নেই' : ($r->is_left ? 'Left'.($r->left_on ? ' · '.$r->left_on->format('d M Y') : '') : (($r->status ?: '—').($r->disabled ? ' · লাইন বন্ধ' : '')));
    $changes = \Illuminate\Support\Facades\DB::table('billing_customer_changes')
        ->where('company_id', $r->company_id)->where('header_id', $r->header_id)->orderByDesc('seen_at')->limit(5)->get()
        ->map(fn ($c) => ($c->change === 'left' ? 'Left হয়েছেন' : 'আবার Active')
            .' · '.\Illuminate\Support\Carbon::parse($c->seen_at, 'UTC')->timezone('Asia/Dhaka')->format('d M Y'))
        ->join(', ');
@endphp

@include('filament.app.tickets.partials.style')

<div class="ztd">
    <div class="ztd-grid2" style="grid-template-columns:repeat(auto-fit, minmax(300px, 1fr))">
        {{-- পরিচয় --}}
        <div class="ztd-card">
            <div class="ztd-head">
                <div class="ztd-title"><span class="ztd-ico">👤</span>পরিচয়</div>
                <span class="ztd-chip tone-mute">ID {{ $r->customer_id }}</span>
            </div>
            <div class="ztd-rows">
                <div class="ztd-row"><span>নাম</span><span>{{ $val($r->name) }}</span></div>
                <div class="ztd-row"><span>PPPoE ID</span><span class="ztd-mono">{{ $val($r->username) }}</span></div>
                <div class="ztd-row"><span>মোবাইল</span><span class="ztd-mono">{{ $val($r->mobile) }}</span></div>
                @if ($r->email)<div class="ztd-row"><span>ইমেইল</span><span>{{ $r->email }}</span></div>@endif
                @if ($r->nid)<div class="ztd-row"><span>NID</span><span class="ztd-mono">{{ $r->nid }}</span></div>@endif
                <div class="ztd-row"><span>ঠিকানা</span><span>{{ $val($address) }}</span></div>
                <div class="ztd-row"><span>যোগ দিয়েছেন</span><span>{{ $date($r->joined_on) }}</span></div>
                <div class="ztd-row"><span>দায়িত্বে</span><span>{{ $val($r->assigned_employee) }}</span></div>
            </div>
        </div>

        {{-- সংযোগ --}}
        <div class="ztd-card">
            <div class="ztd-head">
                <div class="ztd-title"><span class="ztd-ico">🔌</span>সংযোগ</div>
                @if ($r->package)<span class="ztd-chip tone-good">{{ $r->package }}</span>@endif
            </div>
            <div class="ztd-rows">
                <div class="ztd-row"><span>Zone / Subzone / Box</span><span>{{ $val(collect([$r->zone, $r->subzone, $r->box])->filter()->join(' / ')) }}</span></div>
                <div class="ztd-row"><span>প্যাকেজ</span><span>{{ $val($r->package) }}</span></div>
                <div class="ztd-row"><span>স্পিড</span><span>{{ $val($r->speed) }}</span></div>
                <div class="ztd-row"><span>সংযোগের ধরন</span><span>{{ $val($r->connection_type) }}</span></div>
                <div class="ztd-row"><span>Protocol / Server</span><span class="ztd-mono">{{ $val(trim($r->protocol.' · '.$r->server, ' ·')) }}</span></div>
                <div class="ztd-row"><span>কাস্টমারের ধরন</span><span>{{ $val($r->customer_type) }}</span></div>
            </div>
        </div>

        {{-- বিল --}}
        <div class="ztd-card">
            <div class="ztd-head">
                <div class="ztd-title"><span class="ztd-ico">💳</span>বিল</div>
                <span class="ztd-chip tone-{{ $statusTone }}"><span class="ztd-dot {{ $statusTone === 'good' ? 'live' : '' }}"></span>{{ $statusText }}</span>
            </div>
            <div class="ztd-traffic" style="margin:0 0 10px">
                <div class="tone-{{ $due > 0 ? 'bad' : 'good' }}"><span class="ztd-label">বকেয়া</span><b style="color:var(--tone)">{{ $due > 0 ? $tk($r->due) : 'নেই' }}</b></div>
                <div><span class="ztd-label">মাসিক বিল</span><b>{{ $tk($r->monthly_bill) }}</b></div>
            </div>
            <div class="ztd-rows">
                <div class="ztd-row"><span>এই মাসে দিয়েছেন</span><span>{{ $tk($r->paid) }}</span></div>
                <div class="ztd-row"><span>বিলের শেষ তারিখ</span><span>{{ $r->bill_day ? $r->bill_day.' তারিখ' : '—' }}</span></div>
                <div class="ztd-row"><span>শেষ পেমেন্ট</span><span>{{ $date($r->last_payment_date) }}</span></div>
                @if ($changes)<div class="ztd-row"><span>Active / Left বদল</span><span>{{ $changes }}</span></div>@endif
            </div>
            <div class="ztd-sub" style="margin-top:8px">শেষ sync {{ $r->synced_at?->locale('bn')->diffForHumans() ?? '—' }}</div>
        </div>
    </div>
</div>
