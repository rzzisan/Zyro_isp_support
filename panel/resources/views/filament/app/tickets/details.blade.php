@php
    /** @var \App\Models\BillingTicket $t */
    $dt = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->timezone('Asia/Dhaka')->format('d M, g:i A') : null;
    $val = fn ($v) => $v === null || $v === '' ? '—' : $v;
    $stateTone = ['pending' => 'bad', 'processing' => 'warn', 'solved' => 'good'][$t->state] ?? 'mute';
    $prioTone = ['high' => 'bad', 'medium' => 'warn'][$t->priority] ?? 'mute';
    $initial = mb_strtoupper(mb_substr(trim(preg_replace('/^(md|mohammad|mohammed|muhammad|mst|mosammat)\.?\s+/iu', '', trim((string) $t->customer_name))) ?: '?', 0, 1));
    $steps = [
        [__('খোলা'), $dt($t->opened_at), $t->created_by, true],
        [__('দায়িত্বে'), $t->assigned_to ? __('assign হয়েছে') : null, $t->assigned_to, (bool) $t->assigned_to],
        [__('সমাধান'), $dt($t->solved_at), $t->solved_by, ! $t->isOpen()],
    ];
@endphp

@include('filament.app.tickets.partials.style')


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
                @if ($t->complainNumber() && $t->complainNumber() !== $t->mobile)<span class="ztd-pill">{{ __('অভিযোগ') }} <b>{{ $t->complainNumber() }}</b></span>@endif
                @if ($z = collect([$t->zone, $t->subzone, $t->box])->filter()->join(' / '))<span class="ztd-pill">📍 <b>{{ $z }}</b></span>@endif
            </div>
        </div>
        <div class="ztd-chips">
            <span class="ztd-chip tone-{{ $stateTone }}"><span class="ztd-dot {{ $t->isOpen() ? 'live' : '' }}"></span>{{ __(\App\Models\BillingTicket::STATES[$t->state] ?? $t->state) }}</span>
            @if ($t->priority)
                <span class="ztd-chip tone-{{ $prioTone }}">{{ __(\App\Models\BillingTicket::PRIORITIES[$t->priority] ?? $t->priority) }}</span>
            @endif
        </div>
    </div>

    @include('filament.app.tickets.partials.line', ['part' => 'tiles', 'emptyText' => __('এই টিকিটে কাস্টমারের বিলিং ID নেই, তাই লাইনের তথ্য আনা যায়নি')])

    {{-- Problem --}}
    <div class="ztd-card tone-{{ $stateTone }}">
        <div class="ztd-head">
            <div class="ztd-title"><span class="ztd-ico">🛠️</span>{{ $t->category ?: __('সমস্যা') }}</div>
            <span class="ztd-sub">{{ $t->duration() ? ($t->isOpen() ? __('খোলা আছে :time', ['time' => $t->duration()]) : __('সময় লেগেছে :time', ['time' => $t->duration()])) : '' }}</span>
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

    {{-- What was done on it from the Desk (ticket_events) --}}
    @php($events = $t->events)
    @if ($events->isNotEmpty() || $t->isDesk())
        <div class="ztd-card">
            <div class="ztd-head">
                <div class="ztd-title"><span class="ztd-ico">🕘</span>{{ __('ইতিহাস') }}</div>
                <span class="ztd-sub">{{ $t->isDesk() ? __('Desk-এর টিকিট') : __('বিলিংয়ের টিকিট') }}</span>
            </div>
            @forelse ($events as $e)
                @php($d = $e->detail ?? [])
                <div class="ztd-note">
                    <b>{{ $dt($e->created_at) }}</b> · {{ __(\App\Models\TicketEvent::KINDS[$e->kind] ?? $e->kind) }}
                    @if ($e->actor) · {{ $e->actor }}@endif
                    @if (! empty($d['employees'])) · {{ implode(', ', (array) $d['employees']) }}@endif
                    @if (! empty($d['category'])) · {{ $d['category'] }}@endif
                    @if (! empty($d['remark'])) · “{{ $d['remark'] }}”@endif
                </div>
            @empty
                <div class="ztd-note">{{ __('এখনো কিছু নেই') }}</div>
            @endforelse
        </div>
    @endif

    @include('filament.app.tickets.partials.line', ['part' => 'cards'])
</div>
