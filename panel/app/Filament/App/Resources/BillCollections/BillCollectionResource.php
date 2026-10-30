<?php

namespace App\Filament\App\Resources\BillCollections;

use App\Filament\App\Resources\BillCollections\Pages\ListBillCollections;
use App\Models\BillCollection;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Bills collected from the Desk (and refused attempts). Approval happens in the billing software. Read-only. */
class BillCollectionResource extends Resource
{
    use \App\Filament\App\Concerns\QuickAccessible;

    protected static ?string $model = BillCollection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function getNavigationLabel(): string
    {
        return __('Desk বিল লগ');
    }

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('বিল কালেকশন');
    }

    public static function getPluralModelLabel(): string
    {
        return __('বিল কালেকশন');
    }

    protected static ?string $slug = 'bill-collections';

    protected static ?int $navigationSort = -5;

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('bills');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $company = fn () => Filament::getTenant()?->getKey() ?? 0;
        $tk = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('সময়'))->dateTime('d M Y, g:i A', 'Asia/Dhaka')->sortable(),
                TextColumn::make('customer_name')->label(__('কাস্টমার'))
                    ->description(fn (BillCollection $r) => trim('ID '.$r->customer_id.($r->username ? ' · '.$r->username : '')))
                    ->searchable(['customer_name', 'customer_id', 'username']),
                TextColumn::make('paid')->label(__('টাকা'))->formatStateUsing(fn ($state) => $tk($state))->suffix(__(' টাকা'))
                    ->description(fn (BillCollection $r) => (float) $r->discount > 0 ? __('ডিসকাউন্ট :amount', ['amount' => $tk($r->discount)]) : null),
                TextColumn::make('payable')->label(__('তখন বকেয়া'))->formatStateUsing(fn ($state) => $tk($state))->placeholder('—')->toggleable(),
                TextColumn::make('payment_method')->label(__('মাধ্যম'))->placeholder('—'),
                TextColumn::make('received_by')->label('Received By')->placeholder('—'),
                TextColumn::make('user.name')->label(__('Desk থেকে কে'))->placeholder('—'),
                TextColumn::make('result')->label(__('ফল'))->badge()
                    ->formatStateUsing(fn (string $state) => __(BillCollection::RESULTS[$state] ?? $state))
                    ->color(fn (string $state) => ['saved' => 'success', 'blocked' => 'warning', 'failed' => 'danger'][$state] ?? 'gray'),
                // bill_approve: approved in the background right after saving, or why it stayed pending
                TextColumn::make('approved_at')->label('Approve')->badge()
                    ->getStateUsing(fn ($record) => $record->approved_at ? __('হয়েছে') : ($record->approve_error ? __('হয়নি') : null))
                    ->color(fn ($state) => $state === __('হয়েছে') ? 'success' : 'danger')
                    ->tooltip(fn ($record) => $record->approve_error)->placeholder('—'),
                TextColumn::make('message')->label(__('বার্তা'))->limit(70)->wrap()->toggleable(),
                TextColumn::make('trx_no')->label(__('Trx নম্বর'))->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remarks')->label(__('নোট'))->placeholder('—')->limit(50)->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('send_sms')->label('SMS')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('receipt')->label(__('রিসিট'))->icon(\Filament\Support\Icons\Heroicon::OutlinedPrinter)
                    ->visible(fn (BillCollection $record) => $record->result === 'saved')
                    ->url(fn (BillCollection $record) => route('receipts.print', ['company' => Filament::getTenant()->slug, 'collection' => $record->id]))
                    ->openUrlInNewTab(),
            ])
            ->filters([
                SelectFilter::make('result')->label(__('ফল'))->options(array_map('__', BillCollection::RESULTS)),
                SelectFilter::make('user_id')->label(__('Desk থেকে কে'))->relationship('user', 'name'),
                Filter::make('date')->schema([DatePicker::make('from')->label(__('থেকে')), DatePicker::make('until')->label(__('পর্যন্ত'))])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d, 'Asia/Dhaka')->startOfDay()->utc()))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<', Carbon::parse($d, 'Asia/Dhaka')->addDay()->startOfDay()->utc()))),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListBillCollections::route('/')];
    }
}
