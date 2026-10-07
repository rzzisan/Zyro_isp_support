<?php

namespace App\Filament\App\Resources\Conversations\Pages;

use App\Filament\App\Resources\Conversations\Concerns\InboxList;
use App\Filament\App\Resources\Conversations\ConversationResource;
use Filament\Resources\Pages\Page;

/** Inbox with no chat open yet: the chat list and a placeholder. */
class ListConversations extends Page
{
    use InboxList;

    protected static string $resource = ConversationResource::class;

    protected string $view = 'filament.app.inbox';

    protected static ?string $title = 'ইনবক্স';

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getViewData(): array
    {
        return ['contact' => null];
    }
}
