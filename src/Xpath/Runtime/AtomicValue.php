<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Runtime;

/**
 * Task 2.3.1 — one atomic item in the XPath 2.0 data model. `untypedAtomic` is kept as its own
 * type (not folded into `string`) because general comparison treats it differently depending on
 * what it is compared against (docs/notes/decisions.md quotes the verified spec rule) - a node's
 * atomized value is untypedAtomic, a string literal in the expression is `xs:string`, and the two
 * are NOT the same type even though both print the same way.
 */
final readonly class AtomicValue
{
    public const TYPE_STRING = 'string';
    public const TYPE_UNTYPED_ATOMIC = 'untypedAtomic';
    public const TYPE_DECIMAL = 'decimal';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';

    private function __construct(
        public string $type,
        private string|Decimal|bool $raw,
    ) {
    }

    public static function string(string $value): self
    {
        return new self(self::TYPE_STRING, $value);
    }

    public static function untypedAtomic(string $value): self
    {
        return new self(self::TYPE_UNTYPED_ATOMIC, $value);
    }

    public static function decimal(Decimal $value): self
    {
        return new self(self::TYPE_DECIMAL, $value);
    }

    public static function integer(Decimal $value): self
    {
        return new self(self::TYPE_INTEGER, $value);
    }

    public static function boolean(bool $value): self
    {
        return new self(self::TYPE_BOOLEAN, $value);
    }

    public function isNumeric(): bool
    {
        return $this->type === self::TYPE_DECIMAL || $this->type === self::TYPE_INTEGER;
    }

    public function asString(): string
    {
        // Branch on the actual stored type, not the $type tag - lets PHPStan narrow $this->raw
        // properly in each arm instead of leaving it as the full string|Decimal|bool union.
        return match (true) {
            $this->raw instanceof Decimal => $this->raw->toString(),
            is_bool($this->raw) => $this->raw ? 'true' : 'false',
            default => $this->raw,
        };
    }

    /**
     * @throws \InvalidArgumentException if this value cannot be cast to xs:decimal.
     */
    public function asDecimal(): Decimal
    {
        if ($this->raw instanceof Decimal) {
            return $this->raw;
        }
        if (is_string($this->raw)) {
            return Decimal::fromLexical($this->raw);
        }

        throw new \InvalidArgumentException("cannot cast {$this->type} value to xs:decimal");
    }

    /**
     * fn:boolean() effective-boolean-value rule for a *singleton* atomic value (verified against
     * the spec, see Sequence::effectiveBooleanValue() for the full-sequence rule this feeds into).
     *
     * ⚠️ NOT the same rule as casting to xs:boolean - "any non-empty string is true" (this method)
     * is EBV's rule, but general/value comparison against an actual xs:boolean, and `cast
     * as`/`castable as xs:boolean`, must use the XML Schema LEXICAL casting rule instead
     * ({"true","1"} -> true, {"false","0"} -> false, anything else -> a cast error - verified
     * against the XML Schema Part 2 boolean lexical space, https://www.w3.org/TR/xmlschema-2/#boolean).
     * Conflating the two was a real, measured bug: `cbc:ChargeIndicator = true()` (a real BR-CL-20
     * rule context predicate) wrongly matched a node whose text was literally "false", because
     * "false" is a non-empty PHP string - use `castToBoolean()` for casting/comparison, this method
     * ONLY for effective-boolean-value contexts (`if(...)`, `and`/`or`, a predicate's own EBV).
     */
    public function asBoolean(): bool
    {
        return match (true) {
            is_bool($this->raw) => $this->raw,
            $this->raw instanceof Decimal => !$this->raw->isZero(),
            default => $this->raw !== '',
        };
    }

    /**
     * xs:boolean casting (XML Schema Part 2 lexical space {"true","1","false","0"}, verified via
     * WebFetch against https://www.w3.org/TR/xmlschema-2/#boolean, not assumed) - see `asBoolean()`
     * for why this is a DIFFERENT rule from effective-boolean-value, and must be used instead of it
     * for general/value comparison against a real xs:boolean and for `cast as`/`castable as
     * xs:boolean`. An already-boolean or numeric value casts the same way under both rules (a
     * number's cast-to-boolean rule, "zero/NaN is false, anything else is true", coincides with
     * EBV), so only the string/untypedAtomic branch actually differs.
     *
     * @throws \InvalidArgumentException if the string is not in xs:boolean's lexical space.
     */
    public function castToBoolean(): bool
    {
        return match (true) {
            is_bool($this->raw) => $this->raw,
            $this->raw instanceof Decimal => !$this->raw->isZero(),
            $this->raw === 'true' || $this->raw === '1' => true,
            $this->raw === 'false' || $this->raw === '0' => false,
            default => throw new \InvalidArgumentException(
                "cannot cast \"{$this->raw}\" to xs:boolean (err:FORG0001)"
            ),
        };
    }
}
