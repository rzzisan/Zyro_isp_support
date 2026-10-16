<?php

namespace App\Support;

use App\Models\BillingCustomer;
use App\Models\Company;
use App\Models\OnuAssignment;
use App\Models\OnuBrand;
use App\Models\OnuStockEntry;
use Illuminate\Support\Facades\DB;

/** Shared rules of the ONU stock module: MAC format, customer IDs, stock per brand. */
class Onu
{
    public const MODULE = 'onu';

    public const PURPOSES = ['New Connection', 'Warranty', 'Convert to ONU', 'বিক্রয় করা হয়েছে', 'পয়েন্টে লাগানো হয়েছে'];

    public const CONNECTION_TYPES = ['নতুন লাইন', 'ফাইবার কনভার্ট'];

    public const MATERIALS = ['অনু', 'ফাইবার', 'অনু-রাউটার'];

    public const OTHER = 'other';

    /** The module is on for this company. */
    public static function enabled(?Company $company): bool
    {
        return (bool) $company?->hasModule(self::MODULE);
    }

    /** canAccess() of the module's pages: the company has the module and the member has the menu. */
    public static function canAccess(): bool
    {
        return static::enabled(\Filament\Facades\Filament::getTenant()) && Menu::can('onu_stock');
    }

    /** Any typed MAC -> hex characters only, upper case ("a2:8f.06-05 37 40" -> "A28F06053740"). */
    public static function normalizeMac(?string $mac): ?string
    {
        $n = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $mac));

        return $n === '' ? null : $n;
    }

    public static function validMac(?string $norm): bool
    {
        return (bool) preg_match('/^[0-9A-F]{12}$/', (string) $norm);
    }

    /** A2:8F:06:05:37:40 for a valid MAC, else what was typed. */
    public static function displayMac(?string $norm, ?string $raw = null): string
    {
        return static::validMac($norm) ? implode(':', str_split($norm, 2)) : (string) ($raw ?? $norm);
    }

    /**
     * The billing customer for a typed ID, matched without leading zeros ("976" and "0976" are the same).
     * Returns [customer_code as billing writes it (or as typed when billing has no such ID), billing_customers.id or null].
     */
    public static function resolveCustomer(int $companyId, ?string $code): array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return [null, null];
        }
        $c = BillingCustomer::where('company_id', $companyId)->whereRaw("ltrim(customer_id, '0') = ltrim(?, '0')", [$code])
            ->orderByRaw('gone_at IS NOT NULL')->first(['id', 'customer_id']);

        return $c ? [$c->customer_id, $c->id] : [$code, null];
    }

    /** brand_id => [in, out, left] for the company. */
    public static function stock(int $companyId): array
    {
        $in = OnuStockEntry::where('company_id', $companyId)->groupBy('brand_id')->selectRaw('brand_id, sum(quantity) AS q')->pluck('q', 'brand_id');
        $out = OnuAssignment::where('company_id', $companyId)->groupBy('brand_id')->selectRaw('brand_id, count(*) AS q')->pluck('q', 'brand_id');

        return OnuBrand::where('company_id', $companyId)->pluck('id')->mapWithKeys(fn ($id) => [$id => [
            'in' => (int) ($in[$id] ?? 0), 'out' => (int) ($out[$id] ?? 0), 'left' => (int) ($in[$id] ?? 0) - (int) ($out[$id] ?? 0),
        ]])->all();
    }

    /** [brand_id => "name (left)"] of brands with stock left, plus $keep (the record's own brand when editing). */
    public static function brandOptions(int $companyId, ?int $keep = null): array
    {
        $stock = static::stock($companyId);

        return OnuBrand::where('company_id', $companyId)->orderBy('name')->get()
            ->filter(fn ($b) => ($stock[$b->id]['left'] ?? 0) > 0 || $b->id === $keep)
            ->mapWithKeys(fn ($b) => [$b->id => $b->name.' (স্টকে '.($stock[$b->id]['left'] ?? 0).')'])->all();
    }

    /** OLT ONUs by normalized MAC (one query per request), for showing live light levels next to an assignment. */
    public static function liveOnus(int $companyId): array
    {
        return once(fn () => DB::table('onus')->where('company_id', $companyId)->whereNotNull('onu_mac')
            ->get(['id', 'name', 'onu_mac', 'online', 'rx_dbm'])
            ->keyBy(fn ($o) => static::normalizeMac($o->onu_mac))->all());
    }
}
