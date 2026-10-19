<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class UnaryOp implements Expr
{
    /**
     * @param '-'|'+' $op
     */
    public function __construct(
        public string $op,
        public Expr $operand,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'UnaryOp';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return ['node' => 'UnaryOp', 'pos' => $this->pos, 'op' => $this->op, 'operand' => $this->operand->toArray()];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $operand */
        $operand = $data['operand'];

        $op = (string) $data['op'];
        if ($op !== '-' && $op !== '+') {
            throw new \InvalidArgumentException("invalid UnaryOp op in cache: \"$op\"");
        }

        return new self($op, AstSerializer::fromArray($operand), (int) $data['pos']);
    }
}
