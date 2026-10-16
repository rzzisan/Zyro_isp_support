<?php

namespace App\Filament\App\Resources\Customers\Pages;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Services\Engine;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('কাস্টমার');
    }

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant()->getKey();
        $n = BillingCustomer::where('company_id', $company)->whereNull('gone_at')->where('is_left', false)->count();
        $left = BillingCustomer::where('company_id', $company)->whereNull('gone_at')->where('is_left', true)->count();
        $last = DB::table('billing_customer_syncs')->where('company_id', $company)->whereNull('error')
            ->whereNotNull('finished_at')->max('finished_at');

        return __(':n জন কাস্টমার · Left :left জন · বিলিং থেকে প্রতিদিন রাত ৩টায় আপডেট হয়, নতুন কাস্টমার আসে ২০ মিনিট পরপর', ['n' => $n, 'left' => $left])
            .($last ? ' · '.__('শেষ আপডেট :time', ['time' => Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A')]) : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNew')->label(__('নতুন কাস্টমার আনুন'))->icon(Heroicon::OutlinedUserPlus)->color('gray')
                ->visible(fn () => CustomerResource::managers())
                ->action(function () {
                    try {
                        $r = Engine::syncNewCustomers(Filament::getTenant()->getKey());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('নতুন কাস্টমার আনা যায়নি'))->body($e->getMessage())->send();

                        return;
                    }
                    $found = $r['found'] ?? [];
                    Notification::make()->success()
                        ->title($found ? __(':n জন নতুন কাস্টমার আনা হয়েছে', ['n' => count($found)]) : __('নতুন কাস্টমার নেই'))
                        ->body(__('শেষ ID :id', ['id' => $r['last_id'] ?? '-']).($found ? ' · '.__('নতুন: :ids', ['ids' => implode(', ', $found)]) : ''))->send();
                }),
            Action::make('sync')->label(__('এখনই Sync'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => CustomerResource::managers())
                ->requiresConfirmation()->modalDescription(__('বিলিং থেকে সব কাস্টমারের তথ্য নতুন করে আনা হবে (প্রায় ২০ সেকেন্ড)।'))
                ->action(function () {
                    try {
                        $r = Engine::syncCustomers(Filament::getTenant()->getKey());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('Sync হয়নি'))->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title(__('কাস্টমারের তথ্য আপডেট হয়েছে'))
                        ->body(__('মোট :total জন · নতুন :created · বিলিংয়ে নেই :gone · Left :left জন (এই sync-এ Left হয়েছেন :became, ফিরে এসেছেন :back)', ['total' => $r['total'],
                            'created' => $r['created'], 'gone' => $r['gone'], 'left' => $r['left_total'] ?? 0, 'became' => $r['became_left'] ?? 0, 'back' => $r['came_back'] ?? 0]))->send();
                }),
            Action::make('csv')->label(__('CSV ডাউনলোড'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => CustomerResource::managers())
                ->url(fn () => route('customers.csv', ['company' => Filament::getTenant()->slug]))
                ->openUrlInNewTab(),
        ];
    }
}
