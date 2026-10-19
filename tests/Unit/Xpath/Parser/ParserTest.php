<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Parser;

use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Xpath\Ast\BinaryOp;
use EinvoiceValidator\Xpath\Ast\FunctionCall;
use EinvoiceValidator\Xpath\Ast\IfExpr;
use EinvoiceValidator\Xpath\Ast\PathExpr;
use EinvoiceValidator\Xpath\Ast\QuantifiedExpr;
use PHPUnit\Framework\TestCase;

/**
 * Structural checks that a real expression parses into the SHAPE it should have, not merely
 * "does not throw" (already covered exhaustively by ParserInventoryTest against all 1956 real
 * expressions).
 */
final class ParserTest extends TestCase
{
    public function testSimpleAbbreviatedPath(): void
    {
        $expr = Parser::parse('cac:Foo/cbc:Bar');

        self::assertInstanceOf(PathExpr::class, $expr);
        self::assertFalse($expr->absolute);
        self::assertCount(2, $expr->steps);
        self::assertSame('child', $expr->steps[0]->axis);
        self::assertSame('cac', $expr->steps[0]->test?->name?->prefix);
        self::assertSame('Foo', $expr->steps[0]->test?->name?->local);
    }

    public function testAbsolutePathAndDescendantShorthand(): void
    {
        $expr = Parser::parse('//cac:Foo');
        self::assertInstanceOf(PathExpr::class, $expr);
        self::assertTrue($expr->absolute);
        self::assertSame('descendant-or-self', $expr->steps[0]->axis);
        self::assertSame('child', $expr->steps[1]->axis);
    }

    /**
     * ⚠️ The axis name contains hyphens. The lexer must read `ancestor-or-self` as ONE name followed
     * by `::`, not as `ancestor - or - self` ( a subtraction ), and the predicate must stay on the
     * axis step, because a positional predicate there counts in reverse order.
     */
    public function testHyphenatedReverseAxisWithAPositionalPredicate(): void
    {
        $expr = Parser::parse('ancestor-or-self::sig:UBLDocumentSignatures[1]');

        self::assertInstanceOf(PathExpr::class, $expr);
        self::assertCount(1, $expr->steps);
        self::assertSame('ancestor-or-self', $expr->steps[0]->axis);
        self::assertSame('sig', $expr->steps[0]->test?->name?->prefix);
        self::assertSame('UBLDocumentSignatures', $expr->steps[0]->test?->name?->local);
        self::assertCount(1, $expr->steps[0]->predicates);
    }

    /**
     * ⚠️ `element()` and `attribute()` are kind tests, but only with the parentheses. Read as function
     * calls they failed at run time as unknown functions; read without the parentheses check, an
     * invoice element named `element` would turn into a kind test.
     */
    public function testElementAndAttributeAreKindTestsOnlyWithParentheses(): void
    {
        $expr = Parser::parse('cac:Foo/element()');
        self::assertInstanceOf(PathExpr::class, $expr);
        self::assertSame('child', $expr->steps[1]->axis);
        self::assertSame('kindtest', $expr->steps[1]->test?->kind);
        self::assertSame('element', $expr->steps[1]->test?->kindtestName);

        $attr = Parser::parse('@attribute()');
        self::assertInstanceOf(PathExpr::class, $attr);
        self::assertSame('attribute', $attr->steps[0]->axis);
        self::assertSame('attribute', $attr->steps[0]->test?->kindtestName);

        $name = Parser::parse('element');
        self::assertInstanceOf(PathExpr::class, $name);
        self::assertSame('name', $name->steps[0]->test?->kind);
        self::assertSame('element', $name->steps[0]->test?->name?->local);
    }

    public function testPrefixedWildcard(): void
    {
        $expr = Parser::parse('//ram:*');

        self::assertInstanceOf(PathExpr::class, $expr);
        $lastStep = $expr->steps[array_key_last($expr->steps)];
        self::assertSame('wildcard', $lastStep->test?->kind);
        self::assertSame('ram', $lastStep->test?->name?->prefix);
    }

    public function testFunctionCallAsANonFirstPathStep(): void
    {
        // Real expression from the corpus: cac:TaxScheme/normalize-space(upper-case(cbc:ID))='VAT'
        $expr = Parser::parse("cac:TaxScheme/normalize-space(upper-case(cbc:ID))='VAT'");

        self::assertInstanceOf(BinaryOp::class, $expr);
        self::assertSame('=', $expr->op);
        self::assertInstanceOf(PathExpr::class, $expr->left);
        self::assertCount(2, $expr->left->steps);
        self::assertSame('child', $expr->left->steps[0]->axis);
        self::assertNull($expr->left->steps[1]->axis);
        self::assertInstanceOf(FunctionCall::class, $expr->left->steps[1]->primary);
        self::assertSame('normalize-space', $expr->left->steps[1]->primary->name->local);
    }

    public function testPredicate(): void
    {
        $expr = Parser::parse("cac:Foo[cbc:Bar = '1']");

        self::assertInstanceOf(PathExpr::class, $expr);
        self::assertCount(1, $expr->steps[0]->predicates);
        self::assertInstanceOf(BinaryOp::class, $expr->steps[0]->predicates[0]);
    }

    public function testIfExpr(): void
    {
        $expr = Parser::parse('if (cbc:Foo) then 1 else 0');

        self::assertInstanceOf(IfExpr::class, $expr);
    }

    public function testQuantifiedExpr(): void
    {
        $expr = Parser::parse('every $x in cac:Foo satisfies $x = 1');

        self::assertInstanceOf(QuantifiedExpr::class, $expr);
        self::assertSame('every', $expr->quantifier);
        self::assertSame('x', $expr->var);
    }

    public function testOperatorPrecedenceAdditiveBeforeComparison(): void
    {
        // 1 + 2 = 3 must parse as (1 + 2) = 3, not 1 + (2 = 3).
        $expr = Parser::parse('1 + 2 = 3');

        self::assertInstanceOf(BinaryOp::class, $expr);
        self::assertSame('=', $expr->op);
        self::assertInstanceOf(BinaryOp::class, $expr->left);
        self::assertSame('+', $expr->left->op);
    }

    public function testOperatorPrecedenceMultiplicativeBeforeAdditive(): void
    {
        // 1 + 2 * 3 must parse as 1 + (2 * 3).
        $expr = Parser::parse('1 + 2 * 3');

        self::assertInstanceOf(BinaryOp::class, $expr);
        self::assertSame('+', $expr->op);
        self::assertInstanceOf(BinaryOp::class, $expr->right);
        self::assertSame('*', $expr->right->op);
    }

    public function testDoubledQuoteEscapingInStringLiteral(): void
    {
        $expr = Parser::parse("'it''s'");

        self::assertInstanceOf(\EinvoiceValidator\Xpath\Ast\Literal::class, $expr);
        self::assertSame("it's", $expr->value);
    }

    public function testInstanceOfIsRejectedAsUnsupported(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        Parser::parse('$x instance of xs:integer');
    }

    public function testTreatAsIsRejectedAsUnsupported(): void
    {
        $this->expectException(UnsupportedConstructException::class);
        Parser::parse('$x treat as xs:integer');
    }
}
