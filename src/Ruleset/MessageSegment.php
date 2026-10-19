<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Ast\Expr;

/**
 * One piece of a rule's message text. Most messages are a single literal segment, taken verbatim from the
 * source ruleset. A real minority (measured: BR-DE-18 in the XRechnung CIUS) interleave `<xsl:value-of>`
 * inside the `<svrl:text>`, so the message can only be rendered once a rule actually fires, against that
 * firing's own context node and variable scope. Exactly one of `literal`/`expr` is set.
 */
final readonly class MessageSegment
{
    private function __construct(
        public ?string $literal,
        public ?Expr $expr,
    ) {
    }

    public static function literal(string $text): self
    {
        return new self($text, null);
    }

    public static function expression(Expr $expr): self
    {
        return new self(null, $expr);
    }

    public function isLiteral(): bool
    {
        return $this->literal !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->literal !== null
            ? ['kind' => 'literal', 'text' => $this->literal]
            : ['kind' => 'expr', 'expr' => $this->expr?->toArray()];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['kind'] ?? null) === 'literal') {
            return self::literal((string) $data['text']);
        }
        if (($data['kind'] ?? null) === 'expr') {
            /** @var array<string, mixed> $exprData */
            $exprData = $data['expr'];

            return self::expression(AstSerializer::fromArray($exprData));
        }

        throw new \InvalidArgumentException('unknown message segment kind: ' . ($data['kind'] ?? '(missing)'));
    }
}
