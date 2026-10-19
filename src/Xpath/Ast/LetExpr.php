<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class LetExpr implements Expr
{
    public function __construct(
        public string $var,
        public Expr $value,
        public Expr $return,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'LetExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'LetExpr',
            'pos' => $this->pos,
            'var' => $this->var,
            'value' => $this->value->toArray(),
            'return' => $this->return->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $value */
        $value = $data['value'];
        /** @var array<string, mixed> $ret */
        $ret = $data['return'];

        return new self(
            (string) $data['var'],
            AstSerializer::fromArray($value),
            AstSerializer::fromArray($ret),
            (int) $data['pos'],
        );
    }
}
