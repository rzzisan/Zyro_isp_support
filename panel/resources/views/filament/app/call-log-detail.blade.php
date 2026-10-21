{{-- One bot-answered call: recording (both sides) and what each side said. --}}
<div style="display:flex;flex-direction:column;gap:12px">
    @if ($audio)
        <audio controls preload="none" src="{{ $audio }}" style="width:100%"></audio>
    @else
        <div style="opacity:.7">{{ __('রেকর্ডিং নেই') }}</div>
    @endif
    @if ($call->callback_reason)
        <div style="color:#d97706"><b>{{ __('কল ব্যাক') }}:</b> {{ $call->callback_reason }}</div>
    @endif
    @if ($call->bot_error)
        <div style="color:#dc2626;font-size:12px">{{ $call->bot_error }}</div>
    @endif
    <div style="display:flex;flex-direction:column;gap:6px;max-height:50vh;overflow:auto">
        @forelse ($call->transcript ?? [] as $t)
            <div style="align-self:{{ $t['who'] === 'bot' ? 'flex-start' : 'flex-end' }};max-width:85%;padding:6px 10px;border-radius:10px;
                background:{{ $t['who'] === 'bot' ? 'rgba(59,130,246,.12)' : 'rgba(16,185,129,.12)' }}">
                <div style="font-size:11px;opacity:.6">{{ $t['who'] === 'bot' ? __('বট') : __('কাস্টমার') }} · {{ gmdate('i:s', (int) ($t['at'] ?? 0)) }}</div>
                {{ $t['text'] }}
            </div>
        @empty
            <div style="opacity:.7">{{ __('লেখা রূপ নেই') }}</div>
        @endforelse
    </div>
</div>
