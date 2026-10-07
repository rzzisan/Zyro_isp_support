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
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    protected static ?string $title = 'কাস্টমার';

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant()->getKey();
        $n = BillingCustomer::where('company_id', $company)->whereNull('gone_at')->count();
        $last = DB::table('billing_customer_syncs')->where('company_id', $company)->whereNull('error')
            ->whereNotNull('finished_at')->max('finished_at');

        return "{$n} জন কাস্টমার · বিলিং থেকে প্রতিদিন রাত ৩টায় আপডেট হয়"
            .($last ? ' · শেষ আপডেট '.\Illuminate\Support\Carbon::parse($last, 'UTC')->timezone('Asia/Dhaka')->format('d M, g:i A') : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')->label('এখনই Sync')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => CustomerResource::managers())
                ->requiresConfirmation()->modalDescription('বিলিং থেকে সব কাস্টমারের তথ্য নতুন করে আনা হবে (প্রায় ২০ সেকেন্ড)।')
                ->action(function () {
                    try {
                        $r = Engine::syncCustomers(Filament::getTenant()->getKey());
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Sync হয়নি')->body($e->getMessage())->send();

                        return;
                    }
                    Notification::make()->success()->title('কাস্টমারের তথ্য আপডেট হয়েছে')
                        ->body("মোট {$r['total']} জন · নতুন {$r['created']} · বিলিংয়ে নেই {$r['gone']}")->send();
                }),
            Action::make('csv')->label('CSV ডাউনলোড')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => CustomerResource::managers())
                ->url(fn () => route('customers.csv', ['company' => Filament::getTenant()->slug]))
                ->openUrlInNewTab(),
        ];
    }
}
