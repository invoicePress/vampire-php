<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class RangeExpr implements Expr
{
    public function __construct(public Expr $from, public Expr $to, private int $pos)
    {
    }

    public function node(): string
    {
        return 'RangeExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'RangeExpr',
            'pos' => $this->pos,
            'from' => $this->from->toArray(),
            'to' => $this->to->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $from */
        $from = $data['from'];
        /** @var array<string, mixed> $to */
        $to = $data['to'];

        return new self(AstSerializer::fromArray($from), AstSerializer::fromArray($to), (int) $data['pos']);
    }
}
