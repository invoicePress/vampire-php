<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class BinaryOp implements Expr
{
    public function __construct(
        public string $op,
        public Expr $left,
        public Expr $right,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'BinaryOp';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'BinaryOp',
            'pos' => $this->pos,
            'op' => $this->op,
            'left' => $this->left->toArray(),
            'right' => $this->right->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $left */
        $left = $data['left'];
        /** @var array<string, mixed> $right */
        $right = $data['right'];

        return new self(
            (string) $data['op'],
            AstSerializer::fromArray($left),
            AstSerializer::fromArray($right),
            (int) $data['pos'],
        );
    }
}
