<?php

namespace App\Filament\App\Resources\WaCampaigns\Pages;

use App\Filament\App\Resources\WaCampaigns\WaCampaignResource;
use App\Filament\App\WaCampaignActions;
use App\Support\WaTemplates;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ListWaCampaigns extends ListRecords
{
    protected static string $resource = WaCampaignResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('WhatsApp পাঠান');
    }

    public function getSubheading(): ?string
    {
        return __('Meta-তে অনুমোদিত টেমপ্লেট কাস্টমারদের WhatsApp-এ যায় কোম্পানির নিজের নম্বর থেকে, পেছনে ছোট ছোট ভাগে। Meta প্রতি মেসেজ কোম্পানির কার্ডে চার্জ করে। পেজ বন্ধ করলেও পাঠানো চলতে থাকে।');
    }

    protected function getHeaderActions(): array
    {
        return [
            WaCampaignActions::send(),
            Action::make('refreshTemplates')->label(__('টেমপ্লেট রিফ্রেশ'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function () {
                    try {
                        $all = WaTemplates::templates(Filament::getTenant()->getKey(), fresh: true);
                        $ok = count(array_filter($all, fn ($t) => $t['supported']));
                        Notification::make()->success()->title(__('অনুমোদিত টেমপ্লেট: :n', ['n' => $ok]))
                            ->body(implode(', ', array_map(fn ($t) => $t['name'], $all)) ?: null)->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('টেমপ্লেট আনা যায়নি'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
