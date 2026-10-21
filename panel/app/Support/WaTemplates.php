<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\WaAccount;
use App\Models\WaCampaign;
use App\Models\WaCampaignMessage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WhatsApp template sends from the Desk: the company's approved templates (read from Meta with its own token), what
 * goes into each {{n}}, the text a customer sees, and the queue. engine/wa_campaign.py sends the queue.
 * Only positional body variables are filled; templates with a media header, a variable in the header or a button
 * that needs a value are listed as not supported.
 */
class WaTemplates
{
    /** field => label: what can go into a {{n}} (customer columns as in SMS, plus the expire date). */
    public static function fields(): array
    {
        $out = collect(Sms::PLACEHOLDERS)->map(fn ($p) => $p[0])->all();
        $out['expire_date'] = 'মেয়াদ শেষের তারিখ';

        return array_map('__', $out) + ['_text' => __('নিজে লিখুন (সবার জন্য একই)')];
    }

    public static function account(int $companyId): ?WaAccount
    {
        return WaAccount::where('company_id', $companyId)->orderBy('id')->first();
    }

    private static function graph(string $path): string
    {
        return 'https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/'.ltrim($path, '/');
    }

    /**
     * "name|language" => [name, language, category, header, body, footer, buttons, params, supported, why] for the
     * company's APPROVED templates. Cached 2 minutes; $fresh asks Meta again. Throws with Meta's error.
     */
    public static function templates(int $companyId, bool $fresh = false): array
    {
        $account = static::account($companyId);
        if (! $account) {
            throw new RuntimeException(__('এই কোম্পানির কোনো WhatsApp নম্বর যুক্ত নেই।'));
        }
        $key = "wa-templates:{$account->id}";
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 120, function () use ($account) {
            $rows = [];
            $url = static::graph($account->waba_id.'/message_templates');
            $query = ['fields' => 'name,status,category,language,components,parameter_format', 'limit' => 100];
            for ($page = 0; $url && $page < 10; $page++) {
                try {
                    $res = Http::withToken($account->access_token)->timeout(30)->get($url, $query)->throw()->json();
                } catch (RequestException $e) {
                    throw new RuntimeException(__('Meta থেকে টেমপ্লেট আনা যায়নি: ').mb_substr($e->response->json('error.message') ?? $e->getMessage(), 0, 300));
                }
                $rows = array_merge($rows, $res['data'] ?? []);
                $url = $res['paging']['next'] ?? null;
                $query = [];  // the next link carries the query
            }
            $out = [];
            foreach ($rows as $r) {
                if (($r['status'] ?? null) !== 'APPROVED') {
                    continue;
                }
                $t = static::parse($r);
                $out[$t['name'].'|'.$t['language']] = $t;
            }
            ksort($out);

            return $out;
        });
    }

    /** One template from Meta's list into what the form needs. */
    public static function parse(array $r): array
    {
        $t = ['name' => $r['name'], 'language' => $r['language'] ?? 'bn', 'category' => $r['category'] ?? null,
            'header' => null, 'body' => '', 'footer' => null, 'buttons' => [], 'params' => 0, 'supported' => true, 'why' => null];
        $no = function (string $why) use (&$t) {
            $t['supported'] = false;
            $t['why'] ??= $why;
        };
        if (($r['parameter_format'] ?? 'POSITIONAL') === 'NAMED') {
            $no(__('নামওয়ালা ভেরিয়েবল ({{name}}) এখনো চলে না'));
        }
        foreach ($r['components'] ?? [] as $c) {
            switch (strtoupper($c['type'] ?? '')) {
                case 'HEADER':
                    if (strtoupper($c['format'] ?? 'TEXT') !== 'TEXT') {
                        $no(__('ছবি/ভিডিও/ফাইলের হেডার এখনো চলে না'));
                    } elseif (str_contains($c['text'] ?? '', '{{')) {
                        $no(__('হেডারে ভেরিয়েবল এখনো চলে না'));
                    }
                    $t['header'] = $c['text'] ?? null;
                    break;
                case 'BODY':
                    $t['body'] = (string) ($c['text'] ?? '');
                    preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $t['body'], $m);
                    $t['params'] = $m[1] ? max(array_map('intval', $m[1])) : 0;
                    break;
                case 'FOOTER':
                    $t['footer'] = $c['text'] ?? null;
                    break;
                case 'BUTTONS':
                    foreach ($c['buttons'] ?? [] as $b) {
                        $type = strtoupper($b['type'] ?? '');
                        $t['buttons'][] = (string) ($b['text'] ?? $type);
                        if (in_array($type, ['COPY_CODE', 'FLOW', 'OTP', 'CATALOG', 'MPM'], true)
                            || ($type === 'URL' && str_contains($b['url'] ?? '', '{{'))) {
                            $no(__('মান লাগে এমন বোতাম এখনো চলে না'));
                        }
                    }
                    break;
            }
        }

        return $t;
    }

    /** Messaging limit tier and quality of the company's number (cached 10 minutes); [] when Meta doesn't answer. */
    public static function numberInfo(int $companyId): array
    {
        $account = static::account($companyId);
        if (! $account) {
            return [];
        }

        return Cache::remember("wa-number-info:{$account->id}", 600, function () use ($account) {
            try {
                return Http::withToken($account->access_token)->timeout(15)
                    ->get(static::graph($account->phone_number_id), ['fields' => 'messaging_limit_tier,quality_rating'])
                    ->throw()->json() ?? [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /** TIER_1K -> 1000 unique customers per 24 hours, null when unlimited/unknown. */
    public static function tierLimit(?string $tier): ?int
    {
        if (! $tier || ! preg_match('/^TIER_(\d+)(K?)$/', $tier, $m)) {
            return null;
        }

        return (int) $m[1] * ($m[2] === 'K' ? 1000 : 1);
    }

    /** Meta refuses empty values, new lines, tabs and more than 4 spaces in a row inside a variable. */
    public static function clean(?string $v): string
    {
        $v = trim(preg_replace('/ {4,}/', '   ', preg_replace('/[\r\n\t]+/', ' ', (string) $v)));

        return $v === '' ? '-' : mb_substr($v, 0, 500);
    }

    /** The values for {{1}}..{{n}} for one customer (null = a typed number without a customer). */
    public static function values(int $companyId, array $mapping, int $count, BillingCustomer|null $c, ?Carbon $today = null): array
    {
        $extra = ['company' => Sms::companyName($companyId), 'expire_date' => ''];
        if ($c && (int) $c->bill_day > 0) {
            $extra['expire_date'] = BillReminders::expireDate((int) $c->bill_day, $today ?? now('Asia/Dhaka'))->format('d-m-Y');
        }
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $p = $mapping[$i - 1] ?? [];
            $v = isset($p['field']) && $p['field'] !== '_text' ? Sms::render('{'.$p['field'].'}', $c, $extra) : ($p['text'] ?? '');
            $out[] = static::clean($v);
        }

        return $out;
    }

    /** What the customer sees: header, body with the values, footer. */
    public static function text(array $t, array $values): string
    {
        $body = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', fn ($m) => $values[(int) $m[1] - 1] ?? $m[0], $t['body']);

        return trim(implode("\n\n", array_filter([$t['header'], $body, $t['footer']], 'filled')));
    }

    /** Count and cost (category rate on the company's number; null when no rate is set). */
    public static function estimate(int $companyId, ?string $category, int $count): array
    {
        $account = static::account($companyId);
        $rate = match (strtoupper((string) $category)) {
            'MARKETING' => $account?->marketing_rate,
            'UTILITY' => $account?->utility_rate,
            default => null,
        };

        return ['count' => $count, 'rate' => $rate !== null ? (float) $rate : null,
            'cost' => $rate !== null ? round($count * (float) $rate, 2) : null];
    }

    /**
     * Puts a send in the queue: one campaign, one row per number (customers first, then bare numbers).
     *
     * @param  array<string, BillingCustomer>  $customers  mobile => customer
     * @param  string[]  $numbers  normalized numbers without a customer
     */
    public static function queue(int $companyId, ?int $userId, string $kind, array $t, array $mapping, array $customers,
        array $numbers = [], array $filters = []): WaCampaign
    {
        $account = static::account($companyId) ?? throw new RuntimeException(__('এই কোম্পানির কোনো WhatsApp নম্বর যুক্ত নেই।'));
        $numbers = array_values(array_diff($numbers, array_map('strval', array_keys($customers))));
        $est = static::estimate($companyId, $t['category'], count($customers) + count($numbers));
        $mapping = array_slice(array_values($mapping), 0, $t['params']);

        return DB::transaction(function () use ($companyId, $userId, $kind, $t, $mapping, $customers, $numbers, $filters, $est, $account) {
            $campaign = WaCampaign::create(['company_id' => $companyId, 'wa_account_id' => $account->id, 'user_id' => $userId,
                'kind' => $kind, 'template_name' => $t['name'], 'template_language' => $t['language'],
                'template_category' => $t['category'], 'template_body' => $t['body'], 'params' => $mapping,
                'filters' => $filters ?: null, 'total' => $est['count'], 'cost' => $est['cost'], 'status' => 'queued']);
            $now = now();
            $today = now('Asia/Dhaka');
            $rows = [];
            $row = function (string $mobile, ?BillingCustomer $c) use ($campaign, $companyId, $userId, $t, $mapping, $now, $today) {
                $values = static::values($companyId, $mapping, $t['params'], $c, $today);

                return ['company_id' => $companyId, 'campaign_id' => $campaign->id, 'user_id' => $userId,
                    'customer_header_id' => $c?->header_id, 'customer_id' => $c?->customer_id, 'name' => $c?->name,
                    'wa_number' => $mobile, 'params' => json_encode($values, JSON_UNESCAPED_UNICODE),
                    'text' => static::text($t, $values), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now];
            };
            foreach ($customers as $mobile => $c) {
                $rows[] = $row((string) $mobile, $c);  // numeric string keys turn into ints in PHP arrays
            }
            foreach ($numbers as $mobile) {
                $rows[] = $row((string) $mobile, null);
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                WaCampaignMessage::insert($chunk);
            }

            return $campaign;
        });
    }

    /** The mapping used last time with this template (the form starts with it). */
    public static function lastMapping(int $companyId, string $name, string $language): array
    {
        return (array) (WaCampaign::where('company_id', $companyId)->where('template_name', $name)
            ->where('template_language', $language)->latest('id')->value('params') ?? []);
    }
}
