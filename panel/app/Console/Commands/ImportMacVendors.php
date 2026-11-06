<?php

namespace App\Console\Commands;

use App\Support\MacVendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Fill / refresh `mac_vendors` from Wireshark's manuf file (all IEEE MA-L, MA-M and MA-S blocks).
 * Monthly via zyro-mac-vendors.timer; Wireshark asks not to download it more than once a week.
 */
class ImportMacVendors extends Command
{
    public const URL = 'https://www.wireshark.org/download/automated/data/manuf.gz';

    protected $signature = 'mac-vendors:import {--file= : a local manuf or manuf.gz file instead of downloading}';

    protected $description = 'Import the MAC address vendor list (router brands) into mac_vendors';

    public function handle(): int
    {
        if ($file = $this->option('file')) {
            $raw = @file_get_contents($file);
        } else {
            $res = Http::timeout(120)->withUserAgent('ZyroDesk/1.0 (mac-vendors)')->get(self::URL);
            $raw = $res->successful() ? $res->body() : false;
        }
        if ($raw === false || $raw === '') {
            $this->error('could not read the manuf file');

            return self::FAILURE;
        }
        if (str_starts_with($raw, "\x1f\x8b")) {
            $raw = gzdecode($raw);
        }

        $rows = static::parse((string) $raw);
        if (count($rows) < 20000) { // a broken download must not empty the table
            $this->error('only '.count($rows).' blocks found, table left as it was');

            return self::FAILURE;
        }

        DB::transaction(function () use ($rows) {
            DB::table('mac_vendors')->delete();
            foreach (array_chunk(array_values($rows), 2000) as $chunk) {
                DB::table('mac_vendors')->insert($chunk);
            }
        });
        $this->info(count($rows).' MAC vendor blocks imported');

        return self::SUCCESS;
    }

    /** manuf lines ("00:0A:EB<TAB>TpLinkTechno<TAB>Tp-Link Technologies Co.,Ltd.", "00:1B:C5:00:00/36 ...") -> rows by prefix. */
    public static function parse(string $raw): array
    {
        $rows = [];
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $cols = array_map('trim', explode("\t", $line));
            if (! preg_match('/^([0-9A-Fa-f:\-\.]+)(?:\/(\d+))?$/', $cols[0], $m)) {
                continue;
            }
            $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $m[1]));
            $bits = isset($m[2]) ? (int) $m[2] : 24;
            if (! in_array($bits, [24, 28, 36], true) || strlen($hex) < $bits / 4) {
                continue;
            }
            $prefix = substr($hex, 0, intdiv($bits, 4));
            $vendor = mb_substr(($cols[2] ?? '') ?: ($cols[1] ?? ''), 0, 255);
            if ($vendor === '' || $hex === '000000') {
                continue;
            }
            $rows[$prefix] = ['prefix' => $prefix, 'vendor' => $vendor, 'brand' => MacVendor::brandFrom($vendor)];
        }

        return $rows;
    }
}
