<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class VarRef implements Expr
{
    public function __construct(public string $name, private int $pos)
    {
    }

    public function node(): string
    {
        return 'VarRef';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return ['node' => 'VarRef', 'pos' => $this->pos, 'name' => $this->name];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['name'], (int) $data['pos']);
    }
}
