<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class IfExpr implements Expr
{
    public function __construct(
        public Expr $cond,
        public Expr $then,
        public Expr $else,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'IfExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'IfExpr',
            'pos' => $this->pos,
            'cond' => $this->cond->toArray(),
            'then' => $this->then->toArray(),
            'else' => $this->else->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $cond */
        $cond = $data['cond'];
        /** @var array<string, mixed> $then */
        $then = $data['then'];
        /** @var array<string, mixed> $else */
        $else = $data['else'];

        return new self(
            AstSerializer::fromArray($cond),
            AstSerializer::fromArray($then),
            AstSerializer::fromArray($else),
            (int) $data['pos'],
        );
    }
}
