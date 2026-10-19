<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Tests\Unit\Xpath\Runtime;

use EinvoiceValidator\Xpath\Runtime\AtomicValue;
use EinvoiceValidator\Xpath\Runtime\Decimal;
use EinvoiceValidator\Xpath\Runtime\Sequence;
use PHPUnit\Framework\TestCase;

/**
 * Effective Boolean Value rules verified against the spec (2.4.3), quoted in
 * docs/notes/decisions.md - not invented.
 */
final class SequenceTest extends TestCase
{
    public function testEmptySequenceEbvIsFalse(): void
    {
        self::assertFalse(Sequence::empty()->effectiveBooleanValue());
    }

    public function testSequenceStartingWithANodeIsAlwaysTrue(): void
    {
        $dom = new \DOMDocument();
        $node = $dom->createElement('x');

        self::assertTrue(Sequence::singleton($node)->effectiveBooleanValue());
    }

    public function testSingletonBooleanEbvIsItself(): void
    {
        self::assertTrue(Sequence::singleton(AtomicValue::boolean(true))->effectiveBooleanValue());
        self::assertFalse(Sequence::singleton(AtomicValue::boolean(false))->effectiveBooleanValue());
    }

    public function testSingletonStringEbvIsNonEmptyCheck(): void
    {
        self::assertFalse(Sequence::singleton(AtomicValue::string(''))->effectiveBooleanValue());
        self::assertTrue(Sequence::singleton(AtomicValue::string('x'))->effectiveBooleanValue());
    }

    public function testSingletonNumericEbvIsNonZeroCheck(): void
    {
        self::assertFalse(Sequence::singleton(AtomicValue::decimal(Decimal::zero()))->effectiveBooleanValue());
        self::assertTrue(Sequence::singleton(AtomicValue::decimal(Decimal::fromInt(1)))->effectiveBooleanValue());
    }

    public function testTwoOrMoreAtomicValuesWithoutALeadingNodeIsATypeError(): void
    {
        $seq = Sequence::of([AtomicValue::string('a'), AtomicValue::string('b')]);

        $this->expectException(\InvalidArgumentException::class);
        $seq->effectiveBooleanValue();
    }

    public function testAtomizeConvertsNodesToUntypedAtomicStringValue(): void
    {
        $dom = new \DOMDocument();
        $node = $dom->createElement('x', 'hello');

        $atomized = Sequence::singleton($node)->atomize();

        self::assertCount(1, $atomized);
        self::assertSame(AtomicValue::TYPE_UNTYPED_ATOMIC, $atomized[0]->type);
        self::assertSame('hello', $atomized[0]->asString());
    }

    public function testAtomizeLeavesAtomicValuesUnchanged(): void
    {
        $value = AtomicValue::decimal(Decimal::fromInt(5));

        self::assertSame($value, Sequence::singleton($value)->atomize()[0]);
    }

    public function testAppend(): void
    {
        $a = Sequence::singleton(AtomicValue::string('a'));
        $b = Sequence::singleton(AtomicValue::string('b'));

        self::assertSame(2, $a->append($b)->count());
    }
}
