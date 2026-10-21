{{-- WhatsApp template form: who gets it, what it costs, the number's daily limit, a preview (App\Filament\App\WaCampaignActions::summary) --}}
<div style="border:1px solid rgba(127,127,127,.25);border-radius:.6rem;padding:.75rem 1rem;font-size:.875rem;line-height:1.5">
    <div style="display:flex;flex-wrap:wrap;gap:.4rem 1.5rem">
        <span><b>{{ number_format($est['count']) }}</b> {{ __('জন পাবেন') }}</span>
        @if ($t)
            <span>{{ __('ধরন') }} <b>{{ $t['category'] }}</b></span>
        @endif
        @if ($est['cost'] !== null)
            <span>{{ __('আনুমানিক খরচ') }} <b>{{ number_format($est['cost'], 2) }}</b> {{ __('টাকা') }} <span style="opacity:.6">({{ rtrim(rtrim(number_format($est['rate'], 4), '0'), '.') }} {{ __('টাকা/মেসেজ') }})</span></span>
        @elseif ($t)
            <span style="opacity:.6">{{ __('খরচ দেখাতে সেটিংস → WhatsApp নম্বরে এই ধরনের মেসেজের রেট দিন') }}</span>
        @endif
        @if ($tier)
            <span>{{ __('নম্বরের দৈনিক সীমা') }} <b>{{ $limit ? number_format($limit).__(' জন / ২৪ ঘণ্টা') : $tier }}</b></span>
        @endif
        @if ($quality)
            <span>Quality <b>{{ $quality }}</b></span>
        @endif
    </div>
    @if ($limit && $est['count'] > $limit)
        <div style="color:#b91c1c">{{ __('সীমার চেয়ে বেশি জন; সীমার বাইরের মেসেজ Meta ফিরিয়ে দিতে পারে। ভাগ করে কয়েক দিনে পাঠান।') }}</div>
    @endif
    @if ($skipped)
        <div style="color:#b45309">{{ __(':n জনের সঠিক মোবাইল নম্বর নেই, তাঁরা বাদ।', ['n' => number_format($skipped)]) }}</div>
    @endif
    @if ($bad)
        <div style="color:#b91c1c">{{ __('ভুল নম্বর বাদ:') }} {{ implode(', ', array_slice($bad, 0, 5)) }}{{ count($bad) > 5 ? ' …' : '' }}</div>
    @endif
    @if ($error)
        <div style="color:#b91c1c">{{ $error }}</div>
    @endif
    <div style="opacity:.65;font-size:.8rem">{{ __('মোবাইল নম্বরে WhatsApp না থাকলে মেসেজ ব্যর্থ হয় (লগে দেখা যায়)। কাস্টমার উত্তর দিলে ইনবক্সে আসে, বট উত্তর দেয়।') }}</div>
    @if ($preview)
        <div style="margin-top:.5rem;display:grid;gap:.4rem">
            @foreach ($preview as $p)
                <div style="background:rgba(37,211,102,.09);border-radius:.5rem;padding:.4rem .6rem">
                    <div style="font-size:.75rem;opacity:.7">{{ $p['to'] }}</div>
                    <div style="white-space:pre-wrap">{{ $p['text'] }}</div>
                </div>
            @endforeach
        </div>
    @elseif ($t)
        <div style="margin-top:.5rem;background:rgba(37,211,102,.09);border-radius:.5rem;padding:.4rem .6rem;white-space:pre-wrap">{{ $t['body'] }}</div>
    @endif
</div>
