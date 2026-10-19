<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Runtime;

/**
 * Task 2.3.1 — an ordered XPath sequence of items (nodes and/or atomic values - XPath allows
 * mixing, even though most of the rules in scope never do).
 */
final readonly class Sequence
{
    /**
     * @param list<\DOMNode|AtomicValue> $items
     */
    private function __construct(private array $items)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<\DOMNode|AtomicValue> $items
     */
    public static function of(array $items): self
    {
        return new self($items);
    }

    public static function singleton(\DOMNode|AtomicValue $item): self
    {
        return new self([$item]);
    }

    /**
     * @return list<\DOMNode|AtomicValue>
     */
    public function items(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function first(): \DOMNode|AtomicValue|null
    {
        return $this->items[0] ?? null;
    }

    public function append(self $other): self
    {
        return new self([...$this->items, ...$other->items]);
    }

    /**
     * Atomization (spec: `fn:data()`): a node's atomized value is its string value as
     * `untypedAtomic` (this project does no XSD typed-value tracking, matching how the compiled
     * Schematron rules use it - never anything more specific than string content); an atomic value
     * atomizes to itself.
     *
     * @return list<AtomicValue>
     */
    public function atomize(): array
    {
        return array_map(
            static fn (\DOMNode|AtomicValue $item): AtomicValue => $item instanceof AtomicValue
                ? $item
                : AtomicValue::untypedAtomic($item->textContent ?? ''),
            $this->items
        );
    }

    /**
     * fn:boolean() / "Effective Boolean Value", verified against the spec (docs/notes/decisions.md):
     * empty sequence -> false; a sequence starting with a node -> true (existence test, whatever
     * else follows); a singleton atomic value -> that value's own EBV; anything else (2+ items,
     * not starting with a node) is a type error in the spec - here it throws rather than guessing.
     */
    public function effectiveBooleanValue(): bool
    {
        if ($this->isEmpty()) {
            return false;
        }

        if ($this->items[0] instanceof \DOMNode) {
            return true;
        }

        if (count($this->items) === 1) {
            /** @var AtomicValue $only */
            $only = $this->items[0];
            return $only->asBoolean();
        }

        throw new \InvalidArgumentException(
            'effective boolean value is undefined for a sequence of two or more atomic values (spec type error)'
        );
    }
}
