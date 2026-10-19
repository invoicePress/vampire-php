<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Fails when documentation and code drift apart.
 *
 * Recomputes the test-coverage numbers over the whole fixture corpus and compares them with the
 * stored `docs/developer/coverage-tests.json`. Adding a fixture, a mutation or a rule set changes the numbers,
 * the stored file goes stale, and this test says so instead of letting the documentation quietly
 * become fiction.
 *
 * ⚠️ Deliberately slow (it validates all fixtures a second time). That is the price of a coverage
 * claim that is measured rather than asserted; run `php devkit/Cli/coverage-tests.php --apply` to
 * refresh the file after a legitimate change.
 */
final class CoverageDocTest extends TestCase
{
    public function testStoredCoverageNumbersStillMatchReality(): void
    {
        $base = dirname(__DIR__, 2);
        $stored = $base . '/docs/developer/coverage-tests.json';

        self::assertFileExists($stored, 'Run devkit/Cli/coverage-tests.php --apply.');

        require_once $base . '/devkit/Cli/coverage-tests.php';

        $measured = \EinvoiceValidator\Devkit\Cli\measure_test_coverage($base);

        $raw = file_get_contents($stored);
        self::assertNotFalse($raw);

        /** @var array{rulesets: array<string, array{rules: int, fired: int}>, files: int} $expected */
        $expected = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            $expected,
            $measured,
            'docs/developer/coverage-tests.json ist veraltet - php devkit/Cli/coverage-tests.php --apply'
        );

        $doc = file_get_contents($base . '/docs/developer/coverage.md');
        self::assertNotFalse($doc);
        self::assertStringContainsString('## Testabdeckung (Aufgabe 4.5)', $doc);

        foreach ($measured['rulesets'] as $ref => $numbers) {
            self::assertStringContainsString(
                sprintf('| `%s` | %d | %d |', $ref, $numbers['rules'], $numbers['fired']),
                $doc,
                "docs/developer/coverage.md nennt für $ref andere Zahlen als die Messung."
            );
        }
    }
}
