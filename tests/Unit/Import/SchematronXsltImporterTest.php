<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Import;

use EinvoiceValidator\Devkit\Import\SchematronXsltImporter;
use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\Ruleset;
use EinvoiceValidator\Xpath\Ast\BinaryOp;
use EinvoiceValidator\Xpath\Ast\Expr;
use EinvoiceValidator\Xpath\Ast\PathExpr;
use PHPUnit\Framework\TestCase;

/**
 * Imports the REAL, official compiled EN16931/XRechnung rulesets (vendor-sources/, same fixtures
 * InterpreterRealRuleTest and ParserInventoryTest already rely on) - not a synthetic XSLT, so this
 * proves the importer against genuine production structure, not just an idealized shape.
 */
final class SchematronXsltImporterTest extends TestCase
{
    private function vendorRoot(): string
    {
        return dirname(__DIR__, 3) . '/vendor-sources';
    }

    protected function setUp(): void
    {
        // ⚠️ The official sources are not part of the repository ( see .gitignore ); without them there is nothing to import.
        if (!is_dir($this->vendorRoot())) {
            self::markTestSkipped('vendor-sources/ fehlt; die Importtests brauchen die amtlichen Quellen.');
        }
    }

    private function xrechnungUbl(): Ruleset
    {
        static $cached = null;
        $cached ??= SchematronXsltImporter::import(
            $this->vendorRoot() . '/xrechnung-schematron/3.0.2/XRechnung-UBL-validation.xslt',
            'XRechnung-UBL',
            '3.0.2'
        );

        return $cached;
    }

    /**
     * @return array{0: \EinvoiceValidator\Ruleset\Pattern, 1: \EinvoiceValidator\Ruleset\Rule, 2: Assertion}
     */
    private function findAssertion(Ruleset $ruleset, string $id): array
    {
        foreach ($ruleset->patterns as $pattern) {
            foreach ($pattern->rules as $rule) {
                foreach ($rule->assertions as $assertion) {
                    if ($assertion->id === $id) {
                        return [$pattern, $rule, $assertion];
                    }
                }
            }
        }

        self::fail("assertion \"$id\" not found in imported ruleset");
    }

    public function testImportsEn16931UblWithMeasuredCounts(): void
    {
        $ruleset = SchematronXsltImporter::import(
            $this->vendorRoot() . '/en16931-schematron/1.3.16/ubl/EN16931-UBL-validation.xslt',
            'EN16931-UBL',
            '1.3.16'
        );

        self::assertSame('EN16931-UBL', $ruleset->id);
        self::assertSame(['UBL-model', 'UBL-syntax', 'Codesmodel'], array_map(
            static fn ($p) => $p->id,
            $ruleset->patterns
        ));

        $ruleCount = 0;
        $assertionCount = 0;
        foreach ($ruleset->patterns as $pattern) {
            $ruleCount += count($pattern->rules);
            foreach ($pattern->rules as $rule) {
                $assertionCount += count($rule->assertions);
            }
        }
        // Measured once via a throwaway harness against the real file (docs/notes/decisions.md);
        // pinned here as a regression guard, not a guess.
        self::assertSame(104, $ruleCount);
        self::assertSame(979, $assertionCount);
    }

    public function testImportsXRechnungUblWithMeasuredCounts(): void
    {
        $ruleset = $this->xrechnungUbl();

        self::assertSame(
            ['variable-pattern', 'peppol-ubl-pattern-1', 'peppol-ubl-pattern-2', 'ubl-pattern', 'ubl-extension-pattern', 'ubl-cvd-pattern'],
            array_map(static fn ($p) => $p->id, $ruleset->patterns)
        );
        self::assertSame(32, count($ruleset->globalLets));

        // "variable-pattern" is the standard Schematron idiom for a pattern that holds only
        // shared `let` declarations and fires no rule of its own - measured, not assumed.
        self::assertSame('variable-pattern', $ruleset->patterns[0]->id);
        self::assertSame([], $ruleset->patterns[0]->rules);
    }

    public function testBrDe1HasTheVerbatimMessageAndCorrectFlagAndFieldRefs(): void
    {
        [, , $assertion] = $this->findAssertion($this->xrechnungUbl(), 'BR-DE-1');

        self::assertSame(Flag::Fatal, $assertion->flag);
        self::assertSame('assert', $assertion->kind);
        self::assertSame(['BG-16'], $assertion->fieldRefs);
        self::assertCount(1, $assertion->message);
        self::assertTrue($assertion->message[0]->isLiteral());
        self::assertSame(
            '[BR-DE-1] Eine Rechnung (INVOICE) muss Angaben zu "PAYMENT INSTRUCTIONS" (BG-16) enthalten.',
            $assertion->message[0]->literal
        );
    }

