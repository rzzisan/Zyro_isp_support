{{-- A customer's IP as a link to their router's web page (company setting: router_port), new tab.
     When a router port is set, the page checks it lazily: open = green + highlighted, closed = red + faded. --}}
@props(['ip' => null])
@php($tenant = \Filament\Facades\Filament::getTenant())
@php($url = $ip ? $tenant?->routerUrl($ip) : null)
@if ($url && $tenant->router_port)<a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="{{ __('কাস্টমারের রাউটার খুলুন') }}"
    x-data="{ open: null }" x-init="fetch(@js(url('/port-check/'.$tenant->slug).'?ip='.urlencode($ip))).then(r => r.json()).then(d => open = d.open).catch(() => {})"
    :style="open === true ? 'color:#059669;background:#d1fae5;padding:0 4px;border-radius:4px;font-weight:700' : (open === false ? 'color:#dc2626;opacity:.55' : '')"
    style="color:inherit;text-decoration:underline dotted;text-underline-offset:2px">{{ $ip }}</a>@elseif ($url)<a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="{{ __('কাস্টমারের রাউটার খুলুন') }}" style="color:inherit;text-decoration:underline dotted;text-underline-offset:2px">{{ $ip }}</a>@else{{ $ip ?: '—' }}@endif
