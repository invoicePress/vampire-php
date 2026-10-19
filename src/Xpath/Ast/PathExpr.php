<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class PathExpr implements Expr
{
    /**
     * @param Step[] $steps
     */
    public function __construct(
        public array $steps,
        public bool $absolute,
        private int $pos,
    ) {
    }

    public function node(): string
    {
        return 'PathExpr';
    }

    public function pos(): int
    {
        return $this->pos;
    }

    public function toArray(): array
    {
        return [
            'node' => 'PathExpr',
            'pos' => $this->pos,
            'absolute' => $this->absolute,
            'steps' => array_map(static fn (Step $s): array => $s->toArray(), $this->steps),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $steps */
        $steps = $data['steps'];

        return new self(
            array_map(Step::fromArray(...), $steps),
            (bool) $data['absolute'],
            (int) $data['pos'],
        );
    }
}
