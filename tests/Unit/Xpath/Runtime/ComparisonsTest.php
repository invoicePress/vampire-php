<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Runtime;

use EinvoiceValidator\Xpath\Runtime\AtomicValue;
use EinvoiceValidator\Xpath\Runtime\Comparisons;
use EinvoiceValidator\Xpath\Runtime\Decimal;
use EinvoiceValidator\Xpath\Runtime\Sequence;
use PHPUnit\Framework\TestCase;

final class ComparisonsTest extends TestCase
{
    public function testGeneralComparisonIsExistentialOverBothSequences(): void
    {
        $left = Sequence::of([AtomicValue::decimal(Decimal::fromInt(1)), AtomicValue::decimal(Decimal::fromInt(2))]);
        $right = Sequence::of([AtomicValue::decimal(Decimal::fromInt(2)), AtomicValue::decimal(Decimal::fromInt(3))]);

        // (1,2) = (2,3): true because 2 = 2 exists as a pair.
        self::assertTrue(Comparisons::general($left, $right, '='));
        // (1,2) = (3,4): no matching pair.
        $other = Sequence::of([AtomicValue::decimal(Decimal::fromInt(3)), AtomicValue::decimal(Decimal::fromInt(4))]);
        self::assertFalse(Comparisons::general($left, $other, '='));
    }

    public function testEitherOperandEmptyMakesGeneralComparisonFalse(): void
    {
        $nonEmpty = Sequence::singleton(AtomicValue::string('x'));

        self::assertFalse(Comparisons::general(Sequence::empty(), $nonEmpty, '='));
        self::assertFalse(Comparisons::general($nonEmpty, Sequence::empty(), '='));
        self::assertFalse(Comparisons::general(Sequence::empty(), Sequence::empty(), '!='));
    }

    public function testUntypedAtomicComparesNumericallyAgainstANumericOperand(): void
    {
        // A node's text "10" atomized against xs:decimal 9 must compare numerically (10 > 9),
        // not lexically ("10" < "9" as strings).
        $node = Sequence::singleton(AtomicValue::untypedAtomic('10'));
        $nine = Sequence::singleton(AtomicValue::decimal(Decimal::fromInt(9)));

        self::assertTrue(Comparisons::general($node, $nine, '>'));
    }

    public function testUntypedAtomicComparesAsStringAgainstAStringOperand(): void
    {
        $node = Sequence::singleton(AtomicValue::untypedAtomic('10'));
        $string = Sequence::singleton(AtomicValue::string('9'));

        // "10" < "9" lexically.
        self::assertTrue(Comparisons::general($node, $string, '<'));
    }

    public function testValueComparisonOperators(): void
    {
        $one = AtomicValue::decimal(Decimal::fromInt(1));
        $two = AtomicValue::decimal(Decimal::fromInt(2));

        self::assertTrue(Comparisons::value($one, $one, 'eq'));
        self::assertTrue(Comparisons::value($one, $two, 'ne'));
        self::assertTrue(Comparisons::value($one, $two, 'lt'));
        self::assertTrue(Comparisons::value($two, $one, 'gt'));
        self::assertTrue(Comparisons::value($one, $one, 'le'));
        self::assertTrue(Comparisons::value($one, $one, 'ge'));
    }

    public function testDecimalNumericEqualityIgnoresTrailingZeros(): void
    {
        $a = AtomicValue::decimal(Decimal::fromLexical('1.10'));
        $b = AtomicValue::decimal(Decimal::fromLexical('1.1'));

        self::assertTrue(Comparisons::value($a, $b, 'eq'));
    }

    /**
     * ⚠️ Real bug, found via the official XRechnung test suite (BR-CL-20's own rule context is
     * `cac:AllowanceCharge[cbc:ChargeIndicator = true()]`): comparing an untypedAtomic node value
     * against a real xs:boolean must cast via the XML Schema LEXICAL rule ("false"/"0" -> false),
     * not the effective-boolean-value rule ("false" is a non-empty string, so EBV says true) - the
     * two were conflated in one `AtomicValue::asBoolean()` method, so `cbc:ChargeIndicator = true()`
     * wrongly matched a node whose own text was literally "false".
     */
    public function testUntypedAtomicWithLexicalValueFalseComparesFalseAgainstBooleanTrue(): void
    {
        $node = Sequence::singleton(AtomicValue::untypedAtomic('false'));
        $true = Sequence::singleton(AtomicValue::boolean(true));

        self::assertFalse(Comparisons::general($node, $true, '='));
    }

    public function testUntypedAtomicWithLexicalValueTrueComparesTrueAgainstBooleanTrue(): void
    {
        $node = Sequence::singleton(AtomicValue::untypedAtomic('true'));
        $true = Sequence::singleton(AtomicValue::boolean(true));

        self::assertTrue(Comparisons::general($node, $true, '='));
    }

    public function testUntypedAtomicLexicalOnesAndZerosAlsoCompareAsBooleans(): void
    {
        $zero = Sequence::singleton(AtomicValue::untypedAtomic('0'));
        $one = Sequence::singleton(AtomicValue::untypedAtomic('1'));
        $false = Sequence::singleton(AtomicValue::boolean(false));
        $true = Sequence::singleton(AtomicValue::boolean(true));

        self::assertTrue(Comparisons::general($zero, $false, '='));
        self::assertTrue(Comparisons::general($one, $true, '='));
    }
}
