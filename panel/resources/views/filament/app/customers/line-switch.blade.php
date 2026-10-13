{{-- Customer list M.Status: a switch like billing's (green = line on). Clicking asks first; only members with the
     "কাস্টমার লিস্টে লাইন চালু/বন্ধ" permission can click. Left / not-in-billing customers show a label instead. --}}
@php($r = $getRecord())
@if ($r->gone_at || $r->is_left)
    <div class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
        {{ $r->gone_at ? 'বিলিংয়ে নেই' : 'Left' }}@if ($r->is_left && $r->left_on)<br>{{ $r->left_on->format('d M Y') }}@endif
    </div>
@else
    @php($on = ! $r->disabled)
    <div class="px-3 py-2" title="{{ $on ? 'লাইন চালু' : 'লাইন বন্ধ' }}">
        <span style="display:inline-block;position:relative;width:2.5rem;height:1.375rem;border-radius:9999px;transition:background .15s;background:{{ $on ? '#2563eb' : '#d1d5db' }}">
            <span style="position:absolute;top:.1875rem;{{ $on ? 'right' : 'left' }}:.1875rem;width:1rem;height:1rem;border-radius:9999px;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.25)"></span>
        </span>
    </div>
@endif
