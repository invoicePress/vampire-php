<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

/**
 * Marker interface for every AST node (Aufgabe 2.1, docs/developer/ast-format.md). `node` is the JSON
 * discriminator field used by both `toArray()` and `AstSerializer::fromArray()`.
 */
interface Expr
{
    public function node(): string;

    public function pos(): int;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
