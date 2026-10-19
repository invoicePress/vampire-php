<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Export;

use EinvoiceValidator\Devkit\Export\SchematronWriter;
use EinvoiceValidator\Devkit\Export\XsltWriter;
use EinvoiceValidator\Devkit\Import\SchematronXsltImporter;
use EinvoiceValidator\Ruleset\Ruleset;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 6.1 / 6.2 — the exporters.
 *
 * ⚠️ The strongest check available without a Java toolchain: export a rule set as XSLT, then IMPORT
 * THAT FILE AGAIN with this project's own importer and compare rule ids, severities, kinds and the
 * number of rules. A stylesheet that merely looks plausible passes no such test - a dropped `flag`,
 * a lost rule or a mangled context all show up here.
 *
 * What this cannot prove is that a third-party XSLT 2.0 processor produces the same findings; that is
 * Task 6.5, and its licence problem is written down in docs/developer/licenses.md rather than solved by pulling
 * in a proprietary processor.
 */
final class RulesetExportTest extends TestCase
{
    private static function ruleset(): Ruleset
    {
        return RulesetJsonCodec::readFile(dirname(__DIR__, 3) . '/rulesets/XRechnung-UBL@3.0.2.json');
    }

    public function testTheSchematronExportIsWellFormedAndCarriesTheRules(): void
    {
        $sch = (new SchematronWriter())->write(self::ruleset());

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($sch), 'Der Schematron-Export ist nicht wohlgeformt.');

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('sch', 'http://purl.oclc.org/dsdl/schematron');

        self::assertSame('xslt2', $dom->documentElement?->getAttribute('queryBinding'));

        $asserts = $xpath->query('//sch:assert | //sch:report');
        self::assertNotFalse($asserts);
        self::assertSame(76, $asserts->length, 'XRechnung-UBL@3.0.2 hat 76 Assertions.');

        $namespaces = $xpath->query('//sch:ns');
        self::assertNotFalse($namespaces);
        self::assertGreaterThan(0, $namespaces->length, 'Ohne ns-Deklarationen sind die Pfade wertlos.');
    }

    public function testTheXsltExportIsWellFormedXsltTwo(): void
    {
        $xslt = (new XsltWriter())->write(self::ruleset());

        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xslt), 'Der XSLT-Export ist nicht wohlgeformt.');
        self::assertSame('2.0', $dom->documentElement?->getAttribute('version'));
    }

    /**
     * Export, then import again with our own importer: same rules, same severities, same kinds.
     */
    public function testTheExportedXsltCanBeImportedAgainWithoutLoss(): void
    {
        $original = self::ruleset();
        $file = tempnam(sys_get_temp_dir(), 'einvoice-xslt-') . '.xslt';
        file_put_contents($file, (new XsltWriter())->write($original));

        try {
            $reimported = SchematronXsltImporter::import($file, $original->id, $original->version);
        } finally {
            @unlink($file);
        }

        self::assertSame(
            self::assertionIndex($original),
            self::assertionIndex($reimported),
            'Der Export verliert oder verändert Regeln.'
        );
    }

    /**
     * @return array<string, string> rule id => "kind/flag"
     */
    private static function assertionIndex(Ruleset $ruleset): array
    {
        $index = [];

        foreach ($ruleset->patterns as $pattern) {
            foreach ($pattern->rules as $rule) {
                foreach ($rule->assertions as $assertion) {
                    if ($assertion->id !== null) {
                        $index[$assertion->id] = $assertion->kind . '/' . $assertion->flag->value;
                    }
                }
            }
        }

        ksort($index);

        return $index;
    }
}
