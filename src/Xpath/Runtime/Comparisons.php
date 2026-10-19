<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Runtime;

/**
 * Task 2.3.2 — general comparisons (`=` `!=` `<` `<=` `>` `>=`, existential over both operand
 * sequences) and value comparisons (`eq` `ne` `lt` `le` `gt` `ge`, a single pair of atomic
 * values). Rules verified against the spec (docs/notes/decisions.md), not memory.
 */
final class Comparisons
{
    private const GENERAL_OPS = ['=', '!=', '<', '<=', '>', '>='];
    private const VALUE_OPS = ['eq', 'ne', 'lt', 'le', 'gt', 'ge'];

    /**
     * Existential quantification over both atomized operand sequences (spec: "true if there exist
     * pairs of values, one from each operand, that compare equal/less/greater"). Either operand
     * empty -> false, never an error - matches the spec's own worked rule, see docs/notes/decisions.md.
     */
    public static function general(Sequence $left, Sequence $right, string $op): bool
    {
        if (!in_array($op, self::GENERAL_OPS, true)) {
            throw new \InvalidArgumentException("not a general comparison operator: \"$op\"");
        }

        if ($left->isEmpty() || $right->isEmpty()) {
            return false;
        }

        foreach ($left->atomize() as $a) {
            foreach ($right->atomize() as $b) {
                if (self::compareAtomic($a, $b, $op)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Value comparison: both operands must already be singleton sequences - the caller
     * (Interpreter, Task 2.4) is responsible for that; a multi-item operand is a spec type error,
     * not something this method silently reduces to "first item".
     */
    public static function value(AtomicValue $left, AtomicValue $right, string $op): bool
    {
        if (!in_array($op, self::VALUE_OPS, true)) {
            throw new \InvalidArgumentException("not a value comparison operator: \"$op\"");
        }

        return self::compareAtomic($left, $right, self::valueOpToGeneralOp($op));
    }

    private static function valueOpToGeneralOp(string $valueOp): string
    {
        return match ($valueOp) {
            'eq' => '=',
            'ne' => '!=',
            'lt' => '<',
            'le' => '<=',
            'gt' => '>',
            'ge' => '>=',
            default => throw new \InvalidArgumentException("not a value comparison operator: \"$valueOp\""),
        };
    }

    /**
     * Type-aware pairwise comparison. untypedAtomic (a node's atomized value) converts to whatever
     * type it is compared against - numeric if the other side is numeric, otherwise string. Two
     * untypedAtomic/string values compare as strings (codepoint collation, i.e. plain PHP string
     * comparison - no locale-specific collation, matching the default XPath behaviour).
     */
    private static function compareAtomic(AtomicValue $a, AtomicValue $b, string $generalOp): bool
    {
        if ($a->isNumeric() || $b->isNumeric()) {
            if (self::isComparableToNumeric($a) && self::isComparableToNumeric($b)) {
                return self::applyOp($a->asDecimal()->compareTo($b->asDecimal()), $generalOp);
            }
        }

        if ($a->type === AtomicValue::TYPE_BOOLEAN || $b->type === AtomicValue::TYPE_BOOLEAN) {
            // castToBoolean(), NOT asBoolean(): comparing against a real xs:boolean casts the
            // other side via the XML Schema lexical rule, not the effective-boolean-value rule -
            // see AtomicValue::asBoolean()'s own docblock for the real bug this distinction fixes.
            $boolCompare = ($a->castToBoolean() <=> $b->castToBoolean());
            return self::applyOp($boolCompare, $generalOp);
        }

        // strcmp(), not PHP's <=>: the spaceship operator silently does NUMERIC comparison when
        // both operands look like numbers (e.g. "10" <=> "9" is 1, not -1) - wrong for XPath
        // string comparison, which is pure codepoint/byte collation regardless of content. Caught
        // by testUntypedAtomicComparesAsStringAgainstAStringOperand() before this shipped.
        return self::applyOp(strcmp($a->asString(), $b->asString()) <=> 0, $generalOp);
    }

    private static function isComparableToNumeric(AtomicValue $value): bool
    {
        return $value->isNumeric()
            || $value->type === AtomicValue::TYPE_UNTYPED_ATOMIC
            || $value->type === AtomicValue::TYPE_STRING;
    }

    private static function applyOp(int $spaceship, string $generalOp): bool
    {
        return match ($generalOp) {
            '=' => $spaceship === 0,
            '!=' => $spaceship !== 0,
            '<' => $spaceship < 0,
            '<=' => $spaceship <= 0,
            '>' => $spaceship > 0,
            '>=' => $spaceship >= 0,
            default => throw new \InvalidArgumentException("not a comparison operator: \"$generalOp\""),
        };
    }
}
