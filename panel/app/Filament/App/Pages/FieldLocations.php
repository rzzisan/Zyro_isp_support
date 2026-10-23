<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Models\FieldJob;
use App\Models\FieldLocation;
use App\Models\Membership;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Owner only: where the field technicians are (the app sends points while they are on duty or working, and they were
 * told so), and one technician's route on one day with the places their jobs were started and finished.
 */
class FieldLocations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?string $slug = 'field-locations';

    protected static string|\UnitEnum|null $navigationGroup = 'সাপোর্ট';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.app.field-locations';

    /** No point for this long while tracking counts as a gap (phone off, no signal, location turned off). */
    public const GAP_MINUTES = 15;

    public ?int $user = null;

    public string $date = '';

    public static function getNavigationLabel(): string
    {
        return __('টেকনিশিয়ান লোকেশন');
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('টেকনিশিয়ান লোকেশন');
    }

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && auth()->user()?->roleIn($company) === 'owner';
    }

    public function mount(): void
    {
        $this->date = now('Asia/Dhaka')->toDateString();
    }

    private function companyId(): int
    {
        return (int) Filament::getTenant()->getKey();
    }

    /** Members linked to a billing employee or with any point: [user_id => name]. */
    public function technicians(): array
    {
        $cid = $this->companyId();
        $ids = Membership::where('company_id', $cid)->whereNotNull('billing_employee_id')->pluck('user_id')
            ->merge(FieldLocation::where('company_id', $cid)->distinct()->pluck('user_id'))->unique();

        return \App\Models\User::whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Each technician's last point, duty and consent. */
    public function board(): array
    {
        $cid = $this->companyId();
        $members = Membership::where('company_id', $cid)->whereIn('user_id', array_keys($this->technicians()))->with('user')->get();
        $last = FieldLocation::where('company_id', $cid)->whereIn('id', FieldLocation::where('company_id', $cid)
            ->selectRaw('max(id)')->groupBy('user_id'))->get()->keyBy('user_id');
        $running = FieldJob::where('company_id', $cid)->where('status', 'running')->pluck('started_by')->filter()->flip();

        return $members->map(function (Membership $m) use ($last, $running) {
            $p = $last[$m->user_id] ?? null;

            return ['user_id' => $m->user_id, 'name' => $m->user?->name, 'consent' => $m->location_consent_at !== null,
                'on_duty_since' => $m->on_duty_since, 'working' => isset($running[$m->user_id]),
                'lat' => $p?->lat, 'lng' => $p?->lng, 'accuracy' => $p?->accuracy, 'mock' => (bool) $p?->mock, 'battery' => $p?->battery,
                'at' => $p?->recorded_at];
        })->sortByDesc(fn ($r) => $r['at']?->timestamp ?? 0)->values()->all();
    }

    /** The picked technician's points on the picked day (Dhaka), their jobs that day, distance and gaps. */
    public function route(): ?array
    {
        if (! $this->user) {
            return null;
        }
        $cid = $this->companyId();
        try {
            $day = Carbon::parse($this->date ?: 'today', 'Asia/Dhaka')->startOfDay();
        } catch (\Throwable) {
            $day = now('Asia/Dhaka')->startOfDay();
        }
        [$from, $to] = [$day->copy()->utc(), $day->copy()->endOfDay()->utc()];
        $points = FieldLocation::where('company_id', $cid)->where('user_id', $this->user)
            ->whereBetween('recorded_at', [$from, $to])->orderBy('recorded_at')->get();
        $metres = 0;
        $gaps = [];
        foreach ($points as $i => $p) {
            if ($i === 0) {
                continue;
            }
            $prev = $points[$i - 1];
            if (! $p->mock && ! $prev->mock) {
                $metres += FieldLocation::distance($prev->lat, $prev->lng, $p->lat, $p->lng);
            }
            $mins = (int) $prev->recorded_at->diffInMinutes($p->recorded_at);
            if ($mins >= self::GAP_MINUTES) {
                $gaps[] = ['from' => $prev->recorded_at, 'to' => $p->recorded_at, 'minutes' => $mins];
            }
        }
        $jobs = FieldJob::where('company_id', $cid)->where(fn ($q) => $q->where('started_by', $this->user)->orWhere('finished_by', $this->user))
            ->where(fn ($q) => $q->whereBetween('started_at', [$from, $to])->orWhereBetween('finished_at', [$from, $to]))
            ->with('ticket')->orderBy('started_at')->get();

        return ['points' => $points, 'metres' => $metres, 'gaps' => $gaps, 'jobs' => $jobs, 'mock' => $points->where('mock', true)->count()];
    }

    /** What the map draws. */
    public function mapData(?array $route, array $board): array
    {
        if ($route) {
            $jobMarks = [];
            foreach ($route['jobs'] as $j) {
                /** @var FieldJob $j */
                $label = '#'.$j->ticket?->complain_id.' '.($j->ticket?->customer_name ?? '');
                foreach (['start' => __('শুরু'), 'finish' => __('শেষ')] as $at => $word) {
                    if ($j->{"{$at}_lat"} !== null) {
                        $jobMarks[] = ['lat' => $j->{"{$at}_lat"}, 'lng' => $j->{"{$at}_lng"}, 'kind' => $at, 'mock' => (bool) $j->{"{$at}_mock"},
                            'label' => $word.': '.$label.($j->{"{$at}_distance"} !== null ? ' · '.__('কাস্টমার থেকে :m মি', ['m' => $j->{"{$at}_distance"}]) : '')];
                    }
                }
            }

            return ['line' => $route['points']->map(fn ($p) => [$p->lat, $p->lng, $p->recorded_at->timezone('Asia/Dhaka')->format('g:i A'), $p->mock])->values(),
                'marks' => $jobMarks];
        }

        return ['line' => [], 'marks' => collect($board)->filter(fn ($r) => $r['lat'] !== null)->map(fn ($r) => ['lat' => $r['lat'], 'lng' => $r['lng'],
            'kind' => $r['on_duty_since'] || $r['working'] ? 'live' : 'old', 'mock' => $r['mock'],
            'label' => $r['name'].' · '.$r['at']->timezone('Asia/Dhaka')->format('d M g:i A')])->values()->all()];
    }

    public function showRoute(int $userId): void
    {
        $this->user = $userId;
    }

    protected function getViewData(): array
    {
        $board = $this->board();
        $route = $this->route();

        return ['board' => $board, 'route' => $route, 'technicians' => $this->technicians(), 'map' => $this->mapData($route, $board)];
    }
}
