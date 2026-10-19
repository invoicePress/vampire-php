<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Result\Stage;
use EinvoiceValidator\Result\StageStatus;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Task 4.1's own end-to-end proof: the real `rulesets/scenarios.json` (imported from the real
 * KoSIT scenarios.xml) drives the WHOLE pipeline - scenario resolution picks the XSD AND the
 * ruleset set, both loaded from their real, shipped locations, run against a real official
 * XRechnung test invoice. If this passes, every piece built since Phase 0 (Parser, Interpreter,
 * Importer, Engine, ScenarioResolver, Validator) works together on genuine production data, not
 * just in isolation.
 */
final class ValidatorScenarioTest extends TestCase
{
    private function baseDir(): string
    {
        return dirname(__DIR__, 2);
    }

    private function validator(): Validator
    {
        $resolver = ScenarioResolver::fromJsonFile($this->baseDir() . '/rulesets/scenarios.json');

        return new Validator(scenarioResolver: $resolver, baseDir: $this->baseDir());
    }

    public function testTheRealValidXRechnungInvoiceIsAcceptedThroughTheFullScenarioDrivenPipeline(): void
    {
        $result = $this->validator()->validateFile(
            dirname(__DIR__) . '/fixtures/invoices/valid-ubl-invoice.xml'
        );

        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Wellformed));
        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Xsd));
        self::assertSame(StageStatus::Passed, $result->stageStatus(Stage::Business));
        self::assertTrue($result->isAccepted(), self::describeFailures($result));
    }

    /**
     * @return string[]
     */
    private function officialUblFixtures(): array
    {
        $files = glob($this->baseDir() . '/tests/fixtures/invoices/xrechnung-standard/*_ubl.xml');
        self::assertNotFalse($files);
        sort($files);

        return $files;
    }

    public function testEveryOfficialUblTestInvoiceIsAcceptedThroughTheFullScenarioDrivenPipeline(): void
    {
        $validator = $this->validator();

        $failures = [];
        foreach ($this->officialUblFixtures() as $file) {
            $result = $validator->validateFile($file);
            if (!$result->isAccepted()) {
                $failures[] = basename($file) . ': ' . self::describeFailures($result);
            }
        }

        self::assertSame([], $failures);
    }

    /**
     * Every instance of the official suite, all four groups, both syntaxes - 86 files.
     *
     * ⚠️ This is the test that shows a missing `customLevel` handling. A test over only the 33 UBL files of
     * the "standard" group passes with or without the scenario's severity overrides. The Extension and CVD
     * groups do not: their documents deliberately use the Extension's own `XR01`/`XR03` scheme ids, a
     * prepaid amount and `listID="CVD"`, all of which violate EN 16931 rules that the KoSIT scenario
     * downgrades to `information` for exactly those scenarios. Without the override, 4 of these 86
     * official, valid instances were rejected ( measured 21.09.2026 ).
     *
     * @return array<string, array{string}>
     */
    public static function officialInstanceProvider(): array
    {
        $root = dirname(__DIR__) . '/fixtures/invoices';
        $cases = [];

        foreach (['xrechnung-standard', 'xrechnung-extension', 'xrechnung-cius', 'xrechnung-cvd'] as $group) {
            $files = glob("$root/$group/*.xml");
            self::assertNotFalse($files, "Fixture group $group is missing.");
            sort($files);

            foreach ($files as $file) {
                $cases["$group/" . basename($file)] = [$file];
            }
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('officialInstanceProvider')]
    public function testEveryOfficialInstanceOfTheSuiteIsAccepted(string $file): void
    {
        $result = $this->validator()->validateFile($file);

        self::assertTrue($result->isAccepted(), self::describeFailures($result));
    }

    public function testACiusExtensionCustomizationIdIsRejectedByTheBaselineCiusScenarioMatch(): void
    {
        // Sanity check that scenario resolution is genuinely selective, not "anything matches
        // the first scenario": an unknown CustomizationID must resolve to no scenario at all.
        $xml = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cbc:CustomizationID>urn:something:unknown</cbc:CustomizationID></Invoice>';

        $result = $this->validator()->validateString($xml);

        self::assertFalse($result->isAccepted());
    }

    private static function describeFailures(\EinvoiceValidator\Result\Result $result): string
    {
        $lines = [];
        foreach ($result->messages() as $message) {
            if ($message->flag->blocksAcceptance()) {
                $lines[] = "[{$message->ruleId}] {$message->text}";
            }
        }

        return implode(' | ', $lines);
    }
}
