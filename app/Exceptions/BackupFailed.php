<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A backup did not happen, or happened and cannot be trusted.
 *
 * Exists to give the failure a type so `ErrorAlerter` can carry it. The
 * alerter fingerprints on class, file and line, which is exactly the shape
 * wanted here: one alert an hour for a nightly job that keeps failing the same
 * way, rather than one per artifact per run.
 *
 * Never thrown into a request. `backup:run` and `backup:sync` catch their own
 * failures, report them, and exit non-zero — a command that dies with an
 * uncaught exception prints a stack trace to `backup.log` and tells nobody.
 */
class BackupFailed extends RuntimeException
{
}
