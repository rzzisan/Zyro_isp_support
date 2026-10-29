<?php

namespace Tests;

use App\Models\SmsGateway;
use App\Models\SmsSetting;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** A configured Khudebarta gateway as the company's main SMS gateway. */
    protected function smsGateway(int $companyId, array $attributes = []): SmsGateway
    {
        $g = SmsGateway::create(['company_id' => $companyId, 'provider' => 'khudebarta',
            'endpoint_url' => SmsSetting::KHUDEBARTA_SEND_URL, 'api_key' => 'a', 'secret_key' => 'b',
            'caller_id' => 'CLN', ...$attributes]);
        SmsSetting::forCompany($companyId)->fill(['primary_gateway_id' => $g->id])->save();

        return $g;
    }

    /** Never run against the live database (a cached config would point the tests there). */
    protected function setUpTraits()
    {
        if (config('database.connections.'.config('database.default').'.database') === 'zyro_support') {
            throw new \RuntimeException('refusing to run tests on the live database: run php artisan config:clear first');
        }

        return parent::setUpTraits();
    }
}
