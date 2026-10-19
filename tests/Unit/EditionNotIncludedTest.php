<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Report\HtmlReport;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * A document naming an edition NEWER than any this package carries - XRechnung 4.0, EN 16931-1:2026 - gets
 * no verdict and a sentence naming the edition, never "Kein Szenario passt" and a rejection.
 *
 * ⚠️ THE SIX FILES ARE DERIVED TEST DATA, NOT XRECHNUNG 4.0 INVOICES. Each is a corpus invoice in which only
 * BT-24 was replaced ( a copy of the official example with only BT-24 changed ). They prove that the identifier is
 * recognised and that nothing is checked against 3.0 in its place, nothing about 4.0 itself.
 *
 * ⚠️ THE TWIN IS `test/validator.test.mjs` IN VAMPIRE JS, with the same texts.
 */
final class EditionNotIncludedTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function documents(): iterable
    {
        $cases = [
            ['en16931-2026', 'EN 16931-1:2026', 'urn:cen.eu:en16931:2026'],
            ['en16931-2026-xrechnung40', 'XRechnung 4.0', 'urn:cen.eu:en16931:2026#compliant#urn:xeinkauf.de:kosit:xrechnung_4.0'],
            ['en16931-2017-xrechnung40', 'XRechnung 4.0', 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_4.0'],
        ];

        foreach ($cases as [$suffix, $label, $id]) {
            foreach (['01.01a-INVOICE_ubl', '01.01a-INVOICE_uncefact'] as $base) {
                yield "$base.synthetic-$suffix" => [
                    Corpus::examples() . "/xrechnung-4.0-synthetic/$base.synthetic-$suffix.xml",
                    $label,
                    $id,
                ];
            }
        }
    }

    #[DataProvider('documents')]
    public function testANewerEditionIsNotIncludedNeverRejectedAndNeverCheckedAs30(string $file, string $label, string $id): void
    {
        if (!is_file($file)) {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        $base = dirname(__DIR__, 2);
        $resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');

        $result = (new Validator(scenarioResolver: $resolver, baseDir: $base))->validateFile($file);
        $json = (new JsonReport())->toArray($result);

        self::assertNull($json['accepted'], 'no verdict, never "rejected"');
        self::assertNull($json['scenario'], 'no scenario of 3.0 took the document');
        self::assertSame([], $json['rulesets'], 'no rule set ran');
        self::assertSame([$label], $json['missingRulesets']);
        self::assertSame(['fatal' => 0, 'warning' => 0, 'information' => 1], $json['counts']);
        self::assertSame(
            "Fassung nicht enthalten: Das Dokument nennt in BT-24 die Fassung $label ( $id ). Dieses Paket enthält für diese Fassung noch kein Regelwerk, darum wurde das Dokument nicht geprüft. Es gibt kein Urteil.",
            $json['findings'][0]['text']
        );
        self::assertStringNotContainsString('Abgelehnt', (new HtmlReport())->render($result));
    }

    public function testAnOlderEditionKeepsNoScenario(): void
    {
        $base = dirname(__DIR__, 2);
        $resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');
        $file = Corpus::examples() . '/invoices/XRechnung/01.01a-INVOICE_ubl.xml';

        if (!is_file($file)) {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        $xml = (string) file_get_contents($file);

        // ⚠️ XRechnung 2.3 is out of force since 2024; "no scenario" is what the KoSIT validator says as well.
        $xml = str_replace('urn:xeinkauf.de:kosit:xrechnung_3.0', 'urn:xoev-de:kosit:standard:xrechnung_2.3', $xml);

        $result = (new Validator(scenarioResolver: $resolver, baseDir: $base))->validateString($xml);

        self::assertTrue($result->hasVerdict());
        self::assertFalse($result->isAccepted());
        self::assertSame('Kein Szenario passt auf dieses Dokument.', $result->messages()[0]->text);
    }
}
