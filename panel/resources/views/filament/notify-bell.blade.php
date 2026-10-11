{{-- WhatsApp message alerts: bell in the top bar + public/js/zyro-notify.js. Only for inbox users of this company. --}}
@php($company = \Filament\Facades\Filament::getTenant())
@if ($company && \App\Support\Menu::allows(auth()->user(), $company, 'inbox'))
    <button type="button" id="zyro-notify-bell" class="z-notify-bell" data-state="ask" aria-label="মেসেজ নোটিফিকেশন">
        <svg class="z-bell-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
        <svg class="z-bell-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8.7 3A6 6 0 0 1 18 8a21.3 21.3 0 0 0 .6 5"/><path d="M17 17H3s3-2 3-9a4.67 4.67 0 0 1 .3-1.7"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/><path d="m2 2 20 20"/></svg>
        <span class="z-bell-dot"></span>
    </button>
    <script>
        window.ZyroNotify = {
            slug: @js($company->slug),
            poll: @js(route('notify.poll', $company)),
            worker: @js(asset('js/zyro-notify-worker.js').'?v='.filemtime(public_path('js/zyro-notify-worker.js'))),
            icon: @js(asset('favicon.ico')),
            sw: @js(asset('zyro-sw.js')),
            vapid: @js(\Illuminate\Support\Facades\DB::table('web_push_keys')->orderBy('id')->value('public_key')),
            push: @js(route('notify.push', $company)),
            unpush: @js(route('notify.push.delete', $company)),
            csrf: @js(csrf_token()),
        };
    </script>
    <script src="{{ asset('js/zyro-notify.js') }}?v={{ filemtime(public_path('js/zyro-notify.js')) }}" defer></script>
@endif
