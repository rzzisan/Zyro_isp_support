{{-- Customer page tabs 1-3 (Service / Network & Device / Personal), billing's details page copied by engine/customer_history.py. --}}
@php
    /** @var \App\Models\BillingCustomer $r */
    $r = $getRecord();
    $d = $r->details ?? [];
    $inst = $d['installation'] ?? [];
    $date = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : null;
    $tk = fn ($v) => $v === null ? null : number_format((float) $v, 2).' ৳';
    $rows = match ($tab) {
        'service' => [
            'ইন্টারনেট সার্ভিস' => [
                'প্যাকেজ' => $r->package, 'MikroTik প্রোফাইল' => $r->mikrotik_profile,
                'সংযোগের তারিখ' => $date($r->joined_on), 'কাস্টমারের ধরন' => $d['client_type'] ?? $r->customer_type,
                'বিল শুরুর মাস' => $r->billing_start_month ? $r->billing_start_month->format('M Y') : null,
                'PPPoE ID' => $r->username, 'মেয়াদ (প্রতি মাসের)' => $r->bill_day ? $r->bill_day.' তারিখ' : null,
                'মাসিক বিল' => $tk($r->monthly_bill), 'বকেয়া' => $tk($r->due),
                'রেফারেন্স' => $r->reference_by, 'সংযোগ দিয়েছেন' => $r->setup_by,
                'শেষ লগইন' => $r->last_login_at?->timezone('Asia/Dhaka')->format('d M Y h:i A'),
                'শেষ লগআউট' => $d['last_logout'] ?? null,
                'MikroTik-এ' => isset($d['mikrotik_enabled']) ? ($d['mikrotik_enabled'] ? 'চালু' : 'বন্ধ') : null,
                'বিলিংয়ে তৈরি' => $d['billing_created_on'] ?? null,
            ],
            'ইনস্টলেশন চার্জ' => [
                'চার্জের তারিখ' => $inst['charged_on'] ?? null, 'ইনস্টলেশন ফি' => $tk($inst['fee'] ?? null),
                'জমা' => $tk($inst['paid'] ?? null), 'বকেয়া' => $tk($inst['due'] ?? null),
                'পদ্ধতি' => $inst['method'] ?? null, 'জমার তারিখ' => $inst['paid_on'] ?? null,
                'নিয়েছেন' => $inst['received_by'] ?? null,
            ],
        ],
        'network' => [
            'নেটওয়ার্ক ও ডিভাইস' => [
                'সার্ভার' => $r->server, 'Protocol' => $r->protocol, 'Zone' => $r->zone, 'Sub Zone' => $r->subzone,
                'Box' => $r->box, 'সংযোগের ধরন' => $r->connection_type,
                'লোকেশন' => $r->latitude && $r->longitude
                    ? new \Illuminate\Support\HtmlString('<a class="text-primary-600 underline" target="_blank" rel="noopener" href="https://www.google.com/maps?q='
                        .urlencode($r->latitude.','.$r->longitude).'">'.e($r->latitude.', '.$r->longitude).'</a>')
                    : null,
                'কেবল' => isset($d['cable_meters']) && $d['cable_meters'] > 0 ? $d['cable_meters'].' মিটার' : null,
                'ফাইবার কোড' => $r->fiber_code, 'কোর' => $d['core_count'] ?? null,
                'ডিভাইস' => $r->device, 'ডিভাইস MAC / সিরিয়াল' => $r->device_mac,
                'ভেন্ডর' => $d['vendor'] ?? null, 'কেনার তারিখ' => $d['device_purchase_date'] ?? null,
            ],
        ],
        default => [
            'ব্যক্তিগত তথ্য' => [
                'পিতার নাম' => $r->father_name, 'মাতার নাম' => $r->mother_name, 'জন্মতারিখ' => $date($r->birth_date),
                'NID' => $r->nid, 'ফর্ম নং' => $r->form_no, 'ইমেইল' => $r->email, 'লিঙ্গ' => $r->gender,
                'পেশা' => $r->occupation, 'মোবাইল' => $r->mobile, 'ফোন' => $r->phone,
                'Facebook' => $d['facebook'] ?? null, 'Linkedin' => $d['linkedin'] ?? null,
                'জেলা' => $r->district, 'উপজেলা' => $r->upazila ?: $r->thana, 'রোড' => $r->road, 'বাসা' => $r->house,
                'বর্তমান ঠিকানা' => $r->present_address ?: $r->address, 'স্থায়ী ঠিকানা' => $r->permanent_address,
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
            বিলিং থেকে আনা হয়েছে {{ $r->details_fetched_at->locale('bn')->diffForHumans() }}
        @else
            বিলিংয়ের পেজ থেকে এখনো আনা হয়নি; কিছু ঘর ফাঁকা থাকতে পারে
        @endif
    </div>
</div>
