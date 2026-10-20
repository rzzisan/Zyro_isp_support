<?php

namespace App\Support;

use App\Models\BillingCustomer;
use Illuminate\Support\Carbon;

/**
 * Billing's customer details (Service / Network & Device / Personal), copied by engine/customer_history.py, as
 * [card title => [label => value]]: the customer page's first three tabs and the app's customer screen show the same.
 */
class CustomerProfile
{
    public const LOCATION = 'location';

    /** @return array<string, array<string, mixed>> */
    public static function sections(BillingCustomer $r, string $tab): array
    {
        $d = $r->details ?? [];
        $inst = $d['installation'] ?? [];
        $date = fn ($v) => $v ? Carbon::parse($v)->format('d M Y') : null;
        $tk = fn ($v) => $v === null ? null : number_format((float) $v, 2).__(' ৳');

        return match ($tab) {
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
                    __('লোকেশন') => $r->latitude && $r->longitude ? [self::LOCATION => $r->latitude.', '.$r->longitude] : null,
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
    }
}
