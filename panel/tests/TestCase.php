<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    //

    /** Never run against the live database (a cached config would point the tests there). */
    protected function setUpTraits()
    {
        if (config('database.connections.'.config('database.default').'.database') === 'zyro_support') {
            throw new \RuntimeException('refusing to run tests on the live database: run php artisan config:clear first');
        }

        return parent::setUpTraits();
    }
}
