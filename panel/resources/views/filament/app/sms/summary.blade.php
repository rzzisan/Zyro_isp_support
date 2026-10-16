{{-- SMS form: who gets it, how long it is, what it costs, a preview (App\Filament\App\SmsActions::summary) --}}
@php
    $len = $length;
    $per = $len['unicode'] ? __('বাংলা/ইউনিকোড: ১ SMS = 70 অক্ষর, বড় হলে প্রতি ভাগ 67') : __('ইংরেজি: ১ SMS = 160 অক্ষর, বড় হলে প্রতি ভাগ 153');
    $ready = $settings->enabled && $settings->configured();
@endphp
<div style="border:1px solid rgba(127,127,127,.25);border-radius:.6rem;padding:.75rem 1rem;font-size:.875rem;line-height:1.5">
    <div style="display:flex;flex-wrap:wrap;gap:.4rem 1.5rem">
        <span><b>{{ number_format($est['count']) }}</b> {{ __('জন পাবেন') }}</span>
        <span>{{ __('লেখা:') }} <b>{{ $len['chars'] }}</b> {{ __('অক্ষর ·') }} <b>{{ $len['parts'] }}</b> {{ __('ভাগ') }}</span>
        <span>{{ __('মোট') }} <b>{{ number_format($est['parts']) }}</b> SMS</span>
        @if ($est['cost'] !== null)
            <span>{{ __('আনুমানিক খরচ') }} <b>{{ number_format($est['cost'], 2) }}</b> {{ __('টাকা') }} <span style="opacity:.6">({{ rtrim(rtrim(number_format($est['rate'], 4), '0'), '.') }} {{ __('টাকা/SMS') }})</span></span>
        @else
            <span style="opacity:.6">{{ __('খরচ দেখাতে সেটিংস → SMS গেটওয়েতে প্রতি SMS-এর রেট দিন') }}</span>
        @endif
        @if ($settings->balance !== null)
            <span>{{ __('ব্যালান্স') }} <b>{{ number_format((float) $settings->balance, 2) }}</b></span>
        @endif
    </div>
    <div style="opacity:.65;font-size:.8rem">{{ $per }}</div>
    @if ($skipped)
        <div style="color:#b45309">{{ __(':n জনের সঠিক মোবাইল নম্বর নেই, তাঁরা বাদ।', ['n' => number_format($skipped)]) }}</div>
    @endif
    @if ($bad)
        <div style="color:#b91c1c">{{ __('ভুল নম্বর বাদ:') }} {{ implode(', ', array_slice($bad, 0, 5)) }}{{ count($bad) > 5 ? ' …' : '' }}</div>
    @endif
    @if (! empty($billOnly))
        <div style="color:#b45309">{{ collect($billOnly)->map(fn ($k) => '{'.$k.'}')->implode(' ') }} {{ __('শুধু বিল পরিশোধ / টিকিটের SMS-এ বসে; এখানে খালি থাকবে।') }}</div>
    @endif
    @if (! $ready)
        <div style="color:#b45309">{{ __('SMS গেটওয়ে এখনো চালু নয় (সেটিংস → SMS গেটওয়ে)। এখন পাঠালে তালিকায় অপেক্ষা করবে, চালু হলে যাবে।') }}</div>
    @endif
    @if ($preview)
        <div style="margin-top:.5rem;display:grid;gap:.4rem">
            @foreach ($preview as $p)
                <div style="background:rgba(15,124,123,.07);border-radius:.5rem;padding:.4rem .6rem">
                    <div style="font-size:.75rem;opacity:.7">{{ $p['to'] }}</div>
                    <div style="white-space:pre-wrap">{{ $p['text'] }}</div>
                </div>
            @endforeach
        </div>
    @endif
</div>
