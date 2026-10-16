{{-- Customer page tabs 1-3 (Service / Network & Device / Personal), billing's details page copied by engine/customer_history.py. --}}
@php
    /** @var \App\Models\BillingCustomer $r */
    $r = $getRecord();
    $d = $r->details ?? [];
    $inst = $d['installation'] ?? [];
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : null;
    $tk = fn ($v) => $v === null ? null : number_format((float) $v, 2).__(' ৳');
    $rows = match ($tab) {
        'service' => [
            __('ইন্টারনেট সার্ভিস') => [
                __('প্যাকেজ') => $r->package, __('MikroTik প্রোফাইল') => $r->mikrotik_profile,
                __('সংযোগের তারিখ') => $date($r->joined_on), __('কাস্টমারের ধরন') => $d['client_type'] ?? $r->customer_type,
                __('বিল শুরুর মাস') => $r->billing_start_month ? $r->billing_start_month->format('M Y') : null,
                'PPPoE ID' => $r->username, __('মেয়াদ (প্রতি মাসের)') => $r->bill_day ? __(':n তারিখ', ['n' => $r->bill_day]) : null,
                __('মাসিক বিল') => $tk($r->monthly_bill), __('বকেয়া') => $tk($r->due),
                __('রেফারেন্স') => $r->reference_by, __('সংযোগ দিয়েছেন') => $r->setup_by,
                __('শেষ লগইন') => $r->last_login_at?->timezone('Asia/Dhaka')->format('d M Y h:i A'),
                __('শেষ লগআউট') => $d['last_logout'] ?? null,
                __('MikroTik-এ') => isset($d['mikrotik_enabled']) ? ($d['mikrotik_enabled'] ? __('চালু') : __('বন্ধ')) : null,
                __('বিলিংয়ে তৈরি') => $d['billing_created_on'] ?? null,
            ],
            __('ইনস্টলেশন চার্জ') => [
                __('চার্জের তারিখ') => $inst['charged_on'] ?? null, __('ইনস্টলেশন ফি') => $tk($inst['fee'] ?? null),
                __('জমা') => $tk($inst['paid'] ?? null), __('বকেয়া') => $tk($inst['due'] ?? null),
                __('পদ্ধতি') => $inst['method'] ?? null, __('জমার তারিখ') => $inst['paid_on'] ?? null,
                __('নিয়েছেন') => $inst['received_by'] ?? null,
            ],
        ],
        'network' => [
            __('নেটওয়ার্ক ও ডিভাইস') => [
                __('সার্ভার') => $r->server, 'Protocol' => $r->protocol, 'Zone' => $r->zone, 'Sub Zone' => $r->subzone,
                'Box' => $r->box, __('সংযোগের ধরন') => $r->connection_type,
                __('লোকেশন') => $r->latitude && $r->longitude
                    ? new \Illuminate\Support\HtmlString('<a class="text-primary-600 underline" target="_blank" rel="noopener" href="https://www.google.com/maps?q='
                        .urlencode($r->latitude.','.$r->longitude).'">'.e($r->latitude.', '.$r->longitude).'</a>')
                    : null,
                __('কেবল') => isset($d['cable_meters']) && $d['cable_meters'] > 0 ? $d['cable_meters'].__(' মিটার') : null,
                __('ফাইবার কোড') => $r->fiber_code, __('কোর') => $d['core_count'] ?? null,
                __('ডিভাইস') => $r->device, __('ডিভাইস MAC / সিরিয়াল') => $r->device_mac,
                __('ভেন্ডর') => $d['vendor'] ?? null, __('কেনার তারিখ') => $d['device_purchase_date'] ?? null,
            ],
        ],
        default => [
            __('ব্যক্তিগত তথ্য') => [
                __('পিতার নাম') => $r->father_name, __('মাতার নাম') => $r->mother_name, __('জন্মতারিখ') => $date($r->birth_date),
                'NID' => $r->nid, __('ফর্ম নং') => $r->form_no, __('ইমেইল') => $r->email, __('লিঙ্গ') => $r->gender,
                __('পেশা') => $r->occupation, __('মোবাইল') => $r->mobile, __('ফোন') => $r->phone,
                'Facebook' => $d['facebook'] ?? null, 'Linkedin' => $d['linkedin'] ?? null,
                __('জেলা') => $r->district, __('উপজেলা') => $r->upazila ?: $r->thana, __('রোড') => $r->road, __('বাসা') => $r->house,
                __('বর্তমান ঠিকানা') => $r->present_address ?: $r->address, __('স্থায়ী ঠিকানা') => $r->permanent_address,
            ],
        ],
    };
@endphp

@include('filament.app.tickets.partials.style')

<div class="ztd">
    <div class="ztd-grid2" style="grid-template-columns:repeat(auto-fit, minmax(320px, 1fr))">
        @foreach ($rows as $title => $fields)
            <div class="ztd-card">
                <div class="ztd-head"><div class="ztd-title">{{ $title }}</div></div>
                <div class="ztd-rows">
                    @foreach ($fields as $label => $value)
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