    /**
     * BR-DE-18's message interleaves literal text with `<xsl:value-of>` (name(.), then the
     * $XR-SKONTO-REGEX global let), so the text can only be resolved when the rule fires - measured to
     * actually occur, not hypothetical.
     */
    public function testBrDe18MessageHasDynamicSegments(): void
    {
        [, $rule, $assertion] = $this->findAssertion($this->xrechnungUbl(), 'BR-DE-18');

        $kinds = array_map(
            static fn ($m) => $m->isLiteral() ? 'literal' : $m->expr?->node(),
            $assertion->message
        );
        self::assertSame(['literal', 'FunctionCall', 'literal', 'VarRef', 'literal'], $kinds);

        // its rule-let scope must be resolvable: the VarRef is a GLOBAL (XR-SKONTO-REGEX), so it
        // is not expected among the RULE's own lets, only among the ruleset's globalLets.
        $globalNames = array_map(static fn ($l) => $l->name, $this->xrechnungUbl()->globalLets);
        self::assertContains('XR-SKONTO-REGEX', $globalNames);
        self::assertNotContains('XR-SKONTO-REGEX', array_map(static fn ($l) => $l->name, $rule->lets));
    }

    /**
     * Every leaf PathExpr inside a (possibly `|`-unioned) rule context must end up absolute after
     * import, so `Engine` (Aufgabe 3.4) can evaluate it directly against the document root.
     */
    private function assertEveryPathIsAbsolute(Expr $expr): void
    {
        if ($expr instanceof BinaryOp && $expr->op === '|') {
            $this->assertEveryPathIsAbsolute($expr->left);
            $this->assertEveryPathIsAbsolute($expr->right);

            return;
        }
        self::assertInstanceOf(PathExpr::class, $expr);
        self::assertTrue($expr->absolute);
    }

    public function testUnionRuleContextIsNormalizedToAbsolutePathsOnBothSides(): void
    {
        // BR-DE-1's raw context is "/ubl:Invoice | /cn:CreditNote" - already absolute on both
        // sides in the source, so this proves normalization does not BREAK an already-absolute
        // union (the more interesting "was relative, got rewritten" case is covered below).
        [, $rule] = $this->findAssertion($this->xrechnungUbl(), 'BR-DE-1');

        $this->assertEveryPathIsAbsolute($rule->context);
    }

    public function testRelativeRuleContextIsRewrittenToAnAbsolutePath(): void
    {
        // BR-52 (EN16931-UBL-validation.xslt): raw context is the plain relative
        // "cac:AdditionalDocumentReference", with no leading "/" or "//" in the source.
        $ruleset = SchematronXsltImporter::import(
            $this->vendorRoot() . '/en16931-schematron/1.3.16/ubl/EN16931-UBL-validation.xslt',
            'EN16931-UBL',
            '1.3.16'
        );
        [, $rule] = $this->findAssertion($ruleset, 'BR-52');

        self::assertInstanceOf(PathExpr::class, $rule->context);
        self::assertTrue($rule->context->absolute);
        self::assertSame('descendant-or-self', $rule->context->steps[0]->axis);
    }

    public function testGlobalLetsPreserveDependencyOrder(): void
    {
        $names = array_map(static fn ($l) => $l->name, $this->xrechnungUbl()->globalLets);

        $versionIndex = array_search('XR-MAJOR-MINOR-VERSION', $names, true);
        $ciusIdIndex = array_search('XR-CIUS-ID', $names, true);

        self::assertIsInt($versionIndex);
        self::assertIsInt($ciusIdIndex);
        self::assertLessThan($ciusIdIndex, $versionIndex, 'XR-CIUS-ID references XR-MAJOR-MINOR-VERSION, so it must be bound first');
    }

    public function testRulesWithinAPatternAreSortedByStrictlyDescendingPriority(): void
    {
        foreach ($this->xrechnungUbl()->patterns as $pattern) {
            $priorities = array_map(static fn ($r) => $r->priority, $pattern->rules);
            $sorted = $priorities;
            rsort($sorted);
            self::assertSame($sorted, $priorities, "pattern {$pattern->id} rules are not priority-sorted");
            self::assertSame($priorities, array_unique($priorities), "pattern {$pattern->id} has duplicate priorities");
        }
    }

    public function testImportingAMalformedFileThrowsAClearImportException(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-a-schematron-') . '.xslt';
        file_put_contents($path, '<xsl:stylesheet xmlns:xsl="http://www.w3.org/1999/XSL/Transform" version="2.0"/>');

        try {
            $this->expectException(ImportException::class);
            SchematronXsltImporter::import($path, 'bogus', '0');
        } finally {
            unlink($path);
        }
    }
}
