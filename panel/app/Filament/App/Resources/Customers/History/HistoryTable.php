<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomer;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

/**
 * One tab of the customer page that lists a part of billing's customer history (engine/customer_history.py copies it).
 * Reads only the desk's own tables; re-renders when the page's background refresh from billing finishes.
 */
abstract class HistoryTable extends TableWidget
{
    public const TZ = 'Asia/Dhaka';

    public ?BillingCustomer $record = null;

    protected int|string|array $columnSpan = 'full';

    /** billing_customer_fetches.section this tab shows */
    protected static string $section = '';

    /** @param  class-string<Model>  $model */
    protected function rows(string $model, string $key = 'customer_header_id'): Builder
    {
        return $model::query()->where('company_id', $this->record?->company_id ?? 0)
            ->where($key, $this->record?->header_id ?? 0);
    }

    /** "বিলিং থেকে আনা হয়েছে ৫ মিনিট আগে" for the table's description. */
    protected function fetchedNote(): string
    {
        $row = DB::table('billing_customer_fetches')->where('company_id', $this->record?->company_id ?? 0)
            ->where('customer_header_id', $this->record?->header_id ?? 0)->where('section', static::$section)->first();
        if (! $row || ! $row->fetched_at) {
            return __('বিলিং থেকে এখনো আনা হয়নি').($row?->error ? __(' (শেষ চেষ্টায় সমস্যা)') : '');
        }

        return __('বিলিং থেকে আনা হয়েছে :time', ['time' => Carbon::parse($row->fetched_at, 'UTC')->locale(app()->getLocale())->diffForHumans()])
            .($row->error ? __(' · শেষ চেষ্টায় সমস্যা হয়েছিল') : '');
    }

    #[On('customer-history-updated')]
    public function historyUpdated(): void
    {
        // re-render with what the refresh copied
    }
}
