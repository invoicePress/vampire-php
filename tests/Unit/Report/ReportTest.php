<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Report;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Report\HtmlReport;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Report\KositXmlReport;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * Phase 7 — the three report formats, each rendered from a REAL validation of a document that really
 * fails (the mutated invoice from Task 4.2). A report of an empty result proves nothing about how it
 * renders findings.
 */
final class ReportTest extends TestCase
{
    private static function baseDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function failingResult(): Result
    {
        $validator = new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
        );

        return $validator->validateFile(
            dirname(__DIR__, 2) . '/fixtures/invoices/mutated/ubl-empty-invoice-number.xml'
        );
    }

    public function testTheJsonReportCarriesTheDocumentedShape(): void
    {
        $data = (new JsonReport())->toArray(self::failingResult());

        // `profiles`, `officialAccepted`, `missingRulesets` and `notices` were added within 1.1, at the end
        // (additions only).
        self::assertSame(
            ['schemaVersion', 'accepted', 'scenario', 'rulesets', 'document', 'stages', 'counts', 'findings', 'profiles', 'officialAccepted', 'missingRulesets', 'notices'],
            array_keys($data)
        );
        self::assertSame([], $data['missingRulesets']);
        self::assertSame([], $data['notices']);
        self::assertSame([], $data['profiles']);
        self::assertNull($data['officialAccepted']);

        self::assertFalse($data['accepted']);
        // 1.1 since Task 5.8a: `ruleset` (one) became `rulesets` (a list of origins).
        self::assertSame('1.1', $data['schemaVersion']);
        self::assertGreaterThan(0, $data['counts']['fatal']);
        self::assertSame(
            ['stage', 'rule', 'rulesets', 'severity', 'ruleSeverity', 'text', 'location', 'line', 'fields', 'origin', 'profile', 'severityBy'],
            array_keys($data['findings'][0])
        );
        self::assertSame('official', $data['findings'][0]['origin']);
        self::assertNotSame([], $data['findings'][0]['rulesets'], 'Ein Befund ohne Herkunft.');
    }

    public function testTheJsonReportIsValidJson(): void
    {
        $json = (new JsonReport())->toJson(self::failingResult());

        self::assertIsArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * ⚠️ The structure is checked against the real KoSIT shape, element for element: `rep:report` with
     * `varlVersion`, an engine name, one `rep:validationStepResult` per rule set, `rep:message` with
     * `level`/`code`/`xpathLocation`, and a `rep:assessment` carrying `rep:reject`. That list comes
     * from an actual KoSIT report ( a report of the KoSIT validator in the examples folder ), not from
     * memory of the specification.
     */
    /**
     * The report must say WHAT it was checked with - library, version and rule package.
     *
     * ⚠️ The line stands in `rep:engine/rep:name` and nowhere else. In the VARL schema `rep:engine` knows
     * only this one child; an element of its own for the rule packages would violate the schema, and the
     * report is in the KoSIT format precisely so that foreign readers accept it.
     *
     * ⚠️ The name must NEVER say "KoSIT Validator". The format is borrowed, the verdict is ours; a report
     * that passes itself off as the reference implementation's would be a claim nobody can check while
     * reading.
     */
    public function testTheKositReportNamesTheLibraryAndTheRulePackages(): void
    {
        $xml = (new KositXmlReport('specialpress/einvoice-validator', '9.9.9'))
            ->render(self::failingResult(), '<Invoice/>');

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');

        $nodes = $xpath->query('/rep:report/rep:engine/rep:name');
        self::assertNotFalse($nodes);
        self::assertSame(1, $nodes->length);

        $name = (string) $nodes->item(0)?->textContent;

        self::assertStringContainsString('specialpress/einvoice-validator 9.9.9', $name);
        self::assertStringContainsString('Regelpaket ', $name);
        self::assertStringContainsString('@', $name, 'Ein Regelpaket ohne Fassung ist keine Kennzeichnung.');
        self::assertStringNotContainsStringIgnoringCase('KoSIT Validator', $name);
    }

    public function testTheKositReportHasTheStructureTheExplainerReads(): void
    {
        $xml = (new KositXmlReport())->render(self::failingResult(), '<Invoice/>');

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml), 'Der Bericht ist nicht wohlgeformt.');

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');
        $xpath->registerNamespace('s', 'http://www.xoev.de/de/validator/framework/1/scenarios');

        self::assertSame('report', $dom->documentElement?->localName);
        self::assertSame('1.0.0', $dom->documentElement?->getAttribute('varlVersion'));
        self::assertSame('false', $dom->documentElement?->getAttribute('valid'));

        foreach (
            [
            '/rep:report/rep:engine/rep:name',
            '/rep:report/rep:timestamp',
            '/rep:report/rep:documentIdentification/rep:documentHash/rep:hashValue',
            '/rep:report/rep:scenarioMatched/s:scenario/s:name',
            '/rep:report/rep:scenarioMatched/rep:validationStepResult[@id="val-xsd"]',
            '/rep:report/rep:assessment/rep:reject',
            ] as $path
        ) {
            $nodes = $xpath->query($path);
            self::assertNotFalse($nodes);
            self::assertGreaterThan(0, $nodes->length, "Fehlt im Bericht: $path");
        }

        $messages = $xpath->query('//rep:message[@code="BR-02"]');
        self::assertNotFalse($messages);
        self::assertGreaterThan(0, $messages->length, 'Der verletzte Regelcode fehlt.');

        $first = $messages->item(0);
        self::assertInstanceOf(\DOMElement::class, $first);
        self::assertSame('error', $first->getAttribute('level'), 'KoSIT nennt unser "fatal" ein "error".');
        self::assertNotSame('', $first->getAttribute('xpathLocation'));
    }

    /**
     * The embedded document view, the "Inhalt des Rechnungsdokuments" of the original.
     *
     * ⚠️ THIS IS THE ONLY PLACE THE FIELD-BY-FIELD VIEW OF THE INVOICE EXISTS. `rep:documentData`
     * carries three fields; everything else a reader shows about the document comes out of the
     * `<table class="document">` inside `rep:explanation`. Without it a report renders findings and
     * nothing below them - which is exactly what the plugin showed on 21.09.2026.
     *
     * ⚠️ The markup is checked with the ORIGINAL's class names (`document`, `row`, `pos`, `element
     * levelN`, `val`, `attribute`) and with the element name in the `title` attribute, because that
     * is what `einvoice-report`'s reader queries. Checking "some table exists" would pass on markup
     * no reader can use.
     */
    public function testTheReportEmbedsTheDocumentViewInTheShapeReadersExpect(): void
    {
        $invoice = (string) file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml'
        );

        $xml = (new KositXmlReport())->render(self::failingResult(), $invoice);

        // ⚠️ Re-read as HTML, exactly as a reader does. Parsed as XML the query would have to know
        // about the XHTML namespace - and a report whose markup only works when queried one
        // particular way is a report that breaks for the next reader.
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');

        $explanation = $xpath->query('//rep:explanation')?->item(0);
        self::assertInstanceOf(\DOMElement::class, $explanation);

        $html = '';

        foreach ($explanation->childNodes as $child) {
            $html .= $dom->saveXML($child);
        }

        self::assertStringNotContainsString(
            'default:',
            $html,
            'libxml hat einen Präfix erfunden - ein Leser mit loadHTML() findet dann keine Tabelle.'
        );

        $htmlDom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $htmlDom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $htmlXpath = new \DOMXPath($htmlDom);

        $rows = $htmlXpath->query('//table[contains(@class,"document")]//tr[contains(@class,"row")]');
        self::assertNotFalse($rows);
        self::assertGreaterThan(50, $rows->length, 'Die Dokumentansicht fehlt oder ist leer.');

        $first = $rows->item(0);
        self::assertInstanceOf(\DOMElement::class, $first);

        $pos = $htmlXpath->query('./td[contains(@class,"pos")]', $first)?->item(0);
        self::assertInstanceOf(\DOMElement::class, $pos);
        self::assertSame('0001', trim($pos->textContent), 'Die Nummerierung beginnt bei 0001.');

        $element = $htmlXpath->query('./td[contains(@class,"element")]', $first)?->item(0);
        self::assertInstanceOf(\DOMElement::class, $element);
        self::assertSame('Invoice', $element->getAttribute('title'), 'Der Elementname steht im title.');
        self::assertStringContainsString('level1', $element->getAttribute('class'));

        // A value and an attribute must occur somewhere - otherwise the table would be an empty shell.
        $values = $htmlXpath->query('//table[contains(@class,"document")]//*[contains(@class,"val")]');
        self::assertNotFalse($values);
        self::assertGreaterThan(10, $values->length);

        $attributes = $htmlXpath->query('//table[contains(@class,"document")]//*[contains(@class,"attribute")]');
        self::assertNotFalse($attributes);
        self::assertGreaterThan(0, $attributes->length, 'Attribute wie currencyID fehlen.');
    }

    /**
     * ⚠️ No document, no document view - and above all no empty table body that a reader would show
     * as "the document is empty".
     */
    public function testWithoutTheDocumentThereIsNoDocumentView(): void
    {
        $xml = (new KositXmlReport())->render(self::failingResult());

        self::assertStringNotContainsString('class="document"', $xml);
        self::assertStringContainsString('Bewertung:', $xml, 'Die Bewertung steht auch ohne Beleg da.');
    }

    /**
     * Every rule set that ran gets a step, including one with no findings - "no step" would read as
     * "not checked".
     */
    public function testEveryRulesetThatRanAppearsAsItsOwnStep(): void
    {
        $result = self::failingResult();
        $xml = (new KositXmlReport())->render($result);

        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');

        $steps = $xpath->query('//rep:validationStepResult[starts-with(@id, "val-sch.")]');
        self::assertNotFalse($steps);
        self::assertSame(count($result->rulesets()), $steps->length);
        self::assertGreaterThan(0, count($result->rulesets()));
    }

    public function testTheHtmlReportIsSelfContainedAndEscapesContent(): void
    {
        $html = (new HtmlReport())->render(self::failingResult());

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Abgelehnt', $html);
        self::assertStringContainsString('<style>', $html, 'Der Bericht muss ohne externe Datei auskommen.');
        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('<script', $html);

        // The rule texts contain quotes and angle brackets; none of them may reach the output raw.
        self::assertStringNotContainsString('<Invoice', $html);
    }

    public function testTheHtmlReportOfAValidInvoiceSaysAccepted(): void
    {
        $validator = new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
        );

        $result = $validator->validateFile(
            dirname(__DIR__, 2) . '/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml'
        );

        $html = (new HtmlReport())->render($result);

        self::assertStringContainsString('Angenommen', $html);
        self::assertStringNotContainsString('>Abgelehnt<', $html);
    }
}
