<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Task 4.3 — runs every expectation file in `tests/fixtures/expectations/` against the validator.
 *
 * Format (one JSON object per file):
 *
 *   {
 *     "file":     "invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml",  // relative to tests/fixtures/
 *     "scenario": "EN16931 XRechnung (UBL Invoice)",                     // resolved scenario, or null
 *     "accept":   true,
 *     "rules":    [ { "id": "BR-DE-TMP-32", "flag": "information" } ],   // findings that MUST appear
 *     "mustNotBlock": [ "BR-CL-10" ],                                    // may fire, never as fatal
 *     "note":     "freitext, warum dieser Fall existiert"
 *   }
 *
 * ⚠️ `rules` is a REQUIRED SUBSET, not the complete list. Pinning every information-level finding of
 * 86 documents would turn each harmless new notice into a red test, and the file would stop saying
 * what it is actually about.
 *
 * ⚠️ `mustNotBlock` is deliberately NOT "must not appear". The rules a scenario downgrades DO still
 * fire - BR-CL-10 reports the Extension's `XR01` scheme id exactly as before, it just carries
 * `information` now. A first draft asserted absence and would have failed on correct behaviour. What
 * is being pinned here is the severity, because that is what the `customLevel` regression changed.
 */
final class ExpectationsTest extends TestCase
{
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
    public static function expectationProvider(): array
    {
        $files = glob(self::fixturesDir() . '/expectations/*.json');
        self::assertNotFalse($files, 'No expectations directory.');
        sort($files);

        $cases = [];

        foreach ($files as $file) {
            $cases[basename($file, '.json')] = [$file];
        }

        return $cases;
    }

    #[DataProvider('expectationProvider')]
    public function testExpectationHolds(string $expectationFile): void
    {
        $raw = file_get_contents($expectationFile);
        self::assertNotFalse($raw);

        /** @var array{file: string, scenario?: string|null, accept: bool, rules?: list<array{id: string, flag: string}>, mustNotBlock?: list<string>} $expected */
        $expected = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $invoice = self::fixturesDir() . '/' . $expected['file'];
        self::assertFileExists($invoice, "Expectation references a missing invoice: {$expected['file']}");

        $resolver = ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json');
        $validator = new Validator(scenarioResolver: $resolver, baseDir: self::baseDir());

        $result = $validator->validateFile($invoice);

        $actual = [];
        foreach ($result->messages() as $message) {
            if ($message->ruleId !== null) {
                $actual[$message->ruleId] = $message->flag->value;
            }
        }

        if (array_key_exists('scenario', $expected)) {
            self::assertSame($expected['scenario'], $result->scenarioName(), 'Wrong scenario resolved.');
        }

        foreach ($expected['rules'] ?? [] as $rule) {
            self::assertArrayHasKey($rule['id'], $actual, "Expected rule {$rule['id']} did not fire.");
            self::assertSame($rule['flag'], $actual[$rule['id']], "Rule {$rule['id']} fired with the wrong severity.");
        }

        foreach ($expected['mustNotBlock'] ?? [] as $ruleId) {
            if (!array_key_exists($ruleId, $actual)) {
                continue;
            }

            self::assertNotSame('fatal', $actual[$ruleId], "Rule $ruleId blocks acceptance; the scenario downgrades it.");
        }

        self::assertSame(
            $expected['accept'],
            $result->isAccepted(),
            'accept/reject differs. Findings: ' . json_encode($actual, JSON_THROW_ON_ERROR)
        );
    }
}
