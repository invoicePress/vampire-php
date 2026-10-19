<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Ast\Expr;

/**
 * One `<sch:let name="..." value="...">` binding, compiled to `<xsl:variable>`/`<xsl:param>` by
 * the KoSIT/CEN build (see docs/notes/decisions.md, Aufgabe 3.1). Two flavours share this same shape:
 * document-global (declared once, evaluated against the document root before any pattern runs)
 * and rule-level (declared inside one `xsl:template`/rule, evaluated per context node the rule
 * fires on, chained after the globals - see `Engine`, Aufgabe 3.4).
 */
final readonly class LetBinding
{
    public function __construct(
        public string $name,
        public Expr $expr,
    ) {
    }

    /**
     * @return array{name: string, expr: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'expr' => $this->expr->toArray()];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $exprData */
        $exprData = $data['expr'];

        return new self((string) $data['name'], AstSerializer::fromArray($exprData));
    }
}
