<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The brand of a device from its MAC (the customer's router MAC MikroTik sees as caller-id), read from the
 * offline `mac_vendors` table (IEEE OUI blocks; `php artisan mac-vendors:import` fills and refreshes it).
 */
class MacVendor
{
    /** Common router / phone / ONU makers: a match on the registered name gives this display brand. */
    private const ALIASES = [
        '/tp-?link/i' => 'TP-Link', '/\bmercusys\b/i' => 'Mercusys', '/\btenda\b/i' => 'Tenda', '/\bxiaomi\b/i' => 'Xiaomi',
        '/\bhuawei\b/i' => 'Huawei', '/honor device/i' => 'Honor', '/d-?link/i' => 'D-Link', '/\bnetis\b/i' => 'Netis', '/\bzte\b/i' => 'ZTE',
        '/\bcudy\b/i' => 'Cudy', '/zioncom|totolink/i' => 'Totolink', '/\bruijie\b/i' => 'Ruijie', '/routerboard|mikrotik/i' => 'MikroTik',
        '/\bubiquiti\b/i' => 'Ubiquiti', '/asustek/i' => 'Asus', '/\bnetgear\b/i' => 'Netgear', '/\blinksys\b/i' => 'Linksys',
        '/\bcisco\b/i' => 'Cisco', '/\bfiberhome\b/i' => 'FiberHome', '/v-?sol/i' => 'V-SOL', '/\bbdcom\b/i' => 'BDCOM', '/\bwavlink\b/i' => 'Wavlink',
        '/\bedimax\b/i' => 'Edimax', '/\bcomfast\b/i' => 'Comfast', '/\btozed\b/i' => 'Tozed', '/\bjio\b/i' => 'Jio', '/\brealme\b/i' => 'Realme',
        '/\boppo\b/i' => 'Oppo', '/vivo mobile/i' => 'Vivo', '/oneplus/i' => 'OnePlus', '/\bsamsung\b/i' => 'Samsung', '/\bapple\b/i' => 'Apple',
        '/\bgoogle\b/i' => 'Google', '/\bintel\b/i' => 'Intel', '/\brealtek\b/i' => 'Realtek', '/\bmediatek\b/i' => 'MediaTek', '/\blenovo\b/i' => 'Lenovo',
        '/hewlett|\bhp\b/i' => 'HP', '/\bdell\b/i' => 'Dell', '/\bmicrosoft\b/i' => 'Microsoft', '/\bamazon\b/i' => 'Amazon',
        '/\bespressif\b/i' => 'Espressif', '/\braspberry\b/i' => 'Raspberry Pi', '/\bhikvision\b/i' => 'Hikvision', '/\bdahua\b/i' => 'Dahua',
        '/\bipcom\b/i' => 'IP-COM', '/\bhisense\b/i' => 'Hisense', '/\bskyworth\b/i' => 'Skyworth', '/\b(transsion|tecno|infinix|itel)\b/i' => 'Transsion',
        '/\bmotorola\b/i' => 'Motorola', '/\bnokia\b/i' => 'Nokia', '/\bsony\b/i' => 'Sony', '/lg electronics/i' => 'LG',
    ];

    private const CORPORATE = 'co|corp|corporation|company|ltd|limited|inc|incorporated|llc|plc|gmbh|ag|sa|s\.a|bv|b\.v|nv|ab|as|oy|srl|spa|pte|pty|kg|'
        .'technology|technologies|tech|electronics|electronic|communication|communications|telecom|telecommunication|'
        .'telecommunications|network|networks|networking|systems|system|industrial|industry|industries|international|group|holdings|sas|'
        .'digital|intelligent|smart|information|science|manufacturing|trading|products|devices|device|semiconductor|computer|'
        .'equipment|enterprise|enterprises|development|software|solutions|global|wireless|optical|electrical|appliance|appliances|'
        .'shenzhen|guangzhou|guangdong|beijing|shanghai|hangzhou|zhejiang|jiangsu|suzhou|dongguan|xiamen|fujian|chengdu|wuhan|nanjing|'
        .'qingdao|ningbo|zhuhai|foshan|zhongshan|huizhou|tianjin|hong|kong|taiwan|china|the';

    /** @var array<string, array{brand: string, vendor: string}|null> */
    private static array $memo = [];

    /** Any written MAC -> 12 upper-case hex digits, or null. */
    public static function normalize(?string $mac): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        return strlen($hex) === 12 ? $hex : null;
    }

    /** A randomised / locally administered MAC (phones' private Wi-Fi address, some routers' WAN clone): no maker. */
    public static function isRandom(string $hex): bool
    {
        return (bool) (hexdec($hex[1]) & 0x2);
    }

    /** ['brand' => 'TP-Link', 'vendor' => 'Tp-Link Technologies Co.,Ltd.'] or null when unknown. */
    public static function lookup(?string $mac): ?array
    {
        $hex = static::normalize($mac);
        if ($hex === null) {
            return null;
        }
        if (array_key_exists($hex, self::$memo)) {
            return self::$memo[$hex];
        }
        try {
            $row = DB::table('mac_vendors')->whereIn('prefix', [substr($hex, 0, 9), substr($hex, 0, 7), substr($hex, 0, 6)])
                ->orderByRaw('length(prefix) DESC')->first(['brand', 'vendor']);
        } catch (\Throwable) {
            $row = null; // table not migrated yet: show the MAC as before
        }
        $found = $row ? ['brand' => $row->brand, 'vendor' => $row->vendor]
            : (static::isRandom($hex) ? ['brand' => __('র‍্যান্ডম MAC'), 'vendor' => __('লোকাল/র‍্যান্ডম MAC, নির্মাতা নেই')] : null);
        if (count(self::$memo) > 5000) {
            self::$memo = [];
        }

        return self::$memo[$hex] = $found;
    }

    /** The brand, or null when the MAC is unknown. */
    public static function brand(?string $mac): ?string
    {
        return static::lookup($mac)['brand'] ?? null;
    }

    /** What the panel shows where it used to show the MAC: the brand, else the MAC itself. */
    public static function label(?string $mac): ?string
    {
        return static::brand($mac) ?? (filled($mac) ? $mac : null);
    }

    /** Tooltip text: MAC and full vendor name. */
    public static function hint(?string $mac): ?string
    {
        if (! filled($mac)) {
            return null;
        }
        $v = static::lookup($mac)['vendor'] ?? null;

        return 'MAC '.$mac.($v ? ' · '.$v : '');
    }

    /** Registered vendor name -> short brand ("Tp-Link Technologies Co.,Ltd." -> "TP-Link"). */
    public static function brandFrom(string $vendor): string
    {
        foreach (self::ALIASES as $re => $brand) {
            if (preg_match($re, $vendor)) {
                return $brand;
            }
        }
        $name = trim((string) preg_replace('/\s*\(.*?\)\s*/', ' ', $vendor));
        $name = trim(explode(',', $name)[0]);
        $words = preg_split('/\s+/', $name) ?: [];
        $kept = array_values(array_filter($words, fn ($w) => ! preg_match('/^('.self::CORPORATE.')\.?$/i', $w)));
        $kept = $kept ?: $words;
        $out = (string) preg_replace('/\s+(and|&)$/i', '', implode(' ', array_slice($kept, 0, 3)));
        if ($out === strtoupper($out) && strlen($out) > 5) {
            $out = ucwords(strtolower($out));
        }

        return mb_substr(trim($out, " .-&") ?: $vendor, 0, 60);
    }
}
