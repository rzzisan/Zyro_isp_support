<?php

namespace App\Filament\App;

use App\Filament\App\Resources\WaCampaigns\WaCampaignResource;
use App\Models\BillingCustomer;
use App\Services\Engine;
use App\Support\Menu;
use App\Support\WaTemplates;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * "WhatsApp পাঠান": an approved WhatsApp template to picked customers / typed numbers, or to everyone matching the
 * same filters as SMS. Staff choose what goes into each {{n}}. Only the queue is written here; engine/wa_campaign.py
 * sends with the company's own token.
 */
class WaCampaignActions
{
    public const MAX_PARAMS = 10;

    private static ?string $error = null;

    private static function company(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    /** The approved templates ([] with the reason kept in $error when Meta can't be asked). */
    public static function templates(): array
    {
        try {
            static::$error = null;

            return WaTemplates::templates(static::company());
        } catch (RuntimeException $e) {
            static::$error = $e->getMessage();

            return [];
        }
    }

    public static function template(?string $key): ?array
    {
        return $key ? (static::templates()[$key] ?? null) : null;
    }

    /** [{field} | {field: _text, text}] for {{1}}..{{n}} from the form state. */
    public static function mapping(callable|array $get, int $count): array
    {
        $v = fn (string $k) => is_array($get) ? ($get[$k] ?? null) : $get($k);
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $f = $v("p{$i}");
            $out[] = $f === '_text' ? ['field' => '_text', 'text' => (string) $v("p{$i}_text")] : ['field' => $f];
        }

        return $out;
    }

    /** Field defaults for a template: what was used last time, else name, ID, package, expire date in that order. */
    private static function fill(?array $t, callable $set): void
    {
        if (! $t) {
            return;
        }
        $last = WaTemplates::lastMapping(static::company(), $t['name'], $t['language']);
        $guess = ['name', 'id', 'package', 'expire_date', 'due', 'company'];
        for ($i = 1; $i <= static::MAX_PARAMS; $i++) {
            $p = $last[$i - 1] ?? null;
            $set("p{$i}", $p['field'] ?? ($i <= $t['params'] ? ($guess[$i - 1] ?? null) : null));
            $set("p{$i}_text", $p['text'] ?? null);
        }
    }

