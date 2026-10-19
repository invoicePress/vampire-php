<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Profile;

use EinvoiceValidator\Devkit\Import\CustomRuleCompiler;
use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Profile\ProfileLoader;
use EinvoiceValidator\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tasks 5.5 / 5.5a — hand-written rules, addressed by business term, running in the same engine as the
 * official ones and visibly marked as their own origin in the report.
 */
final class CustomRulesTest extends TestCase
{
    private static function baseDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private static function compiler(): CustomRuleCompiler
    {
        return CustomRuleCompiler::withBindingsFrom(self::baseDir() . '/rulesets/field-bindings.json');
    }

    public function testABusinessTermContextIsResolvedToTheSyntaxPath(): void
    {
        $ruleset = self::compiler()->compile([
            'id' => 'probe',
            'version' => '1.0',
            'syntax' => 'ubl',
            'rules' => [
                ['id' => 'P-1', 'contextBt' => 'BT-1', 'test' => 'true()', 'message' => 'x'],
            ],
        ]);

        $context = json_encode($ruleset->patterns[0]->rules[0]->context->toArray(), JSON_THROW_ON_ERROR);

        // The AST keeps prefix and local name apart, so the path shows up as its parts, not as text.
        self::assertStringContainsString('"prefix":"cbc"', $context);
        self::assertStringContainsString('"local":"ID"', $context);
    }

    /**
     * ⚠️ The same BT resolves to a different path per syntax - that is the whole point of the binding
     * table. If both came out equal, the table would not be doing anything.
     */
    public function testTheSameBusinessTermGivesADifferentPathPerSyntax(): void
    {
        $ubl = self::compiler()->compile([
            'id' => 'probe', 'version' => '1.0', 'syntax' => 'ubl',
            'rules' => [['id' => 'P-1', 'contextBt' => 'BT-1', 'test' => 'true()', 'message' => 'x']],
        ]);

        $cii = self::compiler()->compile([
            'id' => 'probe', 'version' => '1.0', 'syntax' => 'cii',
            'rules' => [['id' => 'P-1', 'contextBt' => 'BT-1', 'test' => 'true()', 'message' => 'x']],
        ]);

        self::assertNotSame(
            json_encode($ubl->patterns[0]->rules[0]->context->toArray(), JSON_THROW_ON_ERROR),
            json_encode($cii->patterns[0]->rules[0]->context->toArray(), JSON_THROW_ON_ERROR),
        );
    }

    public function testAnUnknownBusinessTermIsRefused(): void
    {
        $this->expectException(ImportException::class);

        self::compiler()->compile([
            'id' => 'probe', 'version' => '1.0', 'syntax' => 'ubl',
            'rules' => [['id' => 'P-1', 'contextBt' => 'BT-9999', 'test' => 'true()', 'message' => 'x']],
        ]);
    }

    public function testARulesetWithoutRulesIsRefused(): void
    {
        $this->expectException(ImportException::class);

        self::compiler()->compile(['id' => 'leer', 'version' => '1.0', 'syntax' => 'ubl', 'rules' => []]);
    }

    /**
     * End to end: the compiled rule set, switched on through a profile, produces findings on a real
     * invoice - and the report says where they came from.
     *
     * ⚠️ The house rule is deliberately stricter than the document (invoice number "123456XX", eight
     * characters, against a twelve-character house rule). A first version asked for eight and fired on
     * nothing: a valid document produces no findings, so "no message from this rule set" proves
     * neither that it ran nor that it did not. The fixture was measured, then the rule was set.
     */
    public function testCustomRulesRunAndAreMarkedWithTheirOwnOrigin(): void
    {
        $validator = new Validator(
            scenarioResolver: ScenarioResolver::fromJsonFile(self::baseDir() . '/rulesets/scenarios.json'),
            baseDir: self::baseDir(),
            profile: (new ProfileLoader(dirname(__DIR__, 2) . '/fixtures/profiles'))->load('test-custom-rules'),
            // Task 4x.3: the house rules are a fixture, not a shipped rule set - so they live under
            // tests/fixtures/rulesets/ and are found through the extra search path, not through the
            // package's own rulesets/ directory.
            rulesetDirs: [dirname(__DIR__, 2) . '/fixtures/rulesets'],
        );

        $result = $validator->validateFile(
            dirname(__DIR__, 2) . '/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml'
        );

        $own = [];

        foreach ($result->messages() as $message) {
            if ($message->ruleset === 'custom-haus-regeln@1.0') {
                $own[$message->ruleId] = $message->flag->value;
            }
        }

        self::assertNotSame([], $own, 'Die eigenen Regeln liefen nicht.');

        foreach (array_keys($own) as $ruleId) {
            self::assertStringStartsWith('HAUS-', (string) $ruleId);
        }
    }
}
