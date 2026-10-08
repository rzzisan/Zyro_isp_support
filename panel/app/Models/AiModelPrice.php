<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The company's price (USD per 1M tokens) for one provider/model, used to estimate AI cost. */
class AiModelPrice extends Model
{
    /** "provider|model" => [input, output] USD per 1M tokens, until the company sets its own. Voice (Whisper): per 1M audio seconds. */
    public const DEFAULTS = [
        'groq|openai/gpt-oss-120b' => [0.15, 0.60],
        'groq|openai/gpt-oss-20b' => [0.075, 0.30],
        'groq|llama-3.3-70b-versatile' => [0.59, 0.79],
        'groq|whisper-large-v3' => [30.83, 0], // $0.111 per audio hour
        'gemini|gemini-2.5-flash' => [0.30, 2.50],
        'gemini|gemini-2.5-flash-lite' => [0.10, 0.40],
        'claude|claude-haiku-5-5' => [0.10, 0.50],
        'claude|claude-sonnet-5-5' => [2.00, 10.00],
        'claude|claude-opus-5-5' => [4.00, 20.00],
    ];

    /** Input read from the provider's prompt cache costs about a tenth of the input price. */
    public const CACHED_INPUT_FACTOR = 0.1;

    protected $fillable = ['company_id', 'provider', 'model', 'input_per_million', 'output_per_million'];

    protected function casts(): array
    {
        return ['input_per_million' => 'float', 'output_per_million' => 'float'];
    }

    /** "provider|model" => [input, output, set by the company?] for this company. */
    public static function table(int $companyId): array
    {
        $out = array_map(fn ($p) => [$p[0], $p[1], false], self::DEFAULTS);
        foreach (self::where('company_id', $companyId)->get() as $p) {
            $out["{$p->provider}|{$p->model}"] = [$p->input_per_million, $p->output_per_million, true];
        }

        return $out;
    }
}
