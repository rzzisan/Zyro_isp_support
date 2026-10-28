<?php

namespace App\Filament\App\Pages;

use App\Models\BillingCustomer;
use App\Models\ExpireDateChange;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * মেয়াদ বদল: billing's Expire Date moved to the day the customer paid after the line was off (engine/expire_dates.py).
 * The one-time bulk list is built here, the owner ticks customers in or out and runs it; the hourly timer's own
 * changes (বিলিং সংযোগ → "মেয়াদ নিজে বদলাবে") show here too. Only the owner writes to billing from this page.
 */
class ExpireDates extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $slug = 'expire-dates';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.app.expire-dates';

    public static function getNavigationLabel(): string
    {
        return __('মেয়াদ বদল');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('বিল দেওয়ার দিনে মেয়াদ (Expire Date) বদল');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return (bool) auth()->user()?->managesCompany($company) && \App\Support\Menu::can('billing')
            && $company?->billingConnection()->exists();
    }

    public static function isOwner(): bool
    {
        return auth()->user()?->roleIn(Filament::getTenant()) === 'owner';
    }

    public function getSubheading(): ?string
    {
        $n = ExpireDateChange::where('company_id', Filament::getTenant()->getKey())->where('kind', 'bulk')
            ->selectRaw('status, count(*) AS n')->groupBy('status')->pluck('n', 'status');

        return __('লাইন ১ দিন বা বেশি বন্ধ থেকে বিল (দেয় টাকার ৮৫% বা বেশি) দিয়ে চালু হলে মেয়াদ হবে বিল দেওয়ার দিন, সর্বোচ্চ ২৮। প্রথমবারের তালিকায় Home কাস্টমার ২০ তারিখের মধ্যে দিলে ৫ দিন বেশি (সর্বোচ্চ ২০)। শুধু Expire Date বদলায়: MikroTik, Zone, SubZone, Box, প্যাকেজ কিছুই না।')
            .' · '.__('তালিকা: বাছাই :p, বাদ :s, হয়েছে :d, ব্যর্থ :f', ['p' => $n['preview'] ?? 0, 's' => $n['skipped'] ?? 0,
                'd' => $n['done'] ?? 0, 'f' => $n['failed'] ?? 0]);
    }

    protected function getHeaderActions(): array
    {
        $company = Filament::getTenant();

        return [
            Action::make('build')->label(__('তালিকা বানান'))->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Desk-এ রাখা বিলিংয়ের কপি থেকে গত ৩৫ দিনের তালিকা আবার বানানো হবে (কপিটা প্রতি রাতে নতুন হয়)। আপনার বাদ দেওয়া কাস্টমাররা বাদই থাকবেন। বিলিং-এ কিছু লেখা হবে না।'))
                ->action(function () use ($company) {
                    try {
                        $r = Engine::expirePreview($company->getKey());
                        Notification::make()->success()->title(__('তালিকায় :n জন', ['n' => $r['rows'] ?? 0]))->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                    }
                }),
            Action::make('run')->label(__('বাছাই করাদের চালান'))->icon(Heroicon::OutlinedPlay)->color('danger')
                ->visible(fn () => static::isOwner())
                ->disabled(fn () => ! $this->selectedCount())
                ->requiresConfirmation()
                ->modalHeading(fn () => __(':n জনের Expire Date বিলিং-এ বদলাবে', ['n' => $this->selectedCount()]))
                ->modalDescription(__('একজন একজন করে বদলানো হবে। প্রতিজনের পরে বিলিংয়ের change log দেখা হবে, Expire Date ছাড়া কিছু বদলালে বা লাইন চালু/বন্ধ হলে সাথে সাথে থেমে যাবে। প্রতিজনের আগের দিন রাখা থাকে, ফেরত আনা যায়।'))
                ->modalSubmitActionLabel(__('হ্যাঁ, চালান'))
                ->action(fn () => $this->start('run')),
        ];
    }

    public function selectedCount(): int
    {
        return ExpireDateChange::where('company_id', Filament::getTenant()->getKey())->where('kind', 'bulk')
            ->where('status', 'preview')->count();
    }

    public function start(string $mode, array $ids = []): void
    {
        abort_unless(static::isOwner(), 403);
        try {
            Engine::expireStart(Filament::getTenant()->getKey(), $mode, $ids);
            Notification::make()->success()->title(__('শুরু হয়েছে, নিচের তালিকায় অবস্থা দেখুন'))->send();
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function table(Table $table): Table
    {
        $company = Filament::getTenant();

        return $table
            ->query(ExpireDateChange::query()->where('company_id', $company->getKey()))
            ->defaultSort('id')
            ->poll('5s')
            ->paginated([50, 100, 500])
            ->columns([
                ToggleColumn::make('selected')->label(__('বাছাই'))
                    ->disabled(fn (ExpireDateChange $record) => ! $record->editable()),
                TextColumn::make('customer_id')->label('ID')->searchable()
                    ->url(fn (ExpireDateChange $record) => ($c = BillingCustomer::where('company_id', $record->company_id)
                        ->where('header_id', $record->customer_header_id)->first())
                        ? \App\Filament\App\Resources\Customers\CustomerResource::getUrl('view', ['record' => $c]) : null),
                TextColumn::make('name')->label(__('নাম'))->searchable()->limit(24),
                TextColumn::make('customer_type')->label(__('ধরন')),
                TextColumn::make('new_day')->label(__('মেয়াদ'))
                    ->formatStateUsing(fn ($state, ExpireDateChange $record) => ($record->old_day ?? '—').' → '.$state),
                TextColumn::make('rule')->label(__('নিয়ম'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __(ExpireDateChange::RULES[$state] ?? $state)),
                TextColumn::make('off_at')->label(__('বন্ধ → চালু'))
                    ->formatStateUsing(fn ($state, ExpireDateChange $record) => $state->timezone('Asia/Dhaka')->format('d M')
                        .' → '.$record->on_at?->timezone('Asia/Dhaka')->format('d M').' ('.$this->hours($record->off_hours).')')
                    ->tooltip(fn (ExpireDateChange $record) => $record->on_remark),
                TextColumn::make('paid_on')->label(__('বিল'))
                    ->formatStateUsing(fn ($state, ExpireDateChange $record) => $state->format('d M').' · '
                        .number_format((float) $record->paid).' ('.$record->paid_pct.'%) · '
                        .($record->is_online ? __('অনলাইন') : ($record->method ?: __('ক্যাশ')))),
                TextColumn::make('status')->label(__('অবস্থা'))->badge()
                    ->formatStateUsing(fn ($state) => __(ExpireDateChange::STATUSES[$state][0] ?? $state))
                    ->color(fn ($state) => ExpireDateChange::STATUSES[$state][1] ?? 'gray')
                    ->description(fn (ExpireDateChange $record) => $record->error ? mb_substr($record->error, 0, 120) : null),
                TextColumn::make('kind')->label(__('কোথা থেকে'))->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state === 'bulk' ? __('প্রথমবারের তালিকা') : __('নিজে (timer)')),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('কোথা থেকে'))->default('bulk')
                    ->options(['bulk' => __('প্রথমবারের তালিকা'), 'auto' => __('নিজে (timer)')]),
                SelectFilter::make('status')->label(__('অবস্থা'))
                    ->options(collect(ExpireDateChange::STATUSES)->map(fn ($s) => __($s[0]))->all()),
                SelectFilter::make('type')->label(__('ধরন'))
                    ->options(['student' => 'Student', 'home' => 'Home', 'other' => __('অন্য')])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'student' => $q->whereRaw("lower(customer_type) LIKE 'student%'"),
                        'home' => $q->whereRaw("lower(customer_type) LIKE 'home%'"),
                        'other' => $q->whereRaw("coalesce(lower(customer_type), '') NOT LIKE 'student%' AND coalesce(lower(customer_type), '') NOT LIKE 'home%'"),
                        default => $q,
                    }),
                SelectFilter::make('rule')->label(__('নিয়ম'))
                    ->options(collect(ExpireDateChange::RULES)->map(fn ($r) => __($r))->all()),
                TernaryFilter::make('is_online')->label(__('অনলাইনে দিয়েছেন')),
                SelectFilter::make('shift')->label(__('কত দিন সরবে'))
                    ->options(['1-2' => __('১-২ দিন'), '3-7' => __('৩-৭ দিন'), '8+' => __('৮ দিনের বেশি')])
                    ->query(function (Builder $q, array $data) {
                        $range = ['1-2' => [1, 2], '3-7' => [3, 7], '8+' => [8, 31]][$data['value'] ?? ''] ?? null;

                        return $range ? $q->whereRaw('abs(new_day - coalesce(old_day, new_day)) BETWEEN ? AND ?', $range) : $q;
                    }),
            ])
            ->recordActions([
                Action::make('one')->label(__('শুধু এটা চালান'))->icon(Heroicon::OutlinedPlay)->color('warning')
                    ->visible(fn (ExpireDateChange $record) => static::isOwner()
                        && in_array($record->status, ['preview', 'skipped', 'failed'], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (ExpireDateChange $record) => __(':name: মেয়াদ :old → :new', ['name' => $record->name,
                        'old' => $record->old_day ?? '—', 'new' => $record->new_day]))
                    ->action(fn (ExpireDateChange $record) => $this->start('one', [$record->id])),
                Action::make('rollback')->label(__('আগের দিনে ফেরত'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (ExpireDateChange $record) => static::isOwner() && $record->status === 'done' && $record->old_day)
                    ->requiresConfirmation()
                    ->modalHeading(fn (ExpireDateChange $record) => __(':name: মেয়াদ আবার :old', ['name' => $record->name, 'old' => $record->old_day]))
                    ->action(fn (ExpireDateChange $record) => $this->start('rollback', [$record->id])),
            ])
            ->toolbarActions([
                BulkAction::make('tick')->label(__('বাছাই করুন'))->icon(Heroicon::OutlinedCheck)
                    ->action(fn (Collection $records) => $this->tick($records, true))->deselectRecordsAfterCompletion(),
                BulkAction::make('untick')->label(__('বাদ দিন'))->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->action(fn (Collection $records) => $this->tick($records, false))->deselectRecordsAfterCompletion(),
                BulkAction::make('rollback_many')->label(__('আগের দিনে ফেরত'))->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn () => static::isOwner())->requiresConfirmation()
                    ->action(fn (Collection $records) => $this->start('rollback',
                        $records->where('status', 'done')->pluck('id')->all()))->deselectRecordsAfterCompletion(),
            ]);
    }

    public function tick(Collection $records, bool $in): void
    {
        $ids = $records->filter(fn (ExpireDateChange $r) => $r->editable())->pluck('id');
        ExpireDateChange::whereIn('id', $ids)->where('company_id', Filament::getTenant()->getKey())
            ->whereIn('status', ['preview', 'skipped'])->update(['status' => $in ? 'preview' : 'skipped', 'updated_at' => now()]);
        Notification::make()->success()->title(($in ? __('বাছাই হলো: ') : __('বাদ দেওয়া হলো: ')).$ids->count())->send();
    }

    private function hours(?int $h): string
    {
        return $h === null ? '' : ($h >= 48 ? __(':n দিন', ['n' => intdiv($h, 24)]) : __(':n ঘণ্টা', ['n' => $h]));
    }
}
