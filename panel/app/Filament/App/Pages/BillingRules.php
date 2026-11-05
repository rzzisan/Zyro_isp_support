<?php

namespace App\Filament\App\Pages;

use App\Models\BillingSetting;
use App\Models\Company;
use App\Services\Engine;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use RuntimeException;

/**
 * বিলিং নিয়ম (plan 2.2): the company's own billing rules, owner only. Phase 0: the rules are saved and every change is
 * kept (billing_settings_log + activity log); nothing acts on them while billing_mode is 'ispdigital'. Also shows the
 * Desk ledger copied from billing (engine/desk_billing.py) and how well it matches billing's due.
 */
class BillingRules extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'billing-rules';

    public static function getNavigationLabel(): string
    {
        return __('বিলিং নিয়ম');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('বিলিং নিয়ম (Desk-এর নিজের বিলিং)');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company && auth()->user()?->roleIn($company) === 'owner';
    }

    protected function record(Company $company): Model
    {
        return BillingSetting::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        return collect(BillingSetting::DEFAULTS)->map(fn ($d, $k) => $record->{$k} ?? $d)->all();
    }

    public function form(Schema $schema): Schema
    {
        $record = $this->record(Filament::getTenant());
        $pct = fn (string $name, string $label) => TextInput::make($name)->label($label)->numeric()->integer()->minValue(0)->maxValue(100)->suffix('%')->required();

        return $schema->components([
            Section::make(__('মূল বিলিং কোনটা'))
                ->description(__('হস্তান্তরের আগে পর্যন্ত বিলিং সফটওয়্যারই মূল। নিচের নিয়মগুলো এখন শুধু রাখা হচ্ছে, Desk এখনো এগুলো দিয়ে কিছু করে না।'))
                ->schema([
                    Placeholder::make('mode')->label(__('এখন'))->content(fn () => __(BillingSetting::MODES[$record->billing_mode ?? 'ispdigital'] ?? $record->billing_mode)),
                    Placeholder::make('ledger')->label(__('Desk-এর খাতা (বিলিং থেকে কপি)'))->content(fn () => $this->ledgerText()),
                    TextInput::make('history_months')->label(__('কত মাসের বিল আর পেমেন্ট খাতায় আসবে'))->numeric()->integer()->minValue(1)->maxValue(36)->suffix(__('মাস'))->required()
                        ->helperText(__('এর আগের হিসাব প্রতিটা কাস্টমারের একটা "আগের হিসাব" সারিতে থাকবে।')),
                ]),
            Section::make(__('১. মাসিক বিল'))->schema([
                Select::make('bill_generation')->label(__('মাসিক বিল কখন তৈরি হবে'))->required()->selectablePlaceholder(false)
                    ->options(['month_start' => __('মাসের ১ তারিখে সবার'), 'expire_date' => __('প্রত্যেকের Expire Date-এ')]),
                Toggle::make('waive_off_month')->label(__('২. লাইন বন্ধ থেকে মাস পার হলে আর কোনো টাকা না এলে সে মাসের বিল শূন্য')),
            ]),
            Section::make(__('৩. বিল সংশোধন, ক্যাশ পেমেন্ট মোছা, অনলাইন পেমেন্ট ট্রান্সফার'))
                ->description(__('মালিক সবসময় পারবেন। অন্য কাউকে দিতে টিম বা পারমিশন গ্রুপ পেজে "বিল সংশোধন", "ক্যাশ পেমেন্ট মোছা", "অনলাইন পেমেন্ট ট্রান্সফার" টিক দিন। অনলাইন পেমেন্ট কখনো মোছা যাবে না, শুধু অন্য ID-তে ট্রান্সফার।'))
                ->schema([]),
            Section::make(__('৪-৫. বিল নেওয়া আর Expire Date'))->columns(2)->schema([
                Toggle::make('desk_auto_approve')->label(__('৪. Desk-এ নেওয়া বিল সাথে সাথে approve'))->columnSpanFull(),
                Toggle::make('expire_on_payment')->label(__('৫. পেমেন্টে Expire Date বদলাবে'))->columnSpanFull(),
                $pct('expire_full_percent', __('Full পেমেন্ট ধরা হবে বকেয়ার কত % দিলে')),
                TextInput::make('expire_max_days')->label(__('সর্বোচ্চ কত দিন সরবে'))->numeric()->integer()->minValue(0)->maxValue(62)->suffix(__('দিন'))->required(),
                Toggle::make('expire_keep_if_early')->label(__('Expire Date পার হওয়ার আগে দিলে তারিখ বদলাবে না'))->columnSpanFull(),
                Toggle::make('online_expire')->label(__('৭. অনলাইন পেমেন্টে Expire Date এখনকার নিয়মে (≥১ দিন বন্ধ, Full পেমেন্ট)'))->columnSpanFull(),
            ]),
            Section::make(__('৬. Payment Status Wise লাইন বন্ধ/চালু'))->columns(3)->schema([
                Toggle::make('auto_line')->label(__('চালু'))->columnSpanFull(),
                Select::make('disable_when')->label(__('বিল না দিলে কবে বন্ধ'))->required()->selectablePlaceholder(false)->live()
                    ->options(['same_day' => __('Expire-এর দিনেই'), 'next_day' => __('Expire-এর পরের দিন'), 'after_days' => __('Expire-এর N দিন পর')]),
                TextInput::make('disable_after_days')->label(__('N (দিন)'))->numeric()->integer()->minValue(1)->maxValue(30)->required()
                    ->visible(fn ($get) => $get('disable_when') === 'after_days'),
                Select::make('disable_hour')->label(__('কোন ঘণ্টায় (বাংলাদেশ সময়)'))->required()->selectablePlaceholder(false)
                    ->options(collect(range(0, 23))->mapWithKeys(fn ($h) => [$h => sprintf('%02d:00', $h)])->all()),
            ]),
            Section::make(__('৮-১০. MikroTik আর আংশিক পেমেন্ট'))->columns(2)->schema([
                TextInput::make('mikrotik_retries')->label(__('৮. MikroTik-এ কাজ যাচাই ব্যর্থ হলে কতবার আবার চেষ্টা'))->numeric()->integer()->minValue(0)->maxValue(10)->required(),
                $pct('enable_min_percent', __('৯. লাইন চালুর জন্য বকেয়ার কমপক্ষে কত % দিতে হবে')),
                $pct('partial_redisable_percent', __('১০. আংশিক দিয়ে চালু হলে মাসিক বিলের কত %-এর বেশি বাকি থাকলে আবার বন্ধ')),
                TextInput::make('partial_redisable_days')->label(__('কত দিন পর'))->numeric()->integer()->minValue(1)->maxValue(30)->suffix(__('দিন'))->required(),
            ]),
            Section::make(__('কাজের লগ'))->schema([
                TextInput::make('activity_log_months')->label(__('কাজের লগ কত মাস রাখা হবে'))->numeric()->integer()->minValue(1)->maxValue(120)->suffix(__('মাস'))
                    ->placeholder(__('খালি = সবসময়'))->helperText(__('পুরনো মাস archive হবে, মোছা হবে না।')),
            ]),
        ]);
    }

    /** The last copy from billing: when, how much, how well the Desk's due matches billing's. */
    public function ledgerText(): HtmlString|string
    {
        $run = DB::table('desk_billing_runs')->where('company_id', Filament::getTenant()->getKey())->where('kind', 'import')
            ->orderByDesc('id')->first();
        if (! $run) {
            return __('এখনো কপি হয়নি। প্রতিদিন সকাল ৭:১৫-এ নিজে হয়, বা উপরের "এখনই কপি" চাপুন।');
        }
        $when = \Illuminate\Support\Carbon::parse($run->started_at)->timezone('Asia/Dhaka')->format('d M Y, g:i A');
        if ($run->status === 'running') {
            return __('কপি চলছে (শুরু :t)…', ['t' => $when]);
        }
        if ($run->status === 'failed') {
            return __('শেষ কপি (:t) হয়নি: :e', ['t' => $when, 'e' => $run->error]);
        }
        $s = json_decode($run->stats ?? '{}', true) ?: [];
        $n = fn ($v) => number_format((float) ($v ?? 0));
        $lines = [
            __('শেষ কপি :t, :m থেকে', ['t' => $when, 'm' => $s['window_start'] ?? '—']),
            __(':c কাস্টমার (:a Active), :p প্যাকেজ', ['c' => $n($s['customers'] ?? 0), 'a' => $n($s['active'] ?? 0), 'p' => $n($s['packages'] ?? 0)]),
            __(':b বিল (:bt টাকা), :y পেমেন্ট (:yt টাকা)', ['b' => $n($s['bills'] ?? 0), 'bt' => $n($s['bills_total'] ?? 0), 'y' => $n($s['payments'] ?? 0), 'yt' => $n($s['payments_total'] ?? 0)]),
            __('বকেয়া মিল: :m / :h জন (:pc%)। মোট বকেয়া Desk :d, বিলিং :bd টাকা', ['m' => $n($s['matched'] ?? 0), 'h' => $n($s['with_history'] ?? 0),
                'pc' => $s['match_percent'] ?? '—', 'd' => $n($s['desk_due'] ?? 0), 'bd' => $n($s['billing_due'] ?? 0)]),
        ];
        if (($s['customers'] ?? 0) > ($s['with_history'] ?? 0)) {
            $lines[] = __(':x জনের বিলের ইতিহাস এখনো বিলিং থেকে আসেনি (রাতের কপিতে আসবে)', ['x' => $n(($s['customers'] ?? 0) - ($s['with_history'] ?? 0))]);
        }

        return new HtmlString(implode('<br>', array_map('e', $lines)));
    }

    /** The rules as they were before this save. */
    protected array $before = [];

    protected function beforeSaving(array $data, Model $record): array
    {
        $this->before = $record->exists ? $record->getAttributes() : [];
        $data['company_id'] = Filament::getTenant()->getKey();
        unset($data['billing_mode']);   // changes only at the cutover, not from this form
        if (($data['activity_log_months'] ?? '') === '') {
            $data['activity_log_months'] = null;
        }

        return $data;
    }

    /** Every changed rule into billing_settings_log (the activity log gets the same change from the model event). */
    protected function afterSaved(Model $record): void
    {
        foreach ($record->getChanges() as $field => $new) {
            if (in_array($field, ['updated_at', 'created_at', 'id', 'company_id'], true)) {
                continue;
            }
            $old = $this->before[$field] ?? null;
            DB::table('billing_settings_log')->insert(['company_id' => $record->company_id, 'user_id' => auth()->id(),
                'field' => $field, 'old_value' => is_bool($old) ? ($old ? '1' : '0') : $old,
                'new_value' => is_bool($new) ? ($new ? '1' : '0') : $new, 'created_at' => now()]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('import')->label(__('এখনই কপি'))->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription(__('বিলিং-এর কাস্টমার, প্যাকেজ, বিল আর পেমেন্টের Desk-এ থাকা কপি থেকে খাতা নতুন করে ভরা হবে। বিলিং-এ কিছু লেখা হয় না।'))
                ->action(function () {
                    try {
                        Engine::deskBillingImport(Filament::getTenant()->getKey());
                        Notification::make()->success()->title(__('কপি শুরু হয়েছে'))->body(__('এক-দুই মিনিট পর পেজটা আবার খুলুন।'))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('কপি শুরু হয়নি'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
