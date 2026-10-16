{{-- Every version of one billing bill (billing's "childs"), oldest first. --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500">
                <th class="py-1 pr-3">{{ __('কে / কীভাবে') }}</th><th class="py-1 pr-3">{{ __('প্যাকেজ') }}</th><th class="py-1 pr-3">{{ __('বিল') }}</th>
                <th class="py-1 pr-3">{{ __('ডিসকাউন্ট') }}</th><th class="py-1 pr-3">{{ __('জমা') }}</th><th class="py-1 pr-3">{{ __('বকেয়া') }}</th><th class="py-1">{{ __('মন্তব্য') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_reverse($bill->versions ?? []) as $v)
                <tr class="border-t border-gray-200 dark:border-white/10">
                    <td class="py-1 pr-3">{{ $v['ProcessedBy'] ?? '—' }}<div class="text-xs text-gray-500">{{ $v['ActionProperty'] ?? '' }}</div></td>
                    <td class="py-1 pr-3">{{ $v['Package'] ?? '—' }}</td>
                    <td class="py-1 pr-3">{{ number_format((float) ($v['BillAmount'] ?? 0), 2) }}</td>
                    <td class="py-1 pr-3">{{ number_format((float) ($v['Discount'] ?? 0), 2) }}</td>
                    <td class="py-1 pr-3">{{ number_format((float) ($v['PaidAmount'] ?? 0), 2) }}</td>
                    <td class="py-1 pr-3">{{ number_format((float) ($v['Due'] ?? 0), 2) }}</td>
                    <td class="py-1">{{ ($v['Remark'] ?? null) && $v['Remark'] !== 'N/A' ? $v['Remark'] : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
