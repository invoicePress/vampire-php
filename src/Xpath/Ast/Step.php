<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

/**
 * A step in a `PathExpr`. XPath allows a `StepExpr` to be EITHER an axis step (`axis::test`, or
 * the common abbreviated forms `cac:Foo`, `@id`, `.`, `..`) OR any `PrimaryExpr` (a function
 * call, variable, literal, parenthesized expression) evaluated in the current context - and this
 * is true at ANY position in the path, not only the first (e.g.
 * `cac:TaxScheme/normalize-space(upper-case(cbc:ID))='VAT'`, a real expression from the corpus,
 * has a function-call step as its SECOND step). `axis`/`test` are used for the former, `primary`
 * for the latter - exactly one of `primary` or (`axis`,`test`) is set.
 */
final readonly class Step
{
    /**
     * @param Expr[] $predicates
     */
    public function __construct(
        public ?string $axis,
        public ?NodeTest $test,
        public array $predicates = [],
        public ?Expr $primary = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'axis' => $this->axis,
            'test' => $this->test?->toArray(),
            'predicates' => array_map(static fn (Expr $e): array => $e->toArray(), $this->predicates),
            'primary' => $this->primary?->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $predicates */
        $predicates = $data['predicates'] ?? [];

        /** @var array<string, mixed>|null $testData */
        $testData = $data['test'] ?? null;
        /** @var array<string, mixed>|null $primaryData */
        $primaryData = $data['primary'] ?? null;

        return new self(
            isset($data['axis']) ? (string) $data['axis'] : null,
            $testData !== null ? NodeTest::fromArray($testData) : null,
            array_map(AstSerializer::fromArray(...), $predicates),
            $primaryData !== null ? AstSerializer::fromArray($primaryData) : null,
        );
    }
}
