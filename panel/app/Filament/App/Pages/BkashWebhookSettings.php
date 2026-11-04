<?php

namespace App\Filament\App\Pages;

use App\Models\BkashWebhook;
use App\Models\Company;
use App\Models\Membership;
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
use RuntimeException;

/**
 * bKash merchant webhook straight into the Desk (engine/bkash_webhook.py). bKash allows one webhook per wallet, so the
 * day bKash moves the URL here the billing software stops hearing about these payments; with "বিলিং-এ জমা" on, the
 * Desk puts each one into billing as an approved bKash bill.
 */
class BkashWebhookSettings extends CompanySettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('bKash Webhook');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('bKash Webhook (মার্চেন্ট পেমেন্ট)');
    }

    public static function canAccess(): bool
    {
        return parent::canAccess() && \App\Support\Menu::can('billing');
    }

    protected function record(Company $company): Model
    {
        return BkashWebhook::forCompany($company);
    }

    protected function fillData(Model $record): array
    {
        return ['enabled' => (bool) $record->enabled, 'merchant_number' => $record->merchant_number,
            'settle_in_billing' => (bool) $record->settle_in_billing, 'settle_user_id' => $record->settle_user_id,
            'enable_line' => $record->enable_line ?? true];
    }

    /** Members whose billing login can post and approve the bills: their own login, or an owner on the company login. */
    public function settleUsers(): array
    {
        return Membership::where('company_id', Filament::getTenant()->getKey())
            ->where(fn ($q) => $q->where('role', 'owner')->orWhere(fn ($q) => $q->whereNotNull('billing_username')->whereNotNull('billing_password')))
            ->with('user')->get()
            ->mapWithKeys(fn (Membership $m) => [$m->user_id => $m->user?->name.($m->hasBillingLogin() ? ' ('.$m->billing_username.')' : ' ('.__('কোম্পানির লগইন').')')])
            ->all();
    }

    public function form(Schema $schema): Schema
    {
        $company = Filament::getTenant();
        $record = $this->record($company);

        return $schema->components([
            Section::make(__('bKash-কে যে URL দেবেন'))
                ->description(__('bKash technical support এই URL আপনার wallet-এর webhook-এ subscribe করবে। এক wallet-এ একটাই webhook হয়: subscribe হলে বিলিং সফটওয়্যার আর এই পেমেন্টের খবর পাবে না।'))
                ->schema([
                    Placeholder::make('url')->label(__('Webhook URL'))->content(BkashWebhook::url($company)),
                    Placeholder::make('state')->label(__('অবস্থা'))->content(fn () => $this->stateText($record)),
                ]),
            Toggle::make('enabled')->label(__('bKash Webhook চালু'))
                ->helperText(__('চালু থাকলে bKash-এর প্রথম subscription নিজে থেকেই নিশ্চিত হয় আর প্রতিটা পেমেন্ট অনলাইন পেমেন্ট পেজে আসে। বন্ধ থাকলে মেসেজ শুধু রাখা হয়।')),
            TextInput::make('merchant_number')->label(__('আমাদের bKash মার্চেন্ট নম্বর'))->maxLength(20)
                ->helperText(__('দিলে শুধু এই নম্বরে আসা পেমেন্ট নেওয়া হবে।')),
            Section::make(__('বিলিং সফটওয়্যারে জমা'))
                ->description(__('কাস্টমার চেনা গেলে (রেফারেন্সে কাস্টমার আইডি, না হলে পাঠানো নম্বর) পেমেন্টটা বিলিং-এ bKash বিল হিসেবে TrxID সহ জমা আর সাথে সাথে approve হয়। চেনা না গেলে বা জমা না হলে অনলাইন পেমেন্ট পেজে স্টাফ হাতে জমা দেবেন।'))
                ->schema([
                    Toggle::make('settle_in_billing')->label(__('পেমেন্ট নিজে বিলিং-এ জমা হবে')),
                    Select::make('settle_user_id')->label(__('কার বিলিং লগইন দিয়ে জমা ও approve হবে'))
                        ->options(fn () => $this->settleUsers())->requiredIf('settle_in_billing', true)
                        ->helperText(__('এই লগইনের বিলিং-এ বিল নেওয়া আর approve করার অনুমতি থাকতে হবে।')),
                    Toggle::make('enable_line')->label(__('বন্ধ লাইন চালু করবে'))
                        ->helperText(__('বিল জমার পরও লাইন বন্ধ থাকলে, আর পেমেন্ট বকেয়ার অন্তত বিলিং সংযোগ পেজের "কত %" হলে।')),
                ]),
        ]);
    }

    public function stateText(BkashWebhook $r): string
    {
        $parts = [];
        $parts[] = $r->topic_arn ? '✅ '.__('subscribe হয়েছে').' ('.$r->confirmed_at?->timezone('Asia/Dhaka')->format('d M Y, g:i A').')' : __('এখনো subscribe হয়নি');
        if ($r->pending_topic_arn) {
            $parts[] = '⏳ '.__('নিশ্চিত করা বাকি: :t', ['t' => $r->pending_topic_arn]);
        }
        $parts[] = $r->last_message_at ? __('শেষ পেমেন্টের খবর :t', ['t' => $r->last_message_at->diffForHumans()]) : __('কোনো পেমেন্টের খবর আসেনি');
        $day = DB::table('bkash_webhook_messages')->where('company_id', Filament::getTenant()->getKey())
            ->where('received_at', '>', now()->subDay())->selectRaw('result, count(*) AS n')->groupBy('result')->pluck('n', 'result');
        if ($day->isNotEmpty()) {
            $parts[] = __('গত ২৪ ঘণ্টা: ').$day->map(fn ($n, $k) => $k.' '.$n)->implode(', ');
        }

        return implode(' · ', $parts);
    }

    protected function beforeSaving(array $data, Model $record): array
    {
        $data['company_id'] = Filament::getTenant()->id;
        $data['merchant_number'] = filled($data['merchant_number'] ?? null) ? preg_replace('/\D/', '', $data['merchant_number']) : null;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')->label(__('subscription নিশ্চিত করুন'))->icon(Heroicon::OutlinedCheckBadge)
                ->visible(fn () => filled($this->record(Filament::getTenant())->pending_subscribe_url))
                ->requiresConfirmation()
                ->modalDescription(fn () => __('এই topic থেকে আসা পেমেন্টই শুধু নেওয়া হবে: :t', ['t' => $this->record(Filament::getTenant())->pending_topic_arn]))
                ->action(function () {
                    try {
                        Engine::confirmBkashWebhook(Filament::getTenant()->getKey());
                        Notification::make()->success()->title(__('subscribe নিশ্চিত হয়েছে'))->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title(__('নিশ্চিত হয়নি'))->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
