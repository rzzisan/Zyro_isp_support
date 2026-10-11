<x-filament-widgets::widget>
    <x-filament::section heading="লাইভ অবস্থা (MikroTik, OLT, বিল)" compact>
        <x-slot name="afterHeader">
            <x-filament::button size="xs" color="gray" icon="heroicon-o-arrow-path" wire:click="refresh" wire:loading.attr="disabled">
                আবার দেখুন
            </x-filament::button>
        </x-slot>
        <div wire:loading.remove wire:target="refresh">
            @include('filament.app.tickets.customer-info', ['info' => $info, 'error' => $error])
        </div>
        <div wire:loading wire:target="refresh" style="padding:1rem; opacity:.7">লোড হচ্ছে…</div>
    </x-filament::section>
</x-filament-widgets::widget>
