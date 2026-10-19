<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Support;

/**
 * Files outside this repository that some tests compare against.
 *
 * ⚠️ THE TESTS THAT NEED THEM ARE SKIPPED WITHOUT THEM, NEVER FAILED. A checkout of this repository has neither the
 * invoice corpus nor the recordings of the JavaScript implementation; the environment variables name them where they
 * exist:
 *
 *   VAMPIRE_EXAMPLES         the folder with `invoices/`, `validation_reports/` and `xrechnung-4.0-synthetic/`
 *   VAMPIRE_JS_RECORDINGS    the folder with the recordings the JavaScript implementation compares against
 *   VAMPIRE_IDENTIFIER_CASES the file `cases.json` of an identifier library with check-digit vectors
 */
final class Corpus
{
    /** The examples folder, or a path that does not exist, so that every lookup below it finds nothing. */
    public static function examples(): string
    {
        return self::fromEnv('VAMPIRE_EXAMPLES') ?? '/vampire-examples-not-configured';
    }

    /** The folder of the recordings of the JavaScript implementation, or a path that does not exist. */
    public static function jsRecordings(): string
    {
        return self::fromEnv('VAMPIRE_JS_RECORDINGS') ?? '/vampire-js-recordings-not-configured';
    }

    /** The check-digit vectors of an identifier library, or a path that does not exist. */
    public static function identifierCases(): string
    {
        return self::fromEnv('VAMPIRE_IDENTIFIER_CASES') ?? '/vampire-identifier-cases-not-configured';
    }

    private static function fromEnv(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? rtrim(str_replace('\\', '/', $value), '/') : null;
    }
}
