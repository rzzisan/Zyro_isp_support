<?php

namespace App\Filament\App;

use App\Filament\App\Resources\SmsCampaigns\SmsCampaignResource;
use App\Models\BillingCustomer;
use App\Models\BillingOption;
use App\Models\SmsSetting;
use App\Models\SmsTemplate;
use App\Services\Engine;
use App\Support\Menu;
use App\Support\Sms;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * "SMS পাঠান": to picked customers / typed numbers, or to everyone matching filters on the desk's own customer copy.
 * Only the queue is written here; the engine sends in the background through the company's SMS gateway.
 */
class SmsActions
{
    private const COLUMNS = ['header_id', 'customer_id', 'name', 'mobile', 'mobile_normalized', 'username', 'package',
        'monthly_bill', 'due', 'zone', 'bill_day'];

    private static function company(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    private static function searchCustomers(string $search): array
    {
        $search = trim($search);
        $digits = preg_replace('/\D/', '', $search);

        return BillingCustomer::where('company_id', static::company())->whereNull('gone_at')
            ->where(fn ($w) => $w->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$search])
                ->orWhere('username', 'ilike', "%{$search}%")->orWhere('name', 'ilike', "%{$search}%")
                ->when(strlen($digits) >= 5, fn ($x) => $x->orWhere('mobile', 'like', "%{$digits}%")))
            ->orderByRaw("ltrim(customer_id, '0') = ltrim(?, '0') DESC", [$search])->orderBy('customer_id')->limit(15)
            ->get(['header_id', 'customer_id', 'name', 'mobile', 'username', 'zone', 'subzone', 'box', 'is_left', 'left_on'])
            ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])
            ->all();
    }

    /** Values our current customers have in a column, plus the desk's copy of the billing list. */
    private static function listOptions(string $kind, string $column): array
    {
        $used = BillingCustomer::where('company_id', static::company())->whereNull('gone_at')->whereNotNull($column)
            ->distinct()->pluck($column);

        return BillingOption::names(static::company(), $kind, $used);
    }

    public static function filters(array $data): array
    {
        return array_filter(array_intersect_key($data, array_flip(['list', 'zone', 'subzone', 'box', 'package',
            'connection_type', 'status', 'due', 'due_min', 'line', 'online'])), fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Who gets it: [mobile => customer] and bare numbers. Single: picked customers + typed numbers (a typed number that
     * belongs to a customer gets that customer's placeholders). Bulk: the filters.
     */
    public static function resolve(array $data): array
    {
        $cid = static::company();
        $skipped = 0;
        $bad = [];
        if (($data['mode'] ?? 'single') === 'bulk') {
            [$customers, $skipped] = Sms::byMobile(Sms::recipients($cid, static::filters($data))->orderBy('customer_id')->get(self::COLUMNS));

            return ['customers' => $customers, 'numbers' => [], 'skipped' => $skipped, 'bad' => []];
        }
        $picked = BillingCustomer::where('company_id', $cid)->whereIn('header_id', array_map('intval', (array) ($data['customers'] ?? [])))
            ->orderBy('customer_id')->get(self::COLUMNS);
        [$customers, $skipped] = Sms::byMobile($picked);
        [$numbers, $bad] = Sms::numbers($data['numbers'] ?? '');
        $numbers = array_values(array_diff($numbers, array_keys($customers)));
        if ($numbers) {
            $known = BillingCustomer::where('company_id', $cid)->whereNull('gone_at')->whereIn('mobile_normalized', $numbers)
                ->orderBy('is_left')->orderBy('customer_id')->get(self::COLUMNS);
            foreach ($known as $c) {
                if (in_array($c->mobile_normalized, $numbers, true) && ! isset($customers[$c->mobile_normalized])) {
                    $customers[$c->mobile_normalized] = $c;
                }
            }
            $numbers = array_values(array_diff($numbers, array_keys($customers)));
        }

        return ['customers' => $customers, 'numbers' => $numbers, 'skipped' => $skipped, 'bad' => $bad];
    }

    public static function send(?BillingCustomer $customer = null): Action
    {
        return Action::make($customer ? 'sendSmsToCustomer' : 'sendSms')->label(__('SMS পাঠান'))->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->visible(fn () => Menu::can('sms'))
            ->modalHeading($customer ? "{$customer->name} (ID {$customer->customer_id}): SMS" : __('SMS পাঠান'))
            ->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitActionLabel(__('পাঠান'))
            ->fillForm(fn () => ['mode' => 'single', 'customers' => $customer ? [(string) $customer->header_id] : [],
                'list' => 'current'])
            ->schema([
                static::modeField($customer),
                Group::make([
                    ...static::recipientFields($customer),
                    Select::make('template')->label(__('টেমপ্লেট'))->placeholder(__('টেমপ্লেট বাছুন (ঐচ্ছিক)'))->live()
                        ->options(fn () => SmsTemplate::where('company_id', static::company())->where('type', 'general')
                            ->orderBy('sort')->orderBy('name')->pluck('name', 'id'))
                        ->afterStateUpdated(function ($state, callable $set) {
                            $t = $state ? SmsTemplate::where('company_id', static::company())->find($state) : null;
                            if ($t) {
                                $set('body', $t->body);
                            }
                        }),
                    View::make('filament.app.sms.placeholders')->viewData(['items' => Sms::placeholders()]),
                    Textarea::make('body')->label(__('লেখা'))->required()->rows(5)->maxLength(1000)->live(debounce: 500)
                        ->extraInputAttributes(['data-sms-body' => 'true'])
                        ->partiallyRenderComponentsAfterStateUpdated(['summary']),
                    Placeholder::make('summary')->hiddenLabel()->key('summary')
                        ->content(fn ($get) => view('filament.app.sms.summary', static::summary($get))),
                    Checkbox::make('confirm')->label(fn ($get) => __('হ্যাঁ, এই সবাইকে SMS পাঠাতে চাই'))
                        ->visible(fn ($get) => $get('mode') === 'bulk')->accepted()
                        ->validationMessages(['accepted' => __('পাঠানোর আগে টিক দিন')]),
                ])->key('smsBody'),
            ])
            ->action(function (array $data, Action $action) use ($customer) {
                if ($customer) {
                    $data['mode'] = 'single';
                    $data['customers'] = [(string) $customer->header_id];
                }
                $r = static::resolve($data);
                if (! $r['customers'] && ! $r['numbers']) {
                    Notification::make()->warning()->title(__('কাউকে পাওয়া যায়নি'))->body(__('সঠিক মোবাইল নম্বর আছে এমন কেউ নেই।'))->send();
                    $action->halt();

                    return;
                }
                $body = trim($data['body']);
                $campaign = Sms::queue(static::company(), auth()->id(), $data['mode'] === 'bulk' ? 'bulk' : 'single', $body,
                    $r['customers'], $r['numbers'], $data['mode'] === 'bulk' ? static::filters($data) : []);
                Engine::kickSms(static::company());
                $settings = SmsSetting::forCompany(static::company());
                $n = number_format($campaign->total);
                Notification::make()->success()->title(__(':nটি SMS পাঠানোর তালিকায় উঠেছে', ['n' => $n]))
                    ->body($settings->enabled && $settings->configured()
                        ? __('পেছনে পাঠানো হচ্ছে, অগ্রগতি "SMS পাঠান" পেজে।')
                        : __('SMS গেটওয়ে এখনো চালু নয় (সেটিংস → SMS গেটওয়ে); চালু হলে পাঠানো শুরু হবে।'))
                    ->actions([Action::make('progress')->label(__('অগ্রগতি দেখুন'))->button()->url(SmsCampaignResource::getUrl('index'))])
                    ->send();
            });
    }

    /**
     * "কাকে": picked customers / typed numbers or filters. Changing it re-renders the group keyed 'smsBody' (the
     * form's body under it), shared by the WhatsApp template form.
     */
    public static function modeField(?BillingCustomer $customer = null): Radio
    {
        return Radio::make('mode')->label(__('কাকে'))->inline()->live()->hidden((bool) $customer)
            ->partiallyRenderComponentsAfterStateUpdated(['smsBody'])
            ->options(['single' => __('নির্দিষ্ট কাস্টমার / নম্বর'), 'bulk' => __('ফিল্টার দিয়ে অনেককে')]);
    }

    /** The customer / number pickers and the bulk filters; changes re-render the component keyed 'summary'. */
    public static function recipientFields(?BillingCustomer $customer = null): array
    {
        return [
            Group::make([
                Select::make('customers')->label(__('কাস্টমার'))->multiple()->searchable()->searchDebounce(300)->live()
                    ->hidden((bool) $customer)
                    ->partiallyRenderComponentsAfterStateUpdated(['summary'])
                    ->placeholder(__('কাস্টমার ID, মোবাইল, PPPoE বা নাম লিখুন'))
                    ->getSearchResultsUsing(fn (string $search) => static::searchCustomers($search))
                    ->getOptionLabelsUsing(fn (array $values) => BillingCustomer::where('company_id', static::company())
                        ->whereIn('header_id', array_map('intval', $values))->get()
                        ->mapWithKeys(fn ($c) => [(string) $c->header_id => BillingCustomer::searchLabel($c)])->all()),
                Textarea::make('numbers')->label(__('অন্য নম্বর'))->rows(2)->live(debounce: 600)->hidden((bool) $customer)
                    ->partiallyRenderComponentsAfterStateUpdated(['summary'])
                    ->placeholder(__('01XXXXXXXXX, কমা বা নতুন লাইন দিয়ে কয়েকটা')),
            ])->visible(fn ($get) => $get('mode') !== 'bulk'),
            Grid::make(4)->visible(fn ($get) => $get('mode') === 'bulk')->schema([
                Select::make('list')->label(__('তালিকা'))->selectablePlaceholder(false)->live()
                    ->options(['current' => __('বর্তমান কাস্টমার'), 'left' => __('Left কাস্টমার'), 'all' => __('সব')]),
                Select::make('zone')->label('Zone')->multiple()->searchable()->live()
                    ->options(fn () => static::listOptions('zones', 'zone')),
                Select::make('subzone')->label('Subzone')->multiple()->searchable()->live()
                    ->options(fn () => static::listOptions('subzones', 'subzone')),
                Select::make('box')->label('Box')->multiple()->searchable()->live()
                    ->options(fn () => static::listOptions('boxes', 'box')),
                Select::make('package')->label(__('প্যাকেজ'))->multiple()->searchable()->live()
                    ->options(fn () => static::listOptions('packages', 'package')),
                Select::make('connection_type')->label('Connection Type')->multiple()->live()
                    ->options(fn () => static::listOptions('connection_types', 'connection_type')),
                Select::make('status')->label(__('অবস্থা'))->multiple()->live()
                    ->options(fn () => static::listOptions('customer_statuses', 'status')),
                Select::make('line')->label(__('লাইন'))->live()->placeholder(__('সব'))
                    ->options(['on' => __('চালু'), 'off' => __('বন্ধ')]),
                Select::make('due')->label(__('বকেয়া'))->live()->placeholder(__('সব'))
                    ->options(['due' => __('বকেয়া আছে'), 'paid' => __('বকেয়া নেই')]),
                TextInput::make('due_min')->label(__('বকেয়া কমপক্ষে'))->numeric()->minValue(0)->suffix(__('টাকা'))
                    ->live(debounce: 600),
                Select::make('online')->label(__('এখন'))->live()->placeholder(__('সব'))
                    ->options(['online' => __('অনলাইন'), 'offline' => __('অফলাইন')]),
            ]),
        ];
    }

    /** The recipient part of the form state (for resolve()) while it is being filled. */
    public static function formData(callable $get): array
    {
        $data = ['mode' => $get('mode') ?? 'single', 'customers' => $get('customers') ?? [], 'numbers' => $get('numbers')];
        foreach (['list', 'zone', 'subzone', 'box', 'package', 'connection_type', 'status', 'due', 'due_min', 'line', 'online'] as $k) {
            $data[$k] = $get($k);
        }

        return $data;
    }

    /** What the summary box under the text shows (count, parts, cost, preview, gateway state). */
    public static function summary(callable $get): array
    {
        $body = (string) $get('body');
        $r = static::resolve(static::formData($get));
        $est = Sms::estimate(static::company(), $body, $r['customers'], $r['numbers']);
        $preview = [];
        $extra = ['company' => Sms::companyName(static::company())];
        foreach (array_slice($r['customers'], 0, 3, true) as $mobile => $c) {
            $preview[] = ['to' => trim("{$c->name} · {$mobile}"), 'text' => Sms::render($body, $c, $extra)];
        }
        foreach (array_slice($r['numbers'], 0, max(0, 3 - count($preview))) as $mobile) {
            $preview[] = ['to' => $mobile, 'text' => Sms::render($body, null, $extra)];
        }

        return ['est' => $est, 'skipped' => $r['skipped'], 'bad' => $r['bad'], 'length' => Sms::parts($body),
            'billOnly' => Sms::billOnly($body),
            'preview' => $body !== '' ? $preview : [], 'settings' => SmsSetting::forCompany(static::company())];
    }
}
