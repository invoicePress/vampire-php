<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Ruleset;

use EinvoiceValidator\Ruleset\RuleCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.6 — the catalogue is built from the real, shipped rule sets, not from a fixture: its whole
 * purpose is to describe what this installation actually knows.
 */
final class RuleCatalogTest extends TestCase
{
    private static ?RuleCatalog $catalog = null;

    private static function catalog(): RuleCatalog
    {
        if (self::$catalog === null) {
            $files = glob(dirname(__DIR__, 3) . '/rulesets/*@*.json');
            self::assertNotFalse($files);

            // Task 4x.3: the hand-written example is a fixture and no longer sits in rulesets/, but the
            // catalogue has to prove it can take rule sets from anywhere - so it is added explicitly.
            $files[] = dirname(__DIR__, 2) . '/fixtures/rulesets/custom-haus-regeln@1.0.json';
            sort($files);

            self::$catalog = RuleCatalog::fromFiles($files);
        }

        return self::$catalog;
    }

    /**
     * ⚠️ Checked as a SUBSET, not as an exact list: the catalogue here is fed from the shipped rule
     * sets PLUS the hand-written fixture, and a stricter assertion would turn every new rule package
     * into a failing test of the catalogue rather than of itself.
     */
    public function testTheCatalogueCoversEveryOfficialRuleset(): void
    {
        $refs = [];

        foreach (self::catalog()->all() as $entry) {
            $refs[$entry->ruleset] = true;
        }

        foreach (
            [
            'CEN-EN16931-UBL@3.0.21',
            'EN16931-CII@1.3.16',
            'EN16931-UBL@1.3.16',
            'FACTURX-EN16931-CII@1.09.2',
            'FACTURX-EXTENDED-CII@1.09.2',
            'PEPPOL-EN16931-UBL@3.0.21',
            'XRechnung-CII@3.0.2',
            'XRechnung-UBL@3.0.2',
            ] as $expected
        ) {
            self::assertArrayHasKey($expected, $refs);
        }
    }

    public function testTheCatalogueAlsoKnowsHandWrittenRules(): void
    {
        $entries = self::catalog()->byRuleset('custom-haus-regeln@1.0');

        self::assertNotSame([], $entries, 'Eigene Regeln gehören genauso in den Katalog.');
        self::assertStringStartsWith('HAUS-', $entries[0]->id);
    }

    /**
     * ⚠️ The same rule id lives in several rule sets - this is the reason `byId()` returns a list.
     * BR-02 ("Eine Rechnung muss eine Rechnungsnummer enthalten") exists in the CEN sets, in Peppol's
     * own copy and in both Factur-X profiles.
     */
    public function testARuleIdCanExistInSeveralRulesets(): void
    {
        $entries = self::catalog()->byId('BR-02');

        self::assertGreaterThan(1, count($entries), 'BR-02 sollte in mehreren Regelwerken vorkommen.');

        $rulesets = array_map(static fn ($e): string => $e->ruleset, $entries);
        self::assertSame($rulesets, array_unique($rulesets), 'Je Regelwerk höchstens ein Eintrag.');
    }

    public function testAnEntryCarriesTheFixedMessageTextWithoutDocumentValues(): void
    {
        $entries = self::catalog()->byId('BR-02');

        self::assertNotSame('', $entries[0]->text);
        self::assertStringNotContainsString('  ', $entries[0]->text, 'Text ist normalisiert.');
    }

    public function testRulesCanBeFoundByTheBusinessTermTheyMention(): void
    {
        $entries = self::catalog()->byField('BT-1');

        self::assertNotSame([], $entries, 'Zu BT-1 muss es Regeln geben.');

        foreach ($entries as $entry) {
            self::assertContains('BT-1', $entry->fields);
        }
    }

    public function testEveryEntrySerialisesToTheDocumentedShape(): void
    {
        $row = self::catalog()->all()[0]->toArray();

        self::assertSame(
            ['id', 'ruleset', 'rulesetId', 'version', 'pattern', 'kind', 'flag', 'text', 'fields',
                'duplicateKey', 'descriptions'],
            array_keys($row)
        );
    }

    /**
     * Task 5.8d — `UBL-CR-400` is the textbook case: the suite catalogue lists it under six standards,
     * and three of those rule sets are shipped here.
     *
     * ⚠️ Measured first, then asserted. "At least two rule sets" is what this installation can
     * actually show; the six of the catalogue include rule sets (UBL.BE, CIUS-RO, PEPPOL-IT) that are
     * Phase 8 and not shipped, so demanding six would fail on correct behaviour.
     */
    public function testTheSameRuleIsFoundAcrossRulesetsThroughItsDuplicateKey(): void
    {
        $entries = self::catalog()->byId('UBL-CR-400');
        self::assertNotSame([], $entries, 'UBL-CR-400 sollte es geben.');

        $key = null;

        foreach ($entries as $entry) {
            if ($entry->duplicateKey !== null) {
                $key = $entry->duplicateKey;
                break;
            }
        }

        self::assertNotNull($key, 'UBL-CR-400 hat keinen duplicateKey - ist 0.2c gelaufen?');

        $byKey = self::catalog()->byDuplicateKey($key);
        $rulesets = array_unique(array_map(static fn ($e): string => $e->ruleset, $byKey));

        self::assertGreaterThanOrEqual(2, count($rulesets), 'Dieselbe Regel sollte in mehreren Regelwerken stehen.');
        self::assertSame($rulesets, array_unique($rulesets));
    }

    public function testTheRulesetsOfARuleAreFoundByIdAsWell(): void
    {
        $refs = self::catalog()->rulesetsOf('UBL-CR-400');

        self::assertGreaterThanOrEqual(2, count($refs));
        self::assertContains('EN16931-UBL@1.3.16', $refs);
    }

    /**
     * ⚠️ A rule without a `duplicateKey` must not come back empty-handed: the fixture rule set is
     * schemaVersion 1 and has no keys at all, and `rulesetsOf()` still has to name its rule set.
     */
    public function testARuleWithoutADuplicateKeyStillNamesItsOwnRuleset(): void
    {
        $entries = self::catalog()->byRuleset('custom-haus-regeln@1.0');
        self::assertNotSame([], $entries);
        self::assertNull($entries[0]->duplicateKey);

        self::assertSame(['custom-haus-regeln@1.0'], self::catalog()->rulesetsOf($entries[0]->id));
    }
}
