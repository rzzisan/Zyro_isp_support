<div @if ($running) wire:poll.4s="check" @endif>
    @if ($running)
        <div class="flex items-center gap-2 text-sm text-gray-500">
            <x-filament::loading-indicator class="h-4 w-4" /> {{ __('বিলিং থেকে এই কাস্টমারের তথ্য আপডেট হচ্ছে…') }}
        </div>
    @elseif ($failed)
        <div class="text-sm text-gray-500">{{ __('বিলিং থেকে এখন আপডেট করা গেল না; নিচে ডেস্কে রাখা শেষ তথ্য দেখাচ্ছে।') }}</div>
    @endif
</div>
