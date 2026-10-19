<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Pipeline;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Pipeline\Scenario;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.7 — automatic package selection: with several scenarios matching, the invoice date decides;
 * without a date the newest wins; an explicit choice by the application beats both. All three cases
 * are required by the task, so all three are tested.
 *
 * ⚠️ The scenarios here carry synthetic validity periods on purpose. None of the three official
 * configurations states one (see Scenario's own docblock), so a test built on shipped data could not
 * exercise this at all - and filling the shipped scenarios with invented dates to make a test pass
 * would be the wrong way round.
 */
final class ScenarioSelectionTest extends TestCase
{
    private function ublDocument(?string $issueDate): \DOMDocument
    {
        $date = $issueDate === null ? '' : "<cbc:IssueDate>$issueDate</cbc:IssueDate>";

        $dom = new \DOMDocument();
        $dom->loadXML(
            '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            . ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cbc:CustomizationID>urn:test</cbc:CustomizationID>' . $date . '</Invoice>'
        );

        return $dom;
    }

    private function ciiDocument(string $yyyymmdd): \DOMDocument
    {
        $dom = new \DOMDocument();
        $dom->loadXML(
            '<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"'
            . ' xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"'
            . ' xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">'
            . '<rsm:ExchangedDocument><ram:IssueDateTime><udt:DateTimeString format="102">'
            . $yyyymmdd
            . '</udt:DateTimeString></ram:IssueDateTime></rsm:ExchangedDocument></rsm:CrossIndustryInvoice>'
        );

        return $dom;
    }

    private function resolver(): ScenarioResolver
    {
        $always = Parser::parse('true()');

        return new ScenarioResolver([
            new Scenario('old', 'Alte Fassung', $always, [], null, ['A@1'], [], null, '2025-12-31'),
            new Scenario('current', 'Aktuelle Fassung', $always, [], null, ['A@2'], [], '2026-01-01', null),
        ]);
    }

    public function testTheInvoiceDatePicksTheOlderPackage(): void
    {
        $scenario = $this->resolver()->resolve($this->ublDocument('2025-06-30'));

        self::assertSame('old', $scenario?->id);
    }

    public function testTheInvoiceDatePicksTheCurrentPackage(): void
    {
        $scenario = $this->resolver()->resolve($this->ublDocument('2026-06-30'));

        self::assertSame('current', $scenario?->id);
    }

    public function testTheCiiDateFormatIsUnderstood(): void
    {
        $scenario = $this->resolver()->resolve($this->ciiDocument('20250630'));

        self::assertSame('old', $scenario?->id, 'CII schreibt das Datum als YYYYMMDD.');
    }

    public function testWithoutADateTheFirstListedScenarioWins(): void
    {
        $scenario = $this->resolver()->resolve($this->ublDocument(null));

        self::assertSame('old', $scenario?->id);
    }

    public function testAnExplicitChoiceBeatsTheDate(): void
    {
        $scenario = $this->resolver()->resolve($this->ublDocument('2025-06-30'), 'current');

        self::assertSame('current', $scenario?->id, 'Die Vorgabe der Anwendung hat Vorrang.');
    }

    public function testAnUnknownExplicitChoiceIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->resolver()->resolve($this->ublDocument('2026-06-30'), 'does-not-exist');
    }

    public function testAScenarioWithoutValidityPeriodAppliesAlways(): void
    {
        $scenario = new Scenario('any', 'Immer', Parser::parse('true()'));

        self::assertTrue($scenario->appliesOn('1999-01-01'));
        self::assertTrue($scenario->appliesOn('2099-12-31'));
        self::assertTrue($scenario->appliesOn(null));
    }
}
