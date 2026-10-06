<?php

namespace App\Console\Commands;

use App\Models\AiKey;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Copies the settings of the original single-company bot into a company.
 * Reads JSON on stdin (secrets never touch the command line or logs).
 */
class ImportLegacyBot extends Command
{
    protected $signature = 'zyro:import-legacy {company : company slug}';

    protected $description = 'Import billing, AI keys, bot settings and WhatsApp numbers from the legacy bot (JSON on stdin)';

    public function handle(): int
    {
        $company = Company::where('slug', $this->argument('company'))->firstOrFail();
        $in = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

        if (! empty($in['billing']['username'])) {
            $company->billingConnection()->updateOrCreate([], [
                'provider' => 'ispdigital',
                'base_url' => rtrim($in['billing']['base_url'], '/'),
                'username' => $in['billing']['username'],
                'password' => $in['billing']['password'],
            ]);
            $this->info('billing connection: imported');
        }

        $added = 0;
        foreach ($in['ai_keys'] ?? [] as $k) {
            $exists = $company->aiKeys()->get()->contains(fn (AiKey $a) => $a->api_key === $k['api_key']);
            if (! $exists) {
                $company->aiKeys()->create(['provider' => $k['provider'], 'label' => $k['label'] ?: 'imported',
                    'api_key' => $k['api_key'], 'model' => $k['model']]);
                $added++;
            }
        }
        $this->info("ai keys: {$added} imported");

        if (! empty($in['bot'])) {
            $company->botSetting()->updateOrCreate([], $in['bot']);
            $this->info('bot settings: imported');
        }

        foreach ($in['wa_accounts'] ?? [] as $w) {
            $company->waAccounts()->updateOrCreate(['phone_number_id' => $w['phone_number_id']], $w);
        }
        $this->info('whatsapp numbers: '.count($in['wa_accounts'] ?? []).' imported');

        return self::SUCCESS;
    }
}
