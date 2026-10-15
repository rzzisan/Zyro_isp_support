<?php

namespace App\Support;

use App\Filament\App\Resources\Customers\CustomerResource;
use App\Models\BillingCustomer;
use App\Models\SmsCampaign;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SMS from the Desk: who gets it (filters on the desk's own customer copy), how long it is (parts), what it costs,
 * and putting it in the queue. The engine (engine/sms.py) sends the queue through the company's gateway.
 */
class Sms
{
    /** placeholder => [label, customer column] */
    public const PLACEHOLDERS = [
        'name' => ['নাম', 'name'],
        'id' => ['কাস্টমার ID', 'customer_id'],
        'username' => ['PPPoE', 'username'],
        'mobile' => ['মোবাইল', 'mobile'],
        'package' => ['প্যাকেজ', 'package'],
        'monthly' => ['মাসিক বিল', 'monthly_bill'],
        'due' => ['বকেয়া', 'due'],
        'zone' => ['Zone', 'zone'],
    ];

    /** GSM 03.38 basic set (one unit each) and the extension table (two units each). */
    private const GSM = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXT = '^{}\\[~]|€';

    /** Characters, units, unicode or not, and SMS parts: GSM 160 (153 per part when longer), Unicode/Bangla 70 (67). */
    public static function parts(string $text): array
    {
        $text = str_replace("\r\n", "\n", $text);
        $chars = mb_strlen($text);
        $gsm = true;
        $units = 0;
        foreach (mb_str_split($text) as $ch) {
            if (mb_strpos(self::GSM, $ch) !== false) {
                $units++;
            } elseif (mb_strpos(self::GSM_EXT, $ch) !== false) {
                $units += 2;
            } else {
                $gsm = false;
                break;
            }
        }
        if (! $gsm) {
            $units = intdiv(strlen(mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')), 2);  // emoji = 2
            [$single, $multi] = [70, 67];
        } else {
            [$single, $multi] = [160, 153];
        }
        $parts = $units === 0 ? 0 : ($units <= $single ? 1 : (int) ceil($units / $multi));

        return ['chars' => $chars, 'units' => $units, 'unicode' => ! $gsm, 'parts' => $parts,
            'per_part' => $parts > 1 ? $multi : $single];
    }

    /** 8801XXXXXXXXX, or null when it is not a Bangladeshi mobile number. */
    public static function mobile(?string $raw): ?string
    {
        $d = preg_replace('/\D/', '', strtr((string) $raw, ['০' => 0, '১' => 1, '২' => 2, '৩' => 3, '৪' => 4, '৫' => 5, '৬' => 6, '৭' => 7, '৮' => 8, '৯' => 9]));
        if (str_starts_with($d, '00880')) {
            $d = substr($d, 2);
        }
        if (preg_match('/^01\d{9}$/', $d)) {
            $d = '88'.$d;
        } elseif (preg_match('/^1\d{9}$/', $d)) {
            $d = '880'.$d;
        }

        return preg_match('/^8801[3-9]\d{8}$/', $d) ? $d : null;
    }

    /** All numbers in free text (comma, space or line separated), normalized and unique, plus the ones that are not valid. */
    public static function numbers(?string $text): array
    {
        $ok = $bad = [];
        foreach (preg_split('/[\s,;]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY) as $raw) {
            ($m = static::mobile($raw)) ? $ok[$m] = $m : $bad[] = $raw;
        }

        return [array_values($ok), $bad];
    }

    public static function money($v): string
    {
        $v = (float) $v;

        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /** The text for one customer, placeholders filled ({due} as a plain number; unknown placeholders stay as they are). */
    public static function render(string $body, BillingCustomer|array|null $c): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($m) use ($c) {
            $col = self::PLACEHOLDERS[$m[1]][1] ?? null;
            if (! $col) {
                return $m[0];
            }
            $v = is_array($c) ? ($c[$col] ?? null) : $c?->{$col};
            if (in_array($col, ['due', 'monthly_bill'], true)) {
                return static::money(max(0, (float) $v));
            }

            return (string) ($v ?? '');
        }, $body);
    }

    public static function usesPlaceholders(string $body): bool
    {
        return (bool) preg_match('/\{('.implode('|', array_keys(self::PLACEHOLDERS)).')\}/', $body);
    }

    /** Bulk filters (the SMS form) on the desk's customer copy. Customers without a valid mobile are dropped later. */
    public static function recipients(int $companyId, array $f): Builder
    {
        $q = BillingCustomer::where('company_id', $companyId)->whereNull('gone_at');
        match ($f['list'] ?? 'current') {
            'left' => $q->where('is_left', true),
            'all' => null,
            default => $q->where('is_left', false),
        };
        foreach (['zone', 'subzone', 'box', 'package', 'connection_type', 'status'] as $col) {
            if (! empty($f[$col])) {
                $q->whereIn($col, (array) $f[$col]);
            }
        }
        match ($f['due'] ?? null) {
            'due' => $q->where('due', '>', 0),
            'paid' => $q->where(fn ($w) => $w->whereNull('due')->orWhere('due', '<=', 0)),
            default => null,
        };
        if (filled($f['due_min'] ?? null)) {
            $q->where('due', '>=', (float) $f['due_min']);
        }
        match ($f['line'] ?? null) {
            'on' => $q->where('disabled', false),
            'off' => $q->where('disabled', true),
            default => null,
        };
        match ($f['online'] ?? null) {
            'online' => $q->whereExists(CustomerResource::freshSession()),
            'offline' => $q->whereNotExists(CustomerResource::freshSession()),
            default => null,
        };
        if (filled($f['bill_day_from'] ?? null)) {
            $q->where('bill_day', '>=', (int) $f['bill_day_from']);
        }
        if (filled($f['bill_day_to'] ?? null)) {
            $q->where('bill_day', '<=', (int) $f['bill_day_to']);
        }

        return $q;
    }

    /** Customers to [mobile => row] (first customer wins on a shared number), skipped = without a valid mobile. */
    public static function byMobile(iterable $customers): array
    {
        $out = [];
        $skipped = 0;
        foreach ($customers as $c) {
            $m = static::mobile($c->mobile_normalized ?: $c->mobile);
            if (! $m) {
                $skipped++;

                continue;
            }
            $out[$m] ??= $c;
        }

        return [$out, $skipped];
    }

    /** Count, parts and cost of a send: with placeholders every text is measured, otherwise one text times the count. */
    public static function estimate(int $companyId, string $body, array $customers, array $numbers = []): array
    {
        $n = count($customers) + count($numbers);
        if (static::usesPlaceholders($body)) {
            $parts = 0;
            foreach ($customers as $c) {
                $parts += static::parts(static::render($body, $c))['parts'];
            }
            $parts += count($numbers) * static::parts(static::render($body, null))['parts'];
        } else {
            $parts = $n * static::parts($body)['parts'];
        }
        $rate = SmsSetting::where('company_id', $companyId)->value('rate');

        return ['count' => $n, 'parts' => $parts, 'rate' => $rate !== null ? (float) $rate : null,
            'cost' => $rate !== null ? round($parts * (float) $rate, 2) : null];
    }

    /**
     * Puts a send in the queue: one campaign, one sms_messages row per number (customers first, then bare numbers;
     * a number appears once). Returns the campaign; the engine is woken up by the caller.
     *
     * @param  array<string, BillingCustomer>  $customers  mobile => customer
     * @param  string[]  $numbers  normalized numbers without a customer
     */
    public static function queue(int $companyId, ?int $userId, string $kind, string $body, array $customers, array $numbers = [], array $filters = []): SmsCampaign
    {
        $numbers = array_values(array_diff($numbers, array_keys($customers)));
        $est = static::estimate($companyId, $body, $customers, $numbers);

        return DB::transaction(function () use ($companyId, $userId, $kind, $body, $customers, $numbers, $filters, $est) {
            $campaign = SmsCampaign::create(['company_id' => $companyId, 'user_id' => $userId, 'kind' => $kind, 'body' => $body,
                'filters' => $filters ?: null, 'total' => $est['count'], 'parts' => $est['parts'], 'cost' => $est['cost'],
                'status' => 'queued']);
            $now = now();
            $rows = [];
            $row = function (string $mobile, ?BillingCustomer $c) use ($campaign, $companyId, $userId, $body, $now) {
                $text = static::render($body, $c);
                $p = static::parts($text);

                return ['company_id' => $companyId, 'campaign_id' => $campaign->id, 'user_id' => $userId,
                    'customer_header_id' => $c?->header_id, 'customer_id' => $c?->customer_id, 'name' => $c?->name,
                    'mobile' => $mobile, 'text' => $text, 'parts' => $p['parts'], 'unicode' => $p['unicode'],
                    'status' => 'queued', 'created_at' => $now, 'updated_at' => $now];
            };
            foreach ($customers as $mobile => $c) {
                $rows[] = $row((string) $mobile, $c);  // numeric string keys turn into ints in PHP arrays
            }
            foreach ($numbers as $mobile) {
                $rows[] = $row($mobile, null);
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                SmsMessage::insert($chunk);
            }

            return $campaign;
        });
    }
}
