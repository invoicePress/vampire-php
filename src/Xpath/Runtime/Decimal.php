<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Runtime;

/**
 * Task 2.3.3 — xs:decimal arithmetic on BCMath decimal strings. Never a PHP float anywhere in
 * this class (Regel A.5). Semantics verified against the actual W3C spec, not memory:
 * https://www.w3.org/TR/2007/REC-xpath-functions-20070123/ ("XQuery 1.0 and XPath 2.0 Functions
 * and Operators"), sections on op:numeric-divide and fn:round - see docs/notes/decisions.md.
 *
 * - `divide()` throws on division by zero (spec: err:FOAR0001 is mandatory for xs:decimal/xs:integer
 *   operands - unlike float division, which would produce INF/NaN).
 * - `round()` rounds ties toward positive infinity (spec: "if two such numbers are equally near,
 *   the one closest to positive infinity is returned" - e.g. round(-2.5) = -2, NOT -3). Implemented
 *   as floor(x + 0.5), the standard identity for that exact rounding rule.
 * - Division precision is spec-defined as "implementation-defined" (no mandated minimum digit
 *   count) - this class uses a fixed internal scale of 34 fractional digits for every bc* call,
 *   chosen generously for invoice-amount arithmetic (see docs/notes/decisions.md), then trims the
 *   result to its minimal canonical form (value equality does not care about trailing zeros).
 */
final readonly class Decimal
{
    private const SCALE = 34;

    /** @param numeric-string $normalized */
    private function __construct(private string $normalized)
    {
    }

    /**
     * @throws \InvalidArgumentException if `$lexical` is not a valid xs:decimal lexical form
     *         (optional sign, digits, optional single "." with digits - no exponent, unlike
     *         xs:double/xs:float).
     */
    public static function fromLexical(string $lexical): self
    {
        $trimmed = trim($lexical);
        // The regex enforces the actual xs:decimal grammar (no exponent, unlike xs:double/float -
        // is_numeric() alone would wrongly accept "1.5e3"). The is_numeric() check right after is
        // redundant given the regex already matched, but is what lets PHPStan narrow $trimmed to
        // `numeric-string` for canonicalize()/bcmath below - not a workaround, a true statement
        // the type checker can act on.
        if (preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $trimmed) !== 1 || !is_numeric($trimmed)) {
            throw new \InvalidArgumentException("not a valid xs:decimal lexical value: \"$lexical\"");
        }

        return new self(self::canonicalize($trimmed));
    }

    public static function fromInt(int $value): self
    {
        return new self((string) $value);
    }

    public static function zero(): self
    {
        return new self('0');
    }

    /**
     * @param numeric-string $value
     * @return numeric-string
     */
    private static function canonicalize(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');

        if (!str_contains($value, '.')) {
            $value .= '.0';
        }
        [$intPart, $fracPart] = explode('.', $value, 2);

        $intPart = ltrim($intPart, '0');
        if ($intPart === '') {
            $intPart = '0';
        }

        $fracPart = rtrim($fracPart, '0');

        $isZero = $intPart === '0' && $fracPart === '';
        $sign = ($negative && !$isZero) ? '-' : '';

        $result = $fracPart === '' ? "{$sign}{$intPart}" : "{$sign}{$intPart}.{$fracPart}";

        // Unreachable in practice - $intPart/$fracPart are digit-only slices of an already
        // xs:decimal-shaped input, so this string is always numeric. The check exists so PHPStan
        // can narrow the return type to numeric-string from a real, verifiable fact rather than
        // an asserted one.
        if (!is_numeric($result)) {
            throw new \LogicException("unreachable: canonicalize() built a non-numeric string: \"$result\"");
        }

        return $result;
    }

    public function toString(): string
    {
        return $this->normalized;
    }

    public function add(self $other): self
    {
        return new self(self::canonicalize(bcadd($this->normalized, $other->normalized, self::SCALE)));
    }

    public function subtract(self $other): self
    {
        return new self(self::canonicalize(bcsub($this->normalized, $other->normalized, self::SCALE)));
    }

    public function multiply(self $other): self
    {
        return new self(self::canonicalize(bcmul($this->normalized, $other->normalized, self::SCALE)));
    }

    /**
     * @throws \DivisionByZeroError spec err:FOAR0001 - decimal division by zero is an error, not
     *         a float-style infinity/NaN result.
     */
    public function divide(self $other): self
    {
        if ($other->isZero()) {
            throw new \DivisionByZeroError('xs:decimal division by zero (err:FOAR0001)');
        }

        return new self(self::canonicalize(bcdiv($this->normalized, $other->normalized, self::SCALE)));
    }

    public function negate(): self
    {
        return $this->isZero() ? $this : new self(self::canonicalize(bcmul($this->normalized, '-1', self::SCALE)));
    }

    /**
     * -1 / 0 / 1, like `<=>`/`strcmp` - never IEEE-style NaN, xs:decimal has no such value.
     */
    public function compareTo(self $other): int
    {
        return bccomp($this->normalized, $other->normalized, self::SCALE);
    }

    public function equals(self $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->normalized, '0', self::SCALE) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->normalized, '0', self::SCALE) < 0;
    }

    /**
     * fn:round: nearest integer, ties toward positive infinity (verified against the spec, see
     * class docblock) - implemented as floor(x + 0.5), never a native round()/float operation.
     */
    public function round(): self
    {
        return $this->add(self::fromLexical('0.5'))->floor();
    }

    public function floor(): self
    {
        $truncated = self::canonicalize(bcadd($this->normalized, '0', 0));
        $truncatedDecimal = new self($truncated);

        if ($truncatedDecimal->equals($this)) {
            return $truncatedDecimal;
        }

        return $this->isNegative() ? $truncatedDecimal->subtract(self::fromInt(1)) : $truncatedDecimal;
    }

    /**
     * @param self[] $values Empty sequence sums to 0, per fn:sum($seq, 0) - the two-argument form
     *                        is not needed since callers never observe an empty input differently.
     */
    public static function sum(array $values): self
    {
        $total = self::zero();
        foreach ($values as $value) {
            $total = $total->add($value);
        }

        return $total;
    }

    public static function abs(self $value): self
    {
        return $value->isNegative() ? $value->negate() : $value;
    }
}
