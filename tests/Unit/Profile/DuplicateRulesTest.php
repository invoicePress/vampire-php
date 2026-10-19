<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Profile;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Profile\ProfileLoader;
use EinvoiceValidator\Report\JsonReport;
use EinvoiceValidator\Report\KositXmlReport;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Task 5.8 — the same rule in several rule sets.
 *
 * The setup is a real one, not a contrived pair: the XRechnung scenario runs `EN16931-UBL@1.3.16`,
 * and Peppol ships its **own compiled stand** of the very same CEN core rules as
 * `CEN-EN16931-UBL@3.0.21`. **Measured: 975 of 979 `duplicateKey`s are shared between the two.**
 * A profile that switches the Peppol copy on therefore runs every core rule twice - which is exactly
 * the situation this task is about.
 *
 * ⚠️ The test bed on its own produces NO merged finding: a valid document fires nothing, and the
 * mutated ones run under a scenario with only one of the two rule sets. That is why this test builds
 * the situation through a profile instead of hoping for it - a green test that never reached the
 * merging code would say nothing at all.
 */
final class DuplicateRulesTest extends TestCase
{
    private const MUTATED = '/fixtures/invoices/mutated/ubl-empty-invoice-number.xml';

    private static function baseDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function validator(?string $profileId): Validator
    {
        return new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
            profile: $profileId === null
                ? null
                : (new ProfileLoader(dirname(__DIR__, 2) . '/fixtures/profiles'))->load($profileId),
        );
    }

    private static function invoice(): string
    {
        return dirname(__DIR__, 2) . self::MUTATED;
    }

    /**
     * The baseline this whole test depends on: without the profile, BR-02 fires exactly once, from
     * one rule set. If this ever changes, the assertions below stop meaning what they say.
     */
    public function testWithoutTheProfileTheRuleFiresOnceFromOneRuleset(): void
    {
        $result = self::validator(null)->validateFile(self::invoice());

        $br02 = array_values(array_filter($result->messages(), static fn ($m): bool => $m->ruleId === 'BR-02'));

        self::assertCount(1, $br02, 'BR-02 sollte einmal feuern.');
        self::assertSame(['EN16931-UBL@1.3.16'], $br02[0]->origins());
    }

    /**
     * Task 5.8a — one finding, both origins.
     */
    public function testTheSameRuleFromTwoRulesetsBecomesOneFindingWithTwoOrigins(): void
    {
        $result = self::validator('test-duplicate-merge')->validateFile(self::invoice());

        $br02 = array_values(array_filter($result->messages(), static fn ($m): bool => $m->ruleId === 'BR-02'));

        self::assertCount(1, $br02, 'Dieselbe Regel auf demselben Knoten ist EIN Befund.');
        self::assertSame(
            ['EN16931-UBL@1.3.16', 'CEN-EN16931-UBL@3.0.21'],
            $br02[0]->origins(),
            'Beide Herkünfte, in der Reihenfolge der Regelwerke.'
        );
    }

    /**
     * ⚠️ Merging must not lose the second rule set from the report. The JSON report names both, and
     * the KoSIT-shaped report writes the finding under BOTH validation steps - that format's
     * structure is one message per step, and keeping it is the point of Task 7.3.
     */
    public function testBothReportsStillShowBothRuleSets(): void
    {
        $result = self::validator('test-duplicate-merge')->validateFile(self::invoice());

        $json = (new JsonReport())->toArray($result);
        $finding = null;

        foreach ($json['findings'] as $candidate) {
            if ($candidate['rule'] === 'BR-02') {
                $finding = $candidate;
                break;
            }
        }

        self::assertNotNull($finding);
        self::assertSame(['EN16931-UBL@1.3.16', 'CEN-EN16931-UBL@3.0.21'], $finding['rulesets']);

        $xml = (new KositXmlReport())->render($result);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');

        $nodes = $xpath->query('//rep:message[@code="BR-02"]');
        self::assertNotFalse($nodes);
        self::assertSame(2, $nodes->length, 'Je Prüfschritt eine Meldung - wie im Originalbericht.');
    }

    /**
     * Task 5.8b — switching a rule off means the rule, not one spelling of it in one rule set.
     */
    public function testDisablingARuleSwitchesItOffInEveryRulesetThatCarriesIt(): void
    {
        $result = self::validator('test-duplicate-disable')->validateFile(self::invoice());

        foreach ($result->messages() as $message) {
            self::assertNotSame('BR-02', $message->ruleId, 'BR-02 läuft noch irgendwo.');
        }
    }

    /**
     * Task 5.8c — switching on a rule that is already running is refused, and says so.
     */
    public function testIncludingAnAlreadyRunningRuleIsRefusedWithANotice(): void
    {
        $result = self::validator('test-duplicate-include-refused')->validateFile(self::invoice());

        $notices = array_values(array_filter(
            $result->messages(),
            static fn ($m): bool => $m->ruleset === 'profile'
        ));

        self::assertCount(1, $notices, 'Genau ein Hinweis zum abgelehnten Zuschalten.');
        self::assertSame(Flag::Information, $notices[0]->flag, 'Ein Hinweis, kein Befund.');
        self::assertSame('BR-02', $notices[0]->ruleId);
        self::assertStringContainsString('EN16931-UBL@1.3.16', $notices[0]->text, 'Der Hinweis nennt, wo die Regel schon läuft.');

        // And the rule itself has not disappeared through this - it keeps running in the scenario.
        // ⚠️ The notice carries the same `ruleId`, so the filter must be by rule set here. Without it
        // the notice counts itself, and the test measures something other than it says.
        $br02 = array_values(array_filter(
            $result->messages(),
            static fn ($m): bool => $m->ruleId === 'BR-02' && $m->ruleset !== 'profile'
        ));
        self::assertCount(1, $br02);
        self::assertSame(['EN16931-UBL@1.3.16'], $br02[0]->origins());

        // The refused rule set does not appear as a check step either: it checked nothing.
        self::assertNotContains('CEN-EN16931-UBL@3.0.21', $result->rulesets());
    }
}
