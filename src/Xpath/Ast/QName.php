<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

/**
 * Not an `Expr` node itself (docs/developer/ast-format.md) - a plain value used inside `FunctionCall`/`NodeTest`.
 */
final readonly class QName
{
    public function __construct(public ?string $prefix, public string $local)
    {
    }

    public function full(): string
    {
        return $this->prefix !== null ? "{$this->prefix}:{$this->local}" : $this->local;
    }

    /**
     * @return array{prefix: ?string, local: string}
     */
    public function toArray(): array
    {
        return ['prefix' => $this->prefix, 'local' => $this->local];
    }

    /**
     * @param array{prefix: ?string, local: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['prefix'], $data['local']);
    }
}
