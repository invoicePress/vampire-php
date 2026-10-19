<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class CastExpr implements Expr
{
    public function __construct(
        public Expr $operand,
        public QName $type,
        public bool $optional,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'CastExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'CastExpr',
            'pos' => $this->pos,
            'operand' => $this->operand->toArray(),
            'type' => $this->type->toArray(),
            'optional' => $this->optional,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $operand */
        $operand = $data['operand'];
        /** @var array{prefix: ?string, local: string} $type */
        $type = $data['type'];

        return new self(
            AstSerializer::fromArray($operand),
            QName::fromArray($type),
            (bool) $data['optional'],
            (int) $data['pos'],
        );
    }
}
