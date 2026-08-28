<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

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

    /**
     * Send this response's session cookie on the next request, so the two are
     * one session.
     *
     * Laravel's test client does not carry a response's cookies into the next
     * call, so by default two requests in a test are two unrelated sessions.
     * Anything comparing a session id before and after therefore always sees
     * a difference and cannot fail — which is what
     * `LoginTest::test_a_fixated_session_does_not_survive_login` used to do.
     *
     * The cookie value is already encrypted, so it goes back through
     * `withUnencryptedCookie()` and `EncryptCookies` decrypts it exactly as it
     * would a browser's. The caller also needs a session store that persists
     * between requests — `SESSION_DRIVER` is `array` — and should assert a
     * control first: two plain requests on the carried cookie must keep the
     * *same* id, or a harness that quietly failed to carry anything reports a
     * rotation every time.
     */
    protected function continuingSession(TestResponse $response): static
    {
        $name = config('session.cookie');

        $cookie = collect($response->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === $name);

        $this->assertNotNull($cookie, 'The response set no session cookie to continue.');

        return $this->withUnencryptedCookie($name, $cookie->getValue());
    }
}
