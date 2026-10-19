<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Xpath\Ast;

final readonly class NodeTest
{
    /**
     * @param 'name'|'wildcard'|'kindtest' $kind
     */
    public function __construct(
        public string $kind,
        public ?QName $name = null,
        public ?string $kindtestName = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name?->toArray(),
            'kindtestName' => $this->kindtestName,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{prefix: ?string, local: string}|null $nameData */
        $nameData = $data['name'] ?? null;

        $kind = (string) $data['kind'];
        if (!in_array($kind, ['name', 'wildcard', 'kindtest'], true)) {
            throw new \InvalidArgumentException("invalid NodeTest kind in cache: \"$kind\"");
        }

        return new self(
            $kind,
            $nameData !== null ? QName::fromArray($nameData) : null,
            isset($data['kindtestName']) ? (string) $data['kindtestName'] : null,
        );
    }
}
