<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit;

use EinvoiceValidator\Cascade\Cascade;
use EinvoiceValidator\Cascade\CascadeResult;
use PHPUnit\Framework\TestCase;
use EinvoiceValidator\Tests\Support\Corpus;

/**
 * The version cascade, `Cascade` and `CascadeResult`.
 *
 * ⚠️ THE FULL MEASUREMENT IS NOT HERE. The 50 invoices of the matrix of 24.09.2026 take about three minutes in PHP;
 * `devkit/Cli/cascade-findings.php --matrix` runs them. These tests pin the decisions that are easy to break by
 * accident: the choice by validity, the stop at the first accepting edition, the stricter newer edition, the texts.
 */
final class CascadeTest extends TestCase
{
    private const XR = [
        ['version' => '3.0.2', 'validFrom' => '2024-06-20', 'validUntil' => null, 'carried' => true],
        ['version' => '3.0.1', 'validFrom' => '2024-02-01', 'validUntil' => '2024-06-19', 'carried' => false],
        ['version' => '3.0.0', 'validFrom' => '2023-08-01', 'validUntil' => '2024-01-31', 'carried' => false],
    ];

    private static function base(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function corpusFile(string $prefix): string
    {
        $hits = glob(Corpus::examples() . '/invoices/ZUGFeRD/*/*/' . $prefix . '*.xml') ?: [];

        return $hits[0] ?? '';
    }

    private static function cascade(): Cascade
    {
        $c = Cascade::fromPackage(self::base(), ['lang' => 'de']);

        if ($c === null) {
            self::markTestSkipped('rulesets/versions/cascade.json fehlt - import-zugferd-versions.php --apply.');
        }

        return $c;
    }

    public function testValidityPicksTheCarriedEditionOfTheDate(): void
    {
        $carried = [self::XR[0]];

        [$v, $code, $valid] = Cascade::chooseByValidity(self::XR, $carried, '2025-03-01');
        self::assertSame(['3.0.2', 'validity', '3.0.2'], [$v['version'], $code, $valid]);

        // ⚠️ The date picks 3.0.1; its package is not carried, so 3.0.2 runs and the result says which edition the date picked.
        [$v, $code, $valid] = Cascade::chooseByValidity(self::XR, $carried, '2024-03-15');
        self::assertSame(['3.0.2', 'validity-not-carried', '3.0.1'], [$v['version'], $code, $valid]);

        [$v, $code] = Cascade::chooseByValidity(self::XR, $carried, '2019-01-01');
        self::assertSame(['3.0.2', 'outside-validity'], [$v['version'], $code]);

        [$v, $code] = Cascade::chooseByValidity(self::XR, $carried, null);
        self::assertSame(['3.0.2', 'no-date'], [$v['version'], $code]);
    }

    public function testTheBoundaryDaysBelongToTheirEdition(): void
    {
        $all = array_map(static fn (array $v): array => ['carried' => true] + $v, self::XR);

        self::assertSame('3.0.1', Cascade::chooseByValidity($all, $all, '2024-06-19')[0]['version']);
        self::assertSame('3.0.2', Cascade::chooseByValidity($all, $all, '2024-06-20')[0]['version']);
    }

    public function testTheCascadeStopsAtTheFirstAcceptingEdition(): void
    {
        $file = self::corpusFile('X18_01');
        if ($file === '') {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        $r = self::cascade()->validateFile($file, false);

        self::assertSame('zugferd', $r['family']['id']);
        self::assertSame('2.5.2', $r['chosen']['version']);
        self::assertSame('first-accepting', $r['chosen']['reasonCode']);
        self::assertSame(['not-run'], array_values(array_unique(array_map(static fn (array $s): string => $s['verdict'], array_slice($r['steps'], 1)))));
    }

    public function testTheMatrixShowsTheStricterNewerEdition(): void
    {
        // X10_01: 2.5 and 2.5.2 accept with a warning, 2.4 and older without ( KoSIT, 24.09.2026 ).
        $file = self::corpusFile('X10_01');
        if ($file === '') {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        $r = self::cascade()->validateFile($file, true);
        $verdicts = array_column($r['steps'], 'verdict', 'version');

        self::assertSame('warnings', $verdicts['2.5.2']);
        self::assertSame('warnings', $verdicts['2.5']);
        self::assertSame('accepted', $verdicts['2.4']);
        self::assertSame('matrix', $r['mode']);
        self::assertContains('BR-FXEXT-E-08rev', $r['steps'][0]['differs']['fired']);
    }

    public function testAnOldEditionRejectsWithASchemaError(): void
    {
        $file = self::corpusFile('X12_01');
        if ($file === '') {
            self::markTestSkipped('VAMPIRE_EXAMPLES ist nicht gesetzt.');
        }

        $r = self::cascade()->validateFile($file, true);
        $last = end($r['steps']);

        self::assertSame('2.1', $last['version']);
        self::assertSame('rejected', $last['verdict']);
        self::assertTrue($last['schemaFailed']);
        self::assertSame(['XSD'], $last['rules']['fatal']);
    }

    public function testAFormatWithoutEditionsIsASingleCheck(): void
    {
        $hits = glob(Corpus::examples() . '/invoices/UBL/base-example.xml') ?: [];

        if ($hits === []) {
            self::markTestSkipped('UBL/base-example.xml fehlt im Korpus.');
        }

        $r = self::cascade()->validateFile($hits[0], false);

        self::assertNull($r['family']);
        self::assertSame('no-family', $r['chosen']['reasonCode']);
        self::assertCount(1, $r['steps']);
    }

    public function testTheTextKeyFoldsOnlyAsciiSpaceAndNbsp(): void
    {
        self::assertSame('"a b"', CascadeResult::textKey(" a \t\n\u{00A0} b "));
        self::assertSame(102, mb_strlen(CascadeResult::textKey(str_repeat('ä', 150))));
    }

    public function testTheReasonsExistInBothLanguages(): void
    {
        $c = ['version' => '2.4', 'valid' => null, 'date' => '2025-01-01', 'newer' => ['2.5.2', '2.5']];

        self::assertSame('Die neueren Fassungen 2.5.2, 2.5 nehmen die Rechnung nicht an; 2.4 ist die neueste Fassung, die sie annimmt.', CascadeResult::reason('first-accepting', 'de', $c));
        self::assertSame('The newer editions 2.5.2, 2.5 do not accept the invoice; 2.4 is the newest edition that accepts it.', CascadeResult::reason('first-accepting', 'en', $c));
    }
}