    public static function send(?BillingCustomer $customer = null): Action
    {
        $slots = [];
        for ($i = 1; $i <= static::MAX_PARAMS; $i++) {
            $slots[] = Grid::make(2)->visible(fn ($get) => (static::template($get('template'))['params'] ?? 0) >= $i)->schema([
                Select::make("p{$i}")->label('{{'.$i.'}}')->options(fn () => WaTemplates::fields())->live()
                    ->required(fn ($get) => (static::template($get('template'))['params'] ?? 0) >= $i)
                    ->validationMessages(['required' => __('প্রতিটা ঘরে কী বসবে বাছুন')]),
                TextInput::make("p{$i}_text")->label(__('লেখা'))->maxLength(500)->live(debounce: 600)
                    ->partiallyRenderComponentsAfterStateUpdated(['summary'])
                    ->visible(fn ($get) => $get("p{$i}") === '_text')
                    ->required(fn ($get) => $get("p{$i}") === '_text'),
            ]);
        }

        return Action::make($customer ? 'sendWaToCustomer' : 'sendWa')->label(__('WhatsApp পাঠান'))
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->visible(fn () => Menu::can('wa_campaign'))
            ->modalHeading($customer ? "{$customer->name} (ID {$customer->customer_id}): WhatsApp" : __('WhatsApp টেমপ্লেট পাঠান'))
            ->modalWidth('4xl')->stickyModalHeader()->stickyModalFooter()
            ->modalSubmitActionLabel(__('পাঠান'))
            ->fillForm(fn () => ['mode' => 'single', 'customers' => $customer ? [(string) $customer->header_id] : [],
                'list' => 'current'])
            ->schema([
                SmsActions::modeField($customer),
                Group::make([
                    ...SmsActions::recipientFields($customer),
                    Select::make('template')->label(__('টেমপ্লেট (Meta-তে অনুমোদিত)'))->required()->live()->searchable()
                        ->placeholder(__('টেমপ্লেট বাছুন'))
                        ->options(fn () => collect(static::templates())->mapWithKeys(fn ($t, $k) => [$k => trim(
                            "{$t['name']} · {$t['language']} · {$t['category']}".($t['supported'] ? '' : ' — '.$t['why']))])->all())
                        ->disableOptionWhen(fn (string $value) => ! (static::template($value)['supported'] ?? false))
                        ->helperText(fn () => static::$error ?? __('নতুন টেমপ্লেট Meta-তে অনুমোদনের পর এখানে আসে (২ মিনিট পর্যন্ত দেরি)।'))
                        ->afterStateUpdated(fn ($state, callable $set) => static::fill(static::template($state), $set)),
                    ...$slots,
                    Placeholder::make('summary')->hiddenLabel()->key('summary')
                        ->content(fn ($get) => view('filament.app.wa.summary', static::summary($get))),
                    Checkbox::make('confirm')->label(__('হ্যাঁ, এই সবাইকে WhatsApp মেসেজ পাঠাতে চাই (Meta চার্জ করবে)'))
                        ->accepted()->validationMessages(['accepted' => __('পাঠানোর আগে টিক দিন')]),
                ])->key('smsBody'),
            ])
            ->action(function (array $data, Action $action) use ($customer) {
                if ($customer) {
                    $data['mode'] = 'single';
                    $data['customers'] = [(string) $customer->header_id];
                }
                $t = static::template($data['template'] ?? null);
                if (! $t || ! $t['supported']) {
                    Notification::make()->danger()->title(__('টেমপ্লেটটা পাওয়া যায়নি বা চলে না'))->body(static::$error)->send();
                    $action->halt();

                    return;
                }
                $r = SmsActions::resolve($data);
                if (! $r['customers'] && ! $r['numbers']) {
                    Notification::make()->warning()->title(__('কাউকে পাওয়া যায়নি'))->body(__('সঠিক মোবাইল নম্বর আছে এমন কেউ নেই।'))->send();
                    $action->halt();

                    return;
                }
                $bulk = ($data['mode'] ?? 'single') === 'bulk';
                $campaign = WaTemplates::queue(static::company(), auth()->id(), $bulk ? 'bulk' : 'single', $t,
                    static::mapping($data, $t['params']), $r['customers'], $r['numbers'], $bulk ? SmsActions::filters($data) : []);
                Engine::kickWa(static::company());
                Notification::make()->success()
                    ->title(__(':nটি WhatsApp মেসেজ পাঠানোর তালিকায় উঠেছে', ['n' => number_format($campaign->total)]))
                    ->body(__('পেছনে পাঠানো হচ্ছে, অগ্রগতি "WhatsApp পাঠান" পেজে।'))
                    ->actions([Action::make('progress')->label(__('অগ্রগতি দেখুন'))->button()->url(WaCampaignResource::getUrl('index'))])
                    ->send();
            });
    }

    /** What the summary box shows: count, cost, the number's daily limit, previews. */
    public static function summary(callable $get): array
    {
        $t = static::template($get('template'));
        $r = SmsActions::resolve(SmsActions::formData($get));
        $count = count($r['customers']) + count($r['numbers']);
        $preview = [];
        if ($t) {
            $mapping = static::mapping($get, $t['params']);
            foreach (array_slice($r['customers'], 0, 3, true) as $mobile => $c) {
                $preview[] = ['to' => trim("{$c->name} · {$mobile}"),
                    'text' => WaTemplates::text($t, WaTemplates::values(static::company(), $mapping, $t['params'], $c))];
            }
            foreach (array_slice($r['numbers'], 0, max(0, 3 - count($preview))) as $mobile) {
                $preview[] = ['to' => (string) $mobile,
                    'text' => WaTemplates::text($t, WaTemplates::values(static::company(), $mapping, $t['params'], null))];
            }
        }
        $info = WaTemplates::numberInfo(static::company());

        return ['t' => $t, 'est' => WaTemplates::estimate(static::company(), $t['category'] ?? null, $count),
            'skipped' => $r['skipped'], 'bad' => $r['bad'], 'preview' => $preview, 'error' => static::$error,
            'tier' => $info['messaging_limit_tier'] ?? null, 'limit' => WaTemplates::tierLimit($info['messaging_limit_tier'] ?? null),
            'quality' => $info['quality_rating'] ?? null];
    }
}
