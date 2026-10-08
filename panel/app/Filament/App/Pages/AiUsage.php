<?php

namespace App\Filament\App\Pages;

use App\Models\AiKey;
use App\Models\AiModelPrice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AI খরচ: tokens the company's AI keys used (recorded by the engine per call), estimated USD cost from the
 * company's price per model, and the quota the provider last reported per key. Owner/Admin (same menu as AI key).
 */
class AiUsage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'AI খরচ';

    protected static ?string $title = 'AI টোকেন ও খরচ';

    protected static ?string $slug = 'ai-usage';

    protected static string|\UnitEnum|null $navigationGroup = 'সেটিংস';

    protected static ?int $navigationSort = 31;

    protected string $view = 'filament.app.ai-usage';

    public const PURPOSES = ['customer' => 'কাস্টমার চ্যাট', 'technician' => 'টেকনিশিয়ান ডেস্ক', 'voice' => 'ভয়েস → লেখা'];

    /** today | 7 | 30 */
    public string $period = 'today';

    public static function canAccess(): bool
    {
        return \App\Support\Menu::can('ai_keys') && ((bool) auth()->user()?->managesCompany(Filament::getTenant()));
    }

    public function setPeriod(string $p): void
    {
        $this->period = in_array($p, ['today', '7', '30'], true) ? $p : 'today';
    }

    private function companyId(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    /** UTC start of a period, counting days in Dhaka time. */
    private function since(string $p): Carbon
    {
        $start = Carbon::now('Asia/Dhaka')->startOfDay();
        if ($p !== 'today') {
            $start->subDays((int) $p - 1);
        }

        return $start->utc();
    }

    private function cost(array $prices, string $provider, string $model, int $in, int $out, int $cached = 0): ?float
    {
        $p = $prices["{$provider}|{$model}"] ?? null;
        $cached = min($cached, $in); // input_tokens already includes the cached part

        return $p === null ? null
            : (($in - $cached) * $p[0] + $cached * $p[0] * AiModelPrice::CACHED_INPUT_FACTOR + $out * $p[1]) / 1_000_000;
    }

    /** Usage grouped by the given columns since the start of a period, each row with its estimated cost. */
    private function grouped(string $p, array $by): array
    {
        $prices = AiModelPrice::table($this->companyId());
        $rows = DB::table('ai_usage')->where('company_id', $this->companyId())->where('created_at', '>=', $this->since($p))
            ->groupBy(array_unique([...$by, 'provider', 'model']))
            ->select([...array_unique([...$by, 'provider', 'model']),
                DB::raw('count(*) AS calls'), DB::raw('sum(CASE WHEN ok THEN 0 ELSE 1 END) AS failed'),
                DB::raw('sum(input_tokens) AS input'), DB::raw('sum(output_tokens) AS output'),
                DB::raw('sum(cached_tokens) AS cached'), DB::raw('max(created_at) AS last_at')])
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $k = implode('|', array_map(fn ($c) => $r->{$c}, $by));
            $o = $out[$k] ?? ['calls' => 0, 'failed' => 0, 'input' => 0, 'output' => 0, 'cached' => 0, 'cost' => 0.0, 'priced' => true, 'last_at' => null];
            foreach ($by as $c) {
                $o[$c] = $r->{$c};
            }
            $o['calls'] += $r->calls;
            $o['failed'] += $r->failed;
            $o['input'] += $r->input;
            $o['output'] += $r->output;
            $o['cached'] += $r->cached;
            $c = $this->cost($prices, $r->provider, $r->model, (int) $r->input, (int) $r->output, (int) $r->cached);
            if ($c === null) {
                $o['priced'] = false;
            } else {
                $o['cost'] += $c;
            }
            $o['last_at'] = max($o['last_at'] ?? '', (string) $r->last_at);
            $out[$k] = $o;
        }

        return array_values($out);
    }

    /** Totals for today / 7 days / 30 days (voice seconds left out of the token counts). */
    public function totals(): array
    {
        $out = [];
        foreach (['today' => 'আজ', '7' => 'গত ৭ দিন', '30' => 'গত ৩০ দিন'] as $p => $label) {
            $t = ['label' => $label, 'calls' => 0, 'failed' => 0, 'input' => 0, 'output' => 0, 'cached' => 0, 'cost' => 0.0, 'priced' => true];
            foreach ($this->grouped($p, ['purpose']) as $r) {
                $t['calls'] += $r['calls'];
                $t['failed'] += $r['failed'];
                if ($r['purpose'] !== 'voice') {
                    $t['input'] += $r['input'];
                    $t['output'] += $r['output'];
                    $t['cached'] += $r['cached'];
                }
                $t['cost'] += $r['cost'];
                $t['priced'] = $t['priced'] && $r['priced'];
            }
            $out[$p] = $t;
        }

        return $out;
    }

    public function byKey(): array
    {
        $usage = collect($this->grouped($this->period, ['ai_key_id']))->keyBy('ai_key_id');
        $today = collect($this->grouped('today', ['ai_key_id']))->keyBy('ai_key_id');

        return AiKey::where('company_id', $this->companyId())->orderBy('id')->get()->map(fn (AiKey $k) => [
            'name' => (AiKey::PROVIDERS[$k->provider][0] ?? $k->provider).($k->label ? " · {$k->label}" : ''),
            'masked' => $k->maskedKey(),
            'usage' => $usage[$k->id] ?? null,
            'today_tokens' => ($today[$k->id]['input'] ?? 0) + ($today[$k->id]['output'] ?? 0),
            'quota' => $k->quotaLines(),
            'quota_at' => $k->quota_at?->timezone('Asia/Dhaka')->format('j M g:i A'),
            'cooling' => $k->rate_limited_until?->isFuture(),
        ])->all();
    }

    public function byModel(): array
    {
        $prices = AiModelPrice::table($this->companyId());

        return array_map(fn ($r) => $r + ['price' => $prices["{$r['provider']}|{$r['model']}"] ?? null],
            $this->grouped($this->period, ['provider', 'model']));
    }

    public function byPurpose(): array
    {
        return $this->grouped($this->period, ['purpose']);
    }

    /** Last 14 days (Dhaka), tokens and cost per day. */
    public function daily(): array
    {
        $prices = AiModelPrice::table($this->companyId());
        $rows = DB::table('ai_usage')->where('company_id', $this->companyId())->where('created_at', '>=', $this->since('14'))
            ->where('purpose', '!=', 'voice')
            ->groupBy(DB::raw("date(created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka')"), 'provider', 'model')
            ->select([DB::raw("date(created_at AT TIME ZONE 'UTC' AT TIME ZONE 'Asia/Dhaka') AS day"), 'provider', 'model',
                DB::raw('count(*) AS calls'), DB::raw('sum(input_tokens) AS input'), DB::raw('sum(output_tokens) AS output'),
                DB::raw('sum(cached_tokens) AS cached')])
            ->get();
        $days = [];
        foreach ($rows as $r) {
            $d = $days[$r->day] ?? ['day' => $r->day, 'calls' => 0, 'tokens' => 0, 'cost' => 0.0];
            $d['calls'] += $r->calls;
            $d['tokens'] += $r->input + $r->output;
            $d['cost'] += $this->cost($prices, $r->provider, $r->model, (int) $r->input, (int) $r->output, (int) $r->cached) ?? 0;
            $days[$r->day] = $d;
        }
        krsort($days);

        return array_values($days);
    }

    public function editPriceAction(): Action
    {
        return Action::make('editPrice')->label('দাম')->icon(Heroicon::OutlinedPencilSquare)->link()
            ->modalHeading(fn (array $arguments) => 'দাম: '.($arguments['model'] ?? ''))
            ->modalDescription('প্রতি ১০ লাখ (1M) টোকেনে কত USD, প্রোভাইডারের প্রাইসিং পেজ থেকে দিন। ফ্রি টিয়ারে আসল বিল শূন্য; ০ দিলে খরচ ০ দেখাবে।')
            ->fillForm(function (array $arguments) {
                $p = AiModelPrice::table($this->companyId())["{$arguments['provider']}|{$arguments['model']}"] ?? [null, null];

                return ['input_per_million' => $p[0], 'output_per_million' => $p[1]];
            })
            ->schema([
                TextInput::make('input_per_million')->label('ইনপুট (USD / 1M টোকেন)')->numeric()->minValue(0)->required(),
                TextInput::make('output_per_million')->label('আউটপুট (USD / 1M টোকেন)')->numeric()->minValue(0)->required(),
            ])
            ->action(function (array $data, array $arguments) {
                AiModelPrice::updateOrCreate(
                    ['company_id' => $this->companyId(), 'provider' => (string) $arguments['provider'], 'model' => (string) $arguments['model']],
                    ['input_per_million' => $data['input_per_million'], 'output_per_million' => $data['output_per_million']]);
                Notification::make()->success()->title('দাম সেভ হয়েছে')->send();
            });
    }
}
