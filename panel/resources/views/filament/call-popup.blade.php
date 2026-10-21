{{-- Hotline caller popup (public/js/zyro-calls.js): only when the company's SIP extension is on, for customers menu users. --}}
@php($company = \Filament\Facades\Filament::getTenant())
@if ($company && \App\Support\Menu::allows(auth()->user(), $company, 'customers') && \App\Models\SipSetting::live($company->id))
    <script>
        window.ZyroCalls = {
            poll: @js(route('calls.poll', $company)),
            t: @js(['ringing' => __('হটলাইনে কল আসছে'), 'ended' => __('কল শেষ'), 'unknown' => __('এই নম্বরে কোনো কাস্টমার নেই'),
                'due' => __('বকেয়া'), 'left' => __('Left'), 'close' => __('বন্ধ করুন')]),
        };
    </script>
    <script src="{{ asset('js/zyro-calls.js') }}?v={{ filemtime(public_path('js/zyro-calls.js')) }}" defer></script>
@endif
