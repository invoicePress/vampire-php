<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Ast\Expr;

/**
 * One `<sch:rule context="...">` (Aufgabe 3.1/3.3), compiled to one `<xsl:template match="..."
 * mode="M..">`. `context` is the rule's match pattern ALREADY NORMALIZED (Aufgabe 3.1, see
 * `SchematronXsltImporter::normalizeContext()`) to an always-from-root path: a relative pattern
 * like `cac:Foo` is rewritten to `//cac:Foo` at the AST level (not by string concatenation, which
 * would break on a mixed union of relative/absolute alternatives) so `Engine` (Aufgabe 3.4) can
 * evaluate it directly against the document root to get every candidate node, with no special
 * casing at match time. `priority` is the original XSLT template priority - descending by
 * declaration order (measured: strictly descending, no gaps, no duplicates within any one rule
 * mode across all four rulesets) - `Engine` sorts a pattern's rules by this, descending, to
 * reproduce Schematron's "first matching rule per node wins" semantics.
 */
final readonly class Rule
{
    /**
     * @param LetBinding[] $lets
     * @param Assertion[] $assertions
     */
    public function __construct(
        public Expr $context,
        public int $priority,
        public array $lets,
        public array $assertions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'context' => $this->context->toArray(),
            'priority' => $this->priority,
            'lets' => array_map(static fn (LetBinding $l): array => $l->toArray(), $this->lets),
            'assertions' => array_map(static fn (Assertion $a): array => $a->toArray(), $this->assertions),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $letsData */
        $letsData = $data['lets'];
        /** @var array<int, array<string, mixed>> $assertionsData */
        $assertionsData = $data['assertions'];

        return new self(
            AstSerializer::fromArray($data['context']),
            (int) $data['priority'],
            array_map(LetBinding::fromArray(...), $letsData),
            array_map(Assertion::fromArray(...), $assertionsData),
        );
    }
}
