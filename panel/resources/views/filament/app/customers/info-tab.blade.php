{{-- Customer page tabs 1-3 (Service / Network & Device / Personal), billing's details page copied by engine/customer_history.py. --}}
@php
    /** @var \App\Models\BillingCustomer $r */
    $r = $getRecord();
    $rows = \App\Support\CustomerProfile::sections($r, $tab);
    $location = fn ($v) => new \Illuminate\Support\HtmlString('<a class="text-primary-600 underline" target="_blank" rel="noopener" href="https://www.google.com/maps?q='
        .urlencode($v).'">'.e($v).'</a>');
@endphp

@include('filament.app.tickets.partials.style')

<div class="ztd">
    <div class="ztd-grid2" style="grid-template-columns:repeat(auto-fit, minmax(320px, 1fr))">
        @foreach ($rows as $title => $fields)
            <div class="ztd-card">
                <div class="ztd-head"><div class="ztd-title">{{ $title }}</div></div>
                <div class="ztd-rows">
                    @foreach ($fields as $label => $value)
                        @php($value = is_array($value) ? $location($value[\App\Support\CustomerProfile::LOCATION]) : $value)
                        <div class="ztd-row"><span>{{ $label }}</span><span>{{ filled($value) ? $value : '—' }}</span></div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
    <div class="ztd-sub" style="margin-top:8px">
        @if ($r->details_fetched_at)
            {{ __('বিলিং থেকে আনা হয়েছে :time', ['time' => $r->details_fetched_at->locale(app()->getLocale())->diffForHumans()]) }}
        @else
            {{ __('বিলিংয়ের পেজ থেকে এখনো আনা হয়নি; কিছু ঘর ফাঁকা থাকতে পারে') }}
        @endif
    </div>
</div>
