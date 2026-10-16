{{-- A customer's IP as a link to their router's web page (company setting: router_port), new tab. --}}
@props(['ip' => null])
@php($url = $ip ? \Filament\Facades\Filament::getTenant()?->routerUrl($ip) : null)
@if ($url)<a href="{{ $url }}" target="_blank" rel="noopener noreferrer" title="{{ __('কাস্টমারের রাউটার খুলুন') }}" style="color:inherit;text-decoration:underline dotted;text-underline-offset:2px">{{ $ip }}</a>@else{{ $ip ?: '—' }}@endif
