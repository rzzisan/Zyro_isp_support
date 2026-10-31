<?php

namespace App\Filament\App\Resources\CustomerAltNumbers;

use App\Filament\App\Concerns\QuickAccessible;
use App\Filament\App\Resources\CustomerAltNumbers\Pages\ManageCustomerAltNumbers;
use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\CustomerAltNumber;
use App\Support\AltNumbers;
use App\Support\Menu;
use App\Support\Sms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * কাস্টমার ও বিল → বিকল্প নম্বর: every customer's other numbers (AltNumbers), searchable by number or customer.
 * Staff add a number by hand or take a wrong one off (it then stays off, the nightly rebuild leaves it).
 */
class CustomerAltNumberResource extends Resource
{
    use QuickAccessible;

    protected static ?string $model = CustomerAltNumber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?string $slug = 'customer-alt-numbers';

    protected static string|\UnitEnum|null $navigationGroup = 'কাস্টমার ও বিল';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('বিকল্প নম্বর');
    }

    public static function getModelLabel(): string
    {
        return __('বিকল্প নম্বর');
    }

    public static function getPluralModelLabel(): string
    {
        return __('বিকল্প নম্বর');
    }

    public static function canAccess(): bool
    {
        return Menu::can('customers');
    }

    private static function company(): int
    {
        return (int) Filament::getTenant()?->getKey();
    }

    private static function customerOf(CustomerAltNumber $r): ?BillingCustomer
    {
        return BillingCustomer::where('company_id', $r->company_id)->where('header_id', $r->customer_header_id)->first();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_header_id')->label(__('কাস্টমার'))->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
                    ->matching(trim($search))->orderBy('is_left')->orderBy('customer_id')->limit(15)
                    ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
                    ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])->all())
                ->getOptionLabelUsing(fn ($value) => ($c = BillingCustomer::where('company_id', static::company())->where('header_id', $value)->first())
                    ? BillingCustomer::searchLabel($c) : null),
            TextInput::make('number')->label(__('মোবাইল নম্বর'))->required()->tel()->placeholder('01XXXXXXXXX'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading(__('কোনো বিকল্প নম্বর নেই'))
            ->emptyStateDescription(__('প্রতি রাতে কাস্টমারের প্রোফাইলের ফোন, টিকেট, WhatsApp আর SMS থেকে বানানো হয়।'))
            ->searchPlaceholder(__('নম্বর, নাম, ID বা PPPoE'))
            ->columns([
                TextColumn::make('number')->label(__('নম্বর'))->formatStateUsing(fn ($state) => '0'.substr((string) $state, 3))
                    ->searchable(query: function (Builder $query, string $search) {
                        $core = preg_replace('/^(?:88)?0?/', '', preg_replace('/\D/', '', $search));
                        $headers = BillingCustomer::where('company_id', static::company())->matching($search)->limit(200)->pluck('header_id');

                        return $query->where(fn ($w) => $w->when(strlen($core) >= 4, fn ($w) => $w->orWhere('number', 'like', "%{$core}%"))
                            ->orWhereIn('customer_header_id', $headers));
                    })
                    ->description(fn (CustomerAltNumber $r) => $r->removed_at ? __('সরানো হয়েছে') : null),
                TextColumn::make('customer')->label(__('কাস্টমার'))
                    ->state(fn (CustomerAltNumber $r) => ($c = static::customerOf($r)) ? $c->name.' (ID '.$c->customer_id.')' : '—')
                    ->description(fn (CustomerAltNumber $r) => ($c = static::customerOf($r)) ? trim(($c->mobile ? __('নিজের নম্বর :m', ['m' => $c->mobile]) : '').($c->is_left ? ' · Left' : '')) : null)
                    ->url(fn (CustomerAltNumber $r) => ($c = static::customerOf($r)) ? CustomerResource::getUrl('view', ['record' => $c]) : null),
                TextColumn::make('sources')->label(__('কোথা থেকে'))->badge()
                    ->state(fn (CustomerAltNumber $r) => array_map(fn ($s) => __(AltNumbers::SOURCES[$s] ?? $s), $r->sourceList())),
                TextColumn::make('reached_at')->label(__('রিমাইন্ডার কলে পাওয়া গেছে'))->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M Y')),
                TextColumn::make('updated_at')->label(__('আপডেট'))->sortable()
                    ->formatStateUsing(fn ($state) => $state?->timezone('Asia/Dhaka')->format('d M Y')),
            ])
            ->filters([
                SelectFilter::make('source')->label(__('কোথা থেকে'))->options(array_map('__', AltNumbers::SOURCES))
                    ->query(fn (Builder $query, array $data) => $data['value'] ? $query->where('sources', 'like', '%'.$data['value'].'%') : $query),
                TernaryFilter::make('removed')->label(__('সরানো নম্বর'))->placeholder(__('শুধু চালু'))
                    ->trueLabel(__('শুধু সরানো'))->falseLabel(__('সব'))
                    ->queries(true: fn (Builder $q) => $q->whereNotNull('removed_at'), false: fn (Builder $q) => $q, blank: fn (Builder $q) => $q->whereNull('removed_at')),
            ])
            ->headerActions([
                CreateAction::make()->label(__('নম্বর যোগ করুন'))->modalHeading(__('কাস্টমারের বিকল্প নম্বর যোগ'))
                    ->using(function (array $data) {
                        $n = Sms::mobile((string) $data['number']);
                        if (! $n) {
                            throw ValidationException::withMessages(['mountedActions.0.data.number' => __('সঠিক মোবাইল নম্বর দিন (01XXXXXXXXX)')]);
                        }
                        $row = CustomerAltNumber::firstOrNew(['company_id' => static::company(), 'customer_header_id' => (int) $data['customer_header_id'], 'number' => $n]);
                        $row->fill(['sources' => implode(',', array_unique(['staff', ...$row->sourceList()])), 'added_by' => auth()->id(),
                            'removed_at' => null, 'removed_by' => null, 'seen_at' => now()])->save();

                        return $row;
                    }),
            ])
            ->recordActions([
                Action::make('remove')->label(__('সরিয়ে দিন'))->icon(Heroicon::OutlinedXMark)->color('danger')->requiresConfirmation()
                    ->modalDescription(__('এই নম্বর আর এই কাস্টমারের ধরা হবে না: সার্চে, বটে, রিমাইন্ডার কলে। রাতের আপডেটেও ফিরবে না।'))
                    ->visible(fn (CustomerAltNumber $r) => ! $r->removed_at)
                    ->action(function (CustomerAltNumber $r) {
                        $r->update(['removed_at' => now(), 'removed_by' => auth()->id()]);
                        Notification::make()->success()->title(__('সরিয়ে দেওয়া হলো'))->send();
                    }),
                Action::make('restore')->label(__('ফিরিয়ে আনুন'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (CustomerAltNumber $r) => (bool) $r->removed_at)
                    ->action(fn (CustomerAltNumber $r) => $r->update(['removed_at' => null, 'removed_by' => null])),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('company_id', static::company());
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomerAltNumbers::route('/')];
    }
}
