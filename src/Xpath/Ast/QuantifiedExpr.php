<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class QuantifiedExpr implements Expr
{
    /**
     * @param 'some'|'every' $quantifier
     */
    public function __construct(
        public string $quantifier,
        public string $var,
        public Expr $in,
        public Expr $satisfies,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'QuantifiedExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'QuantifiedExpr',
            'pos' => $this->pos,
            'quantifier' => $this->quantifier,
            'var' => $this->var,
            'in' => $this->in->toArray(),
            'satisfies' => $this->satisfies->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $in */
        $in = $data['in'];
        /** @var array<string, mixed> $satisfies */
        $satisfies = $data['satisfies'];

        $quantifier = (string) $data['quantifier'];
        if (!in_array($quantifier, ['some', 'every'], true)) {
            throw new \InvalidArgumentException("invalid QuantifiedExpr quantifier in cache: \"$quantifier\"");
        }

        return new self(
            $quantifier,
            (string) $data['var'],
            AstSerializer::fromArray($in),
            AstSerializer::fromArray($satisfies),
            (int) $data['pos'],
        );
    }
}
