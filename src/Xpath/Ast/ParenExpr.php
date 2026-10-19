<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

/**
 * Only preserved for pretty-printing / round-tripping the original grouping - the Interpreter
 * evaluates `inner` directly and otherwise ignores this wrapper (see docs/developer/ast-format.md).
 */
final readonly class ParenExpr implements Expr
{
    public function __construct(public Expr $inner, private int $pos)
    {
    }

    public function node(): string
    {
        return 'ParenExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return ['node' => 'ParenExpr', 'pos' => $this->pos, 'inner' => $this->inner->toArray()];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $inner */
        $inner = $data['inner'];

        return new self(AstSerializer::fromArray($inner), (int) $data['pos']);
    }
}
