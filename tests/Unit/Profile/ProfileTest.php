<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Profile;

use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Profile\ProfileLoader;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 — proves a profile changes the outcome, which is this phase's own acceptance criterion.
 *
 * The document under test is the mutated invoice from Task 4.2 whose invoice number is empty: without
 * a profile BR-02 fires as fatal and the invoice is rejected. That makes every step below visible in
 * the result rather than only in the profile object.
 */
final class ProfileTest extends TestCase
{
    private const MUTATED_INVOICE = 'invoices/mutated/ubl-empty-invoice-number.xml';

    private static function baseDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function loader(): ProfileLoader
    {
        return new ProfileLoader(dirname(__DIR__, 2) . '/fixtures/profiles');
    }

    private function validator(?string $profileId): Validator
    {
        return new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
            profile: $profileId === null ? null : self::loader()->load($profileId),
        );
    }

    /**
     * @return array<string, string>
     */
    private function findings(?string $profileId): array
    {
        $result = $this->validator($profileId)->validateFile(dirname(__DIR__, 2) . '/fixtures/' . self::MUTATED_INVOICE);
        $byRule = [];

        foreach ($result->messages() as $message) {
            if ($message->ruleId !== null) {
                $byRule[$message->ruleId] = $message->flag->value;
            }
        }

        $byRule['__accepted'] = $result->isAccepted() ? 'yes' : 'no';

        return $byRule;
    }

    /**
     * ⚠️ The mutated invoice fails on TWO rules, not one: BR-02 (EN16931-UBL) and PEPPOL-EN16931-R008,
     * which XRechnung carries in its own rule set. Downgrading only BR-02 and then asserting acceptance
     * leaves the invoice rejected, and the profile looks broken while it is doing exactly what it was
     * told. Measured before fixing the expectation, not after.
     */
    public function testWithoutAProfileTheRuleIsFatalAndTheInvoiceIsRejected(): void
    {
        $findings = $this->findings(null);

        self::assertSame('fatal', $findings['BR-02'] ?? null);
        self::assertSame('fatal', $findings['PEPPOL-EN16931-R008'] ?? null);
        self::assertSame('no', $findings['__accepted']);
    }

    public function testDowngradingTheRuleMakesTheSameInvoiceAcceptable(): void
    {
        $findings = $this->findings('test-downgrade');

        self::assertSame('information', $findings['BR-02'] ?? null, 'Die Regel muss weiterhin melden, nur nicht mehr sperren.');
        self::assertSame('yes', $findings['__accepted']);
    }

    public function testDisablingTheRuleRemovesItFromTheReportEntirely(): void
    {
        $findings = $this->findings('test-disable');

        self::assertArrayNotHasKey('BR-02', $findings, 'Eine abgeschaltete Regel darf gar nicht erst auftauchen.');
        // The inherited downgrade still applies to the other rule, so the invoice is accepted.
        self::assertSame('information', $findings['PEPPOL-EN16931-R008'] ?? null);
        self::assertSame('yes', $findings['__accepted']);
    }

    /**
     * `extends` plus own entries: the inherited severity is in the merged profile, and the own
     * `disable` wins over it - which is exactly the order docs/developer/profile-format.md promises.
     */
    public function testExtendsMergesTheParentAndTheChildWins(): void
    {
        $profile = self::loader()->load('test-disable');

        self::assertSame(Flag::Information, $profile->severityFor('BR-02'), 'Die geerbte Einstufung fehlt.');
        self::assertTrue($profile->isDisabled('BR-02'), 'Das eigene disable muss gelten.');
    }

    public function testASwitchedOnRulesetOfTheSameSyntaxIsRun(): void
    {
        $result = $this->validator('test-include-peppol')->validateFile(
            dirname(__DIR__, 2) . '/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml'
        );

        $fromPeppol = [];

        foreach ($result->messages() as $message) {
            if ($message->ruleset === 'PEPPOL-EN16931-UBL@3.0.21') {
                $fromPeppol[] = $message->ruleId;
            }
        }

        self::assertSame(['PEPPOL-EN16931-R004'], $fromPeppol, 'Genau die zugeschaltete Regel muss kommen - und nur sie.');
    }

    public function testSwitchingOnARulesetOfTheWrongSyntaxIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/CII|UBL/');

        $this->validator('test-include-cii')->validateFile(
            dirname(__DIR__, 2) . '/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml'
        );
    }

    public function testACycleInExtendsIsAnError(): void
    {
        $dir = sys_get_temp_dir() . '/einvoice-profile-cycle-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/a.json", '{"id":"a","extends":"b"}');
        file_put_contents("$dir/b.json", '{"id":"b","extends":"a"}');

        try {
            $this->expectException(ImportException::class);
            (new ProfileLoader($dir))->load('a');
        } finally {
            @unlink("$dir/a.json");
            @unlink("$dir/b.json");
            @rmdir($dir);
        }
    }
}
