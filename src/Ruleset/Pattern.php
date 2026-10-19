<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

/**
 * One `<sch:pattern id="..." name="...">` (Aufgabe 3.1/3.3) - one Schematron `mode` in the
 * compiled XSLT (e.g. `UBL-model`/`M11`). `rules` keeps the original declaration order (= the
 * XSLT's descending-priority order); `Engine` (Aufgabe 3.4) relies on this order directly.
 */
final readonly class Pattern
{
    /**
     * @param Rule[] $rules
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $rules,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'rules' => array_map(static fn (Rule $r): array => $r->toArray(), $this->rules),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $rulesData */
        $rulesData = $data['rules'];

        return new self(
            (string) $data['id'],
            (string) $data['name'],
            array_map(Rule::fromArray(...), $rulesData),
        );
    }
}
