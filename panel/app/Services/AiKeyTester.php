<?php

namespace App\Services;

use App\Models\AiKey;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Checks a key by listing the provider's models (no tokens spent). */
class AiKeyTester
{
    public static function check(AiKey $key): int
    {
        [$name, $base] = AiKey::PROVIDERS[$key->provider] ?? [null, null];
        if ($name === null) {
            throw new RuntimeException('অজানা প্রোভাইডার');
        }
        $r = $base === null
            ? Http::timeout(20)->withHeaders(['x-api-key' => $key->api_key, 'anthropic-version' => '2023-06-01'])
                ->get('https://api.anthropic.com/v1/models')
            : Http::timeout(20)->withToken($key->api_key)->get($base.'/models');
        if ($r->status() === 401 || $r->status() === 403) {
            throw new RuntimeException('key সঠিক না বা অনুমতি নেই');
        }
        if (! $r->successful()) {
            throw new RuntimeException('প্রোভাইডার উত্তর দিল HTTP '.$r->status());
        }

        return count($r->json('data') ?? []);
    }
}
