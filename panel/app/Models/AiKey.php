<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiKey extends Model
{
    /** provider id => [name, OpenAI-compatible base URL (null = Anthropic), suggested model] */
    public const PROVIDERS = [
        'groq' => ['Groq', 'https://api.groq.com/openai/v1', 'openai/gpt-oss-120b'],
        'gemini' => ['Google Gemini', 'https://generativelanguage.googleapis.com/v1beta/openai', 'gemini-2.5-flash'],
        'claude' => ['Anthropic Claude', null, 'claude-opus-5-5'],
        'grok' => ['xAI Grok', 'https://api.x.ai/v1', 'grok-4'],
        'openai' => ['OpenAI', 'https://api.openai.com/v1', ''],
        'openrouter' => ['OpenRouter', 'https://openrouter.ai/api/v1', ''],
    ];

    protected $fillable = ['company_id', 'provider', 'label', 'api_key', 'model', 'rate_limited_until',
        'last_checked_at', 'last_check_ok', 'quota', 'quota_at'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'rate_limited_until' => 'datetime',
            'last_checked_at' => 'datetime', 'last_check_ok' => 'boolean', 'quota' => 'array', 'quota_at' => 'datetime'];
    }

    public static function providerOptions(): array
    {
        return array_map(fn ($p) => $p[0], self::PROVIDERS);
    }

    public function maskedKey(): string
    {
        return '••••'.substr((string) $this->api_key, -4);
    }

    /**
     * What the provider last told us about this key's limits, as readable lines
     * (Groq/OpenAI/xAI x-ratelimit-* headers, Anthropic anthropic-ratelimit-*, and the daily limit from Groq's 429 text).
     */
    public function quotaLines(): array
    {
        $q = $this->quota ?? [];
        $lines = [];
        $pair = function (string $label, ?string $limit, ?string $remaining, ?string $reset) use (&$lines) {
            if ($limit === null && $remaining === null) {
                return;
            }
            $lines[] = $label.': '.__(':left বাকি / :limit', ['left' => $remaining !== null ? number_format((int) $remaining) : '?',
                'limit' => $limit !== null ? number_format((int) $limit) : '?']).($reset ? ' '.__('(রিসেট :at)', ['at' => $reset]) : '');
        };
        $groq = $this->provider === 'groq';
        $pair($groq ? __('আজকের রিকোয়েস্ট') : __('রিকোয়েস্ট'), $q['x-ratelimit-limit-requests'] ?? null,
            $q['x-ratelimit-remaining-requests'] ?? null, $q['x-ratelimit-reset-requests'] ?? null);
        $pair(__('মিনিটে টোকেন'), $q['x-ratelimit-limit-tokens'] ?? null,
            $q['x-ratelimit-remaining-tokens'] ?? null, $q['x-ratelimit-reset-tokens'] ?? null);
        $pair(__('রিকোয়েস্ট'), $q['anthropic-ratelimit-requests-limit'] ?? null,
            $q['anthropic-ratelimit-requests-remaining'] ?? null, null);
        $pair(__('মিনিটে টোকেন'), $q['anthropic-ratelimit-tokens-limit'] ?? null,
            $q['anthropic-ratelimit-tokens-remaining'] ?? null, null);
        $names = ['tpd' => __('দৈনিক টোকেন'), 'rpd' => __('দৈনিক রিকোয়েস্ট'), 'tpm' => __('মিনিটে টোকেন'), 'rpm' => __('মিনিটে রিকোয়েস্ট')];
        foreach ($q as $k => $v) {
            $at = isset($v['at']) ? \Illuminate\Support\Carbon::parse($v['at']) : null;
            if (str_starts_with($k, 'limit:') && is_array($v) && $at && $at->gt(now()->subDay())) {
                $lines[] = __(':name শেষ হয়েছিল: :used / :limit (:at)', ['name' => $names[substr($k, 6)] ?? strtoupper(substr($k, 6)),
                    'used' => number_format((int) $v['used']), 'limit' => number_format((int) $v['limit']), 'at' => $at->timezone('Asia/Dhaka')->format('j M g:i A')]);
            }
        }

        return $lines;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
