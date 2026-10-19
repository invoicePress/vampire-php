<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Engine;

use EinvoiceValidator\Engine\Engine;
use EinvoiceValidator\Pipeline\XmlLoader;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;
use PHPUnit\Framework\TestCase;

/**
 * Task 3.5's own acceptance criterion, run directly against Engine (Pipeline/Result/Stage wiring
 * is a separate, later concern - see Engine's own docblock): the real, official EN16931-UBL
 * ruleset (rulesets/EN16931-UBL@1.3.16.json, imported from the real compiled Schematron-XSLT)
 * evaluated against the real, official, valid XRechnung test-suite invoice (case 01.01) must
 * produce zero Fatal-flag findings.
 */
final class EngineRealRulesetTest extends TestCase
{
    private function invoice(): \DOMDocument
    {
        $path = dirname(__DIR__, 2) . '/fixtures/invoices/valid-ubl-invoice.xml';

        return (new XmlLoader())->loadFile($path);
    }

    public function testEn16931UblProducesNoFatalFindingOnTheRealValidInvoice(): void
    {
        $path = dirname(__DIR__, 3) . '/rulesets/EN16931-UBL@1.3.16.json';
        $ruleset = RulesetJsonCodec::readFile($path);

        $findings = (new Engine())->run($ruleset, $this->invoice());

        $fatal = array_values(array_filter($findings, static fn ($f) => $f->flag === Flag::Fatal));

        self::assertSame(
            [],
            array_map(static fn ($f) => "{$f->ruleId}: {$f->text}", $fatal),
            'the real, valid XRechnung test invoice must not trigger any EN16931-UBL fatal rule'
        );
    }

    public function testEn16931CiiRulesetLoadsAndRunsWithoutError(): void
    {
        // The real fixture invoice is UBL, so EN16931-CII's own rules mostly won't match (wrong
        // syntax) - this only proves the ruleset loads and the engine runs it end to end without
        // throwing, not that every rule is exercised (that is Task 4's coverage work).
        $path = dirname(__DIR__, 3) . '/rulesets/EN16931-CII@1.3.16.json';
        $ruleset = RulesetJsonCodec::readFile($path);

        $findings = (new Engine())->run($ruleset, $this->invoice());

        self::assertIsArray($findings);
    }
}
