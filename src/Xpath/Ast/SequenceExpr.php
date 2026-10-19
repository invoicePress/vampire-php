<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class SequenceExpr implements Expr
{
    /**
     * @param Expr[] $items
     */
    public function __construct(public array $items, private int $pos)
    {
    }

    public function node(): string
    {
        return 'SequenceExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'SequenceExpr',
            'pos' => $this->pos,
            'items' => array_map(static fn (Expr $e): array => $e->toArray(), $this->items),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $items */
        $items = $data['items'];

        return new self(array_map(AstSerializer::fromArray(...), $items), (int) $data['pos']);
    }
}
