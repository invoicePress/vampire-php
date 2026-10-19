<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class FunctionCall implements Expr
{
    /**
     * @param Expr[] $args
     */
    public function __construct(
        public QName $name,
        public array $args,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'FunctionCall';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'FunctionCall',
            'pos' => $this->pos,
            'name' => $this->name->toArray(),
            'args' => array_map(static fn (Expr $e): array => $e->toArray(), $this->args),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{prefix: ?string, local: string} $nameData */
        $nameData = $data['name'];
        /** @var array<int, array<string, mixed>> $args */
        $args = $data['args'];

        return new self(
            QName::fromArray($nameData),
            array_map(AstSerializer::fromArray(...), $args),
            (int) $data['pos'],
        );
    }
}
