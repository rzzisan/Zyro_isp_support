{{-- Topbar shortcuts next to the search box (GLOBAL_SEARCH_BEFORE hook); each shows only to members who have that menu. --}}
@php
    use App\Filament\App\Pages\Monitoring;
    use App\Filament\App\Resources\Conversations\ConversationResource;
    use App\Filament\App\Resources\DailyBills\DailyBillResource;
    use App\Filament\App\Resources\Tickets\TicketResource;
    use Filament\Support\Icons\Heroicon;

    $links = array_filter([
        ConversationResource::canAccess() ? ['ইনবক্স', ConversationResource::getUrl(), Heroicon::OutlinedChatBubbleLeftRight] : null,
        DailyBillResource::canAccess() ? ['বিল নিন', DailyBillResource::getUrl('index', ['action' => 'receiveBill']), Heroicon::OutlinedBanknotes] : null,
        TicketResource::canAccess() ? ['নতুন টিকিট', TicketResource::getUrl('index', ['action' => 'newTicket']), Heroicon::OutlinedTicket] : null,
        Monitoring::canAccess() ? ['অনলাইন মনিটরিং', Monitoring::getUrl(), Heroicon::OutlinedSignal] : null,
    ]);
@endphp
@if ($links)
    <nav class="z-shortcuts" aria-label="শর্টকাট">
        @foreach ($links as [$label, $url, $icon])
            <a href="{{ $url }}" class="z-shortcut" title="{{ $label }}">
                {{ \Filament\Support\generate_icon_html($icon) }}
                <span>{{ $label }}</span>
            </a>
        @endforeach
    </nav>
@endif
