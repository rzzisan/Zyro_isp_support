<?php

namespace App\Filament\App\Resources\Customers;

use App\Filament\App\Resources\Customers\Pages\ListCustomers;
use App\Filament\App\Resources\Customers\Pages\ViewCustomer;
use App\Models\BillingCustomer;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** All billing customers from our own copy (synced nightly). Everyone can look; passwords and CSV are owner/admin. */
class CustomerResource extends Resource
{
    protected static ?string $model = BillingCustomer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'কাস্টমার';

    protected static ?string $modelLabel = 'কাস্টমার';

    protected static ?string $pluralModelLabel = 'কাস্টমার';

    protected static ?string $slug = 'customers';

    protected static ?int $navigationSort = -8;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function managers(): bool
    {
        return (bool) auth()->user()?->managesCompany(Filament::getTenant());
    }

    private static function options(string $column): array
    {
        return BillingCustomer::where('company_id', Filament::getTenant()?->getKey() ?? 0)->whereNull('gone_at')
            ->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('customer_id')
            ->striped()
            ->columns([
                TextColumn::make('customer_id')->label('ID')->sortable()->searchable(query: fn (Builder $query, string $search) => $query
                    ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                        ->orWhere('name', 'ilike', "%{$search}%")->orWhere('username', 'ilike', "%{$search}%")
                        ->orWhere('mobile', 'like', '%'.preg_replace('/\D/', '', $search).'%'))),
                TextColumn::make('name')->label('কাস্টমার')->description(fn (BillingCustomer $r) => $r->mobile)->wrap(),
                TextColumn::make('username')->label('PPPoE ID')->copyable()->toggleable(),
                TextColumn::make('zone')->label('Zone')->description(fn (BillingCustomer $r) => trim($r->subzone.($r->box ? ' · '.$r->box : '')))->toggleable(),
                TextColumn::make('package')->label('প্যাকেজ')->toggleable(),
                TextColumn::make('monthly_bill')->label('মাসিক বিল')->numeric(0)->sortable(),
                TextColumn::make('due')->label('বকেয়া')->numeric(0)->sortable()->placeholder('—')
                    ->color(fn ($state) => $state > 0 ? 'danger' : null),
                TextColumn::make('status')->label('অবস্থা')->badge()
                    ->state(fn (BillingCustomer $r) => $r->gone_at ? 'বিলিংয়ে নেই' : ($r->disabled ? 'বন্ধ' : ($r->status ?: '—')))
                    ->color(fn (string $state) => match ($state) { 'Active' => 'success', 'বন্ধ' => 'danger', default => 'gray' }),
                TextColumn::make('bill_day')->label('বিলের তারিখ')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('joined_on')->label('যোগ দিয়েছেন')->date('d M Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('zone')->label('Zone')->options(fn () => static::options('zone'))->searchable(),
                SelectFilter::make('subzone')->label('Subzone')->options(fn () => static::options('subzone'))->searchable(),
                SelectFilter::make('package')->label('প্যাকেজ')->options(fn () => static::options('package'))->searchable(),
                SelectFilter::make('status')->label('অবস্থা')->options(fn () => static::options('status')),
                TernaryFilter::make('disabled')->label('লাইন')->trueLabel('বন্ধ')->falseLabel('চালু'),
                TernaryFilter::make('has_due')->label('বকেয়া')->trueLabel('বকেয়া আছে')->falseLabel('বকেয়া নেই')
                    ->queries(true: fn (Builder $query) => $query->where('due', '>', 0),
                        false: fn (Builder $query) => $query->where(fn ($w) => $w->whereNull('due')->orWhere('due', '<=', 0))),
                TernaryFilter::make('gone')->label('বিলিংয়ে আছে')->default(true)->trueLabel('আছে')->falseLabel('নেই (left)')
                    ->queries(true: fn (Builder $query) => $query->whereNull('gone_at'), false: fn (Builder $query) => $query->whereNotNull('gone_at')),
            ])
            ->filtersLayout(FiltersLayout::AboveContentCollapsible)
            ->filtersFormColumns(4)
            ->recordActions([ViewAction::make()->label('দেখুন')]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('পরিচয়')->columns(3)->schema([
                TextEntry::make('customer_id')->label('কাস্টমার ID'),
                TextEntry::make('name')->label('নাম'),
                TextEntry::make('username')->label('PPPoE ID')->copyable(),
                TextEntry::make('mobile')->label('মোবাইল')->copyable()->placeholder('—'),
                TextEntry::make('email')->label('ইমেইল')->placeholder('—'),
                TextEntry::make('nid')->label('NID')->placeholder('—'),
                TextEntry::make('address')->label('ঠিকানা')->placeholder('—')
                    ->state(fn (BillingCustomer $r) => collect([$r->house, $r->road, $r->address, $r->thana, $r->district])->filter()->join(', ') ?: null),
                TextEntry::make('joined_on')->label('যোগ দিয়েছেন')->date('d M Y')->placeholder('—'),
                TextEntry::make('assigned_employee')->label('দায়িত্বে')->placeholder('—'),
            ]),
            Section::make('সংযোগ')->columns(3)->schema([
                TextEntry::make('zone')->label('Zone / Subzone / Box')
                    ->state(fn (BillingCustomer $r) => collect([$r->zone, $r->subzone, $r->box])->filter()->join(' / ') ?: null)->placeholder('—'),
                TextEntry::make('package')->label('প্যাকেজ')->placeholder('—'),
                TextEntry::make('speed')->label('স্পিড')->placeholder('—'),
                TextEntry::make('connection_type')->label('সংযোগের ধরন')->placeholder('—'),
                TextEntry::make('protocol')->label('Protocol / Server')
                    ->state(fn (BillingCustomer $r) => trim($r->protocol.' · '.$r->server, ' ·') ?: null)->placeholder('—'),
                TextEntry::make('customer_type')->label('কাস্টমারের ধরন')->placeholder('—'),
            ]),
            Section::make('বিল (শেষ sync অনুযায়ী)')->columns(4)->schema([
                TextEntry::make('monthly_bill')->label('মাসিক বিল')->numeric(2)->suffix(' টাকা'),
                TextEntry::make('due')->label('বকেয়া')->numeric(2)->suffix(' টাকা')->placeholder('০'),
                TextEntry::make('paid')->label('এই মাসে দিয়েছেন')->numeric(2)->suffix(' টাকা')->placeholder('—'),
                TextEntry::make('bill_day')->label('বিলের শেষ তারিখ')->placeholder('—')->suffix(' তারিখ'),
                TextEntry::make('last_payment_date')->label('শেষ পেমেন্ট')->date('d M Y')->placeholder('—'),
                TextEntry::make('status')->label('অবস্থা')
                    ->state(fn (BillingCustomer $r) => ($r->status ?: '—').($r->disabled ? ' (লাইন বন্ধ)' : '')),
                TextEntry::make('synced_at')->label('শেষ sync')->since(),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', Filament::getTenant()?->getKey() ?? 0);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomers::route('/'), 'view' => ViewCustomer::route('/{record}')];
    }
}
