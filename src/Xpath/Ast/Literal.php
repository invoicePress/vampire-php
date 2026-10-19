<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class Literal implements Expr
{
    /**
     * @param 'string'|'integer'|'decimal' $kind
     */
    public function __construct(
        public string $kind,
        public string $value,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'Literal';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return ['node' => 'Literal', 'pos' => $this->pos, 'kind' => $this->kind, 'value' => $this->value];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $kind = (string) $data['kind'];
        if (!in_array($kind, ['string', 'integer', 'decimal'], true)) {
            throw new \InvalidArgumentException("invalid Literal kind in cache: \"$kind\"");
        }

        return new self($kind, (string) $data['value'], (int) $data['pos']);
    }
}
