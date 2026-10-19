<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Runtime;

use EinvoiceValidator\Xpath\Runtime\Decimal;
use PHPUnit\Framework\TestCase;

/**
 * Round() cases are the actual worked examples from the W3C spec
 * (https://www.w3.org/TR/2007/REC-xpath-functions-20070123/#func-round), fetched and quoted
 * verbatim in Decimal's own class docblock - not invented, per Regel A.7.
 */
final class DecimalTest extends TestCase
{
    public function testRoundExamplesFromTheSpec(): void
    {
        self::assertSame('3', Decimal::fromLexical('2.5')->round()->toString());
        self::assertSame('2', Decimal::fromLexical('2.4999')->round()->toString());
        self::assertSame('-2', Decimal::fromLexical('-2.5')->round()->toString());
    }

    public function testRoundOnAnIntegerIsUnchanged(): void
    {
        self::assertSame('5', Decimal::fromLexical('5')->round()->toString());
        self::assertSame('0', Decimal::fromLexical('0')->round()->toString());
    }

    public function testRoundHandlesPositiveAndNegativeNonTieCases(): void
    {
        self::assertSame('3', Decimal::fromLexical('2.6')->round()->toString());
        self::assertSame('2', Decimal::fromLexical('2.4')->round()->toString());
        self::assertSame('-3', Decimal::fromLexical('-2.6')->round()->toString());
        self::assertSame('-2', Decimal::fromLexical('-2.4')->round()->toString());
    }

    public function testTheRoundThenScaleBackPatternUsedByTheRealRuleset(): void
    {
        // round(. * 100) div 100 - the actual pattern found in vendor-sources/ (2-decimal amounts).
        $hundred = Decimal::fromInt(100);
        $value = Decimal::fromLexical('12.005');

        $rounded = $value->multiply($hundred)->round()->divide($hundred);

        self::assertSame('12.01', $rounded->toString());
    }

    public function testArithmetic(): void
    {
        $a = Decimal::fromLexical('10.5');
        $b = Decimal::fromLexical('3.2');

        self::assertSame('13.7', $a->add($b)->toString());
        self::assertSame('7.3', $a->subtract($b)->toString());
        self::assertSame('33.6', $a->multiply($b)->toString());
    }

    public function testDivisionByZeroThrows(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        Decimal::fromLexical('1')->divide(Decimal::zero());
    }

    public function testCanonicalizationTrimsTrailingZerosAndLeadingZerosAndSignedZero(): void
    {
        self::assertSame('1.5', Decimal::fromLexical('01.500')->toString());
        self::assertSame('0', Decimal::fromLexical('0.00')->toString());
        self::assertSame('0', Decimal::fromLexical('-0.00')->toString());
        self::assertSame('0.3', Decimal::fromLexical('.3')->toString());
        self::assertSame('-5', Decimal::fromLexical('-5.0')->toString());
    }

    public function testCompareToAndEquals(): void
    {
        $a = Decimal::fromLexical('1.10');
        $b = Decimal::fromLexical('1.1');

        self::assertTrue($a->equals($b));
        self::assertSame(0, $a->compareTo($b));
        self::assertSame(1, Decimal::fromLexical('2')->compareTo(Decimal::fromLexical('1')));
        self::assertSame(-1, Decimal::fromLexical('1')->compareTo(Decimal::fromLexical('2')));
    }

    public function testSumOfEmptySequenceIsZero(): void
    {
        self::assertSame('0', Decimal::sum([])->toString());
    }

    public function testSum(): void
    {
        $values = [Decimal::fromLexical('1.1'), Decimal::fromLexical('2.2'), Decimal::fromLexical('3.3')];

        self::assertSame('6.6', Decimal::sum($values)->toString());
    }

    public function testAbs(): void
    {
        self::assertSame('5', Decimal::abs(Decimal::fromLexical('-5'))->toString());
        self::assertSame('5', Decimal::abs(Decimal::fromLexical('5'))->toString());
        self::assertSame('0', Decimal::abs(Decimal::zero())->toString());
    }

    public function testInvalidLexicalFormThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::fromLexical('1.5e3'); // exponent notation is xs:double, not xs:decimal
    }

    public function testNegateAndIsNegative(): void
    {
        $five = Decimal::fromLexical('5');
        self::assertFalse($five->isNegative());
        self::assertTrue($five->negate()->isNegative());
        self::assertSame('-5', $five->negate()->toString());
        self::assertFalse(Decimal::zero()->negate()->isNegative());
    }
}
