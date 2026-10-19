<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class ForExpr implements Expr
{
    public function __construct(
        public string $var,
        public Expr $in,
        public Expr $return,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'ForExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'ForExpr',
            'pos' => $this->pos,
            'var' => $this->var,
            'in' => $this->in->toArray(),
            'return' => $this->return->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $in */
        $in = $data['in'];
        /** @var array<string, mixed> $ret */
        $ret = $data['return'];

        return new self(
            (string) $data['var'],
            AstSerializer::fromArray($in),
            AstSerializer::fromArray($ret),
            (int) $data['pos'],
        );
    }
}
