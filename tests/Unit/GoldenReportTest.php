<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 4.6 — this library against the reference implementation, document by document.
 *
 * The comparison material in `tests/fixtures/golden/` was produced by the **real KoSIT validator**
 * (`devkit/Cli/kosit-golden.php`, which starts Java and is run by hand). **This test needs no Java**:
 * it reads the recorded reports.
 *
 * Compared per document: the matched scenario, the verdict, the set of rule ids, and the severity of
 * each one. Deliberate deviations live in `golden/exceptions.json` - with a reason, one by one.
 *
 * ⚠️ **Severity is compared against `nativeFlag()`, not `flag`.** The original writes the RULE SET's
 * severity into its report and lets `customLevel` decide the verdict alone: a CVD document shows
 * `BR-CL-13` as `error` and is accepted anyway. Comparing the effective severity produced ten
 * "differences" that were none.
 *
 * ⚠️ **This is the acceptance criterion of Phase 4**, not the coverage figure from 4.5. What counts is
 * that PHP and the original agree on the whole test bed.
 */
final class GoldenReportTest extends TestCase
{
    /** KoSIT's vocabulary to ours. */
    private const LEVELS = ['information' => 'information', 'warning' => 'warning', 'error' => 'fatal'];

    private static function baseDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function fixturesDir(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /**
     * @return array<string, array{string}>
     */
    public static function goldenProvider(): array
    {
        $files = glob(self::fixturesDir() . '/golden/*.json');
        self::assertNotFalse($files, 'Kein goldener Bestand - devkit/Cli/kosit-golden.php --apply.');
        sort($files);

        $cases = [];

        foreach ($files as $file) {
            if (basename($file) === 'exceptions.json') {
                continue;
            }

            $cases[basename($file, '.json')] = [$file];
        }

        return $cases;
    }

    /**
     * @return array<string, mixed>
     */
    private static function exceptions(): array
    {
        $raw = file_get_contents(self::fixturesDir() . '/golden/exceptions.json');
        self::assertNotFalse($raw);

        /** @var array<string, mixed> $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    #[DataProvider('goldenProvider')]
    public function testOurVerdictMatchesTheReferenceValidator(string $goldenFile): void
    {
        $raw = file_get_contents($goldenFile);
        self::assertNotFalse($raw);

        /** @var array{file: string, scenario: ?string, accept: bool, messages: array<string, string>} $golden */
        $golden = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $invoice = self::fixturesDir() . '/' . $golden['file'];
        self::assertFileExists($invoice);

        $validator = new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
        );

        $result = $validator->validateFile($invoice);

        self::assertSame($golden['scenario'], $result->scenarioName(), 'Anderes Szenario als der Referenzvalidator.');
        self::assertSame($golden['accept'], $result->isAccepted(), 'Anderes Urteil als der Referenzvalidator.');

        $exceptions = self::exceptions();
        /** @var array<string, mixed> $allowedOnlyOurs */
        $allowedOnlyOurs = $exceptions['onlyOurs'] ?? [];
        /** @var array<string, mixed> $allowedOnlyKosit */
        $allowedOnlyKosit = $exceptions['onlyKosit'] ?? [];
        /** @var array<string, mixed> $allowedLevel */
        $allowedLevel = $exceptions['level'] ?? [];

        $ours = [];

        foreach ($result->messages() as $message) {
            if ($message->ruleId !== null) {
                $ours[$message->ruleId] = $message->nativeFlag()->value;
            }
        }

        $theirs = [];

        foreach ($golden['messages'] as $code => $level) {
            // The original reports an XSD failure without a rule code. Our XSD stage produces its own
            // wording, so there is nothing to compare id by id - the verdict above already covers it.
            if ($code === 'XSD') {
                continue;
            }

            $theirs[$code] = self::LEVELS[$level] ?? $level;
        }

        foreach (array_diff_key($theirs, $ours) as $code => $level) {
            self::assertArrayHasKey(
                $code,
                $allowedOnlyKosit,
                "Der Referenzvalidator meldet $code ($level), wir nicht - und es steht keine Begründung in exceptions.json."
            );
        }

        foreach (array_diff_key($ours, $theirs) as $code => $level) {
            self::assertArrayHasKey(
                $code,
                $allowedOnlyOurs,
                "Wir melden $code ($level), der Referenzvalidator nicht - und es steht keine Begründung in exceptions.json."
            );
        }

        foreach (array_intersect_key($theirs, $ours) as $code => $level) {
            if ($ours[$code] === $level) {
                continue;
            }

            self::assertArrayHasKey(
                $code,
                $allowedLevel,
                "Einstufung von $code: Referenz \"$level\", wir \"{$ours[$code]}\" - ohne Begründung in exceptions.json."
            );
        }
    }
}
