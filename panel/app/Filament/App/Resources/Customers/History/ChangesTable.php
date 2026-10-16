<?php

namespace App\Filament\App\Resources\Customers\History;

use App\Models\BillingCustomerChangeLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Customer Change Log: what changed each time, "before → after" against billing's previous row.
 * A PPPoE password change shows only as "changed"; the password itself is never stored.
 */
class ChangesTable extends HistoryTable
{
    protected static string $section = 'changes';

    public const LABELS = [
        'Name' => 'নাম', 'MobileNumber' => 'মোবাইল', 'Service' => 'সার্ভিস', 'BillingStatus' => 'বিলিং অবস্থা',
        'MikrotikStatus' => 'MikroTik', 'Server' => 'সার্ভার', 'Speed' => 'প্রোফাইল/স্পিড', 'Package' => 'প্যাকেজ',
        'Protocol' => 'Protocol', 'ConnectionType' => 'সংযোগের ধরন', 'CustomerType' => 'কাস্টমারের ধরন',
        'MonthlyBill' => 'মাসিক বিল', 'ExpireDate' => 'মেয়াদের দিন', 'ExtendedDate' => 'বাড়ানো মেয়াদ', 'Zone' => 'Zone',
        'SubZone' => 'Sub Zone', 'Box' => 'Box', 'Device' => 'ডিভাইস', 'DOB' => 'জন্মতারিখ', 'Email' => 'ইমেইল',
        'Gender' => 'লিঙ্গ', 'BloodGroup' => 'রক্তের গ্রুপ', 'District' => 'জেলা', 'Thana' => 'থানা', 'Address' => 'ঠিকানা',
        'Road' => 'রোড', 'House' => 'বাসা', 'UserName' => 'PPPoE ID', 'ClientCode' => 'কাস্টমার ID',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->rows(BillingCustomerChangeLog::class)->orderByDesc('changed_at')->orderByDesc('id'))
            ->heading(__('পরিবর্তনের ইতিহাস'))
            ->description(fn () => $this->fetchedNote())
            ->paginated([10, 25, 50])->defaultPaginationPageOption(10)
            ->emptyStateHeading(__('কোনো পরিবর্তন নেই'))
            ->filters([
                Filter::make('only_changes')->label(__('শুধু যেখানে কিছু বদলেছে'))->default()
                    ->query(fn (Builder $query) => $query->where(fn (Builder $w) => $w->whereNull('changed')
                        ->orWhereRaw("changed::text NOT IN ('{}', '[]')")->orWhere('password_changed', true)->orWhereNotNull('remarks'))),
            ])
            ->columns([
                TextColumn::make('changed_at')->label(__('সময়'))->dateTime('d M Y h:i A', self::TZ),
                TextColumn::make('changed_by')->label(__('করেছেন')),
                TextColumn::make('what')->label(__('কী বদলেছে'))->wrap()
                    ->state(fn (BillingCustomerChangeLog $r) => $this->describe($r)),
                TextColumn::make('remarks')->label(__('মন্তব্য'))->limit(40)->placeholder('—')->toggleable(),
            ]);
    }

    private function describe(BillingCustomerChangeLog $r): HtmlString
    {
        $e = fn ($v) => e($v === null || $v === '' ? __('(ফাঁকা)') : (is_scalar($v) ? (string) $v : json_encode($v)));
        $lines = [];
        if ($r->changed === null) {
            $lines[] = '<span style="opacity:.7">'.__('প্রথম রেকর্ড: :package, :zone, মাসিক :bill', ['package' => $e($r->snapshot['Package'] ?? null),
                'zone' => $e($r->snapshot['Zone'] ?? null), 'bill' => $e($r->snapshot['MonthlyBill'] ?? null)]).'</span>';
        }
        foreach ($r->changed ?? [] as $field => [$before, $after]) {
            $lines[] = '<b>'.e(__(self::LABELS[$field] ?? $field)).'</b>: '.$e($before).' → '.$e($after);
        }
        if ($r->password_changed) {
            $lines[] = __('<b>PPPoE পাসওয়ার্ড</b> বদলেছে');
        }

        return new HtmlString($lines ? implode('<br>', $lines) : __('<span style="opacity:.6">কিছু বদলায়নি</span>'));
    }
}
