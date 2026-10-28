{{-- One bot-answered call: recording (both sides) and what each side said. --}}
<div style="display:flex;flex-direction:column;gap:12px">
    @if ($audio)
        <audio controls preload="none" src="{{ $audio }}" style="width:100%"></audio>
    @else
        <div style="opacity:.7">{{ __('রেকর্ডিং নেই') }}</div>
    @endif
    @if ($call->outcome_text)
        <div><b>{{ __('ফলাফল') }}:</b> {{ $call->outcome_text }}</div>
    @endif
    @if (! empty($call->actions))
        <div>
            <div style="font-weight:600;margin-bottom:4px">{{ __('যা করা হয়েছে') }}</div>
            <div style="display:flex;flex-direction:column;gap:3px;font-size:13px">
                @foreach ($call->actions as $a)
                    @php($cust = ! empty($a['customer_id']) ? \App\Models\BillingCustomer::where('company_id', $call->company_id)->where('customer_id', $a['customer_id'])->orderBy('is_left')->first() : null)
                    <div style="color:{{ ($a['ok'] ?? true) ? 'inherit' : '#dc2626' }}">
                        <span style="opacity:.6">{{ gmdate('i:s', (int) ($a['at'] ?? 0)) }}</span>
                        {{ $a['text'] ?? '' }}
                        @if ($cust)
                            · <a href="{{ \App\Filament\App\Resources\Customers\CustomerResource::getUrl('view', ['record' => $cust]) }}"
                                 style="color:#3b82f6">{{ __('কাস্টমার') }} {{ $cust->customer_id }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
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
