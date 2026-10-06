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
        'last_checked_at', 'last_check_ok'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'rate_limited_until' => 'datetime',
            'last_checked_at' => 'datetime', 'last_check_ok' => 'boolean'];
    }

    public static function providerOptions(): array
    {
        return array_map(fn ($p) => $p[0], self::PROVIDERS);
    }

    public function maskedKey(): string
    {
        return '••••'.substr((string) $this->api_key, -4);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
