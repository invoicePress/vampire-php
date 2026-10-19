<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Engine;

use EinvoiceValidator\Engine\Engine;
use EinvoiceValidator\Pipeline\XmlLoader;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\Ruleset;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;
use PHPUnit\Framework\TestCase;

/**
 * Every officially valid EN sample invoice runs through without errors - not just one fixture, but the
 * FULL official "standard" (no CIUS extension) set of the real XRechnung 3.0.2 conformance test suite
 * (tests/fixtures/invoices/xrechnung-standard/, copied from vendor-sources/test-invoices/ - see
 * docs/notes/decisions.md for why these are copied into tracked fixtures rather than read from the git-ignored
 * vendor-sources/ directly). Every one of these is a POSITIVE business test case per the suite's own
 * test-overview.md, so every UBL instance must produce zero Fatal findings against EN16931-UBL, and every
 * CII (uncefact) instance zero Fatal findings against EN16931-CII.
 */
final class EngineOfficialTestSuiteTest extends TestCase
{
    private function fixturesDir(): string
    {
        return dirname(__DIR__, 2) . '/fixtures/invoices/xrechnung-standard';
    }

    /**
     * @return string[]
     */
    private function filesMatching(string $suffix): array
    {
        $files = glob($this->fixturesDir() . "/*$suffix");
        self::assertNotFalse($files);
        self::assertNotEmpty($files, "no fixture files found matching *$suffix");
        sort($files);

        return $files;
    }

    private function loadRuleset(string $id, string $version): Ruleset
    {
        return RulesetJsonCodec::readFile(dirname(__DIR__, 3) . "/rulesets/$id@$version.json");
    }

    public function testEveryOfficialUblInstanceProducesNoFatalFindingAgainstEn16931Ubl(): void
    {
        $ruleset = $this->loadRuleset('EN16931-UBL', '1.3.16');
        $engine = new Engine();
        $loader = new XmlLoader();

        $failures = [];
        foreach ($this->filesMatching('_ubl.xml') as $file) {
            $document = $loader->loadFile($file);
            $findings = $engine->run($ruleset, $document);
            foreach ($findings as $finding) {
                if ($finding->flag === Flag::Fatal) {
                    $failures[] = basename($file) . ': [' . $finding->ruleId . '] ' . $finding->text;
                }
            }
        }

        self::assertSame([], $failures);
    }

    public function testEveryOfficialCiiInstanceProducesNoFatalFindingAgainstEn16931Cii(): void
    {
        $ruleset = $this->loadRuleset('EN16931-CII', '1.3.16');
        $engine = new Engine();
        $loader = new XmlLoader();

        $failures = [];
        foreach ($this->filesMatching('_uncefact.xml') as $file) {
            $document = $loader->loadFile($file);
            $findings = $engine->run($ruleset, $document);
            foreach ($findings as $finding) {
                if ($finding->flag === Flag::Fatal) {
                    $failures[] = basename($file) . ': [' . $finding->ruleId . '] ' . $finding->text;
                }
            }
        }

        self::assertSame([], $failures);
    }
}
