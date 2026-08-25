<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase drops every table it finds. If the suite is ever pointed
     * at the development database — a stale bootstrap/cache/config.php, a
     * DB_DATABASE exported into the shell, a copied phpunit.xml — that wipes
     * the seeded demo content the manual verification passes depend on. Refuse
     * to run rather than let that happen.
     */
    protected function setUpTraits()
    {
        // Not setUp(): RefreshDatabase does its dropping from a trait hook that
        // parent::setUp() fires, so a guard placed after that call would run
        // only once the damage was done. setUpTraits() is the seam between the
        // application booting and the trait hooks running.
        $database = DB::connection()->getDatabaseName();

        if (! str_ends_with((string) $database, '_test')) {
            $this->fail(
                "Refusing to run: tests are connected to '{$database}', which is not a "
                ."'*_test' database. Check phpunit.xml, run `php artisan config:clear`, "
                .'and make sure DB_DATABASE is not exported in your shell.'
            );
        }

        return parent::setUpTraits();
    }
}
