<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

/**
 * Task 5.6 — one rule, as the catalogue describes it.
 *
 * @see RuleCatalog
 */
final readonly class CatalogEntry
{
    /**
     * @param string       $ruleset ruleset reference, e.g. "EN16931-UBL@1.3.16"
     * @param string       $kind    'assert' (fires when false) or 'report' (fires when true)
     * @param list<string> $fields  BT/BG references the message names
     * @param ?string      $duplicateKey Task 5.8d - same key means same rule, even in another rule set
     *                                   and even under another id. `null` where no key is known.
     * @param array<string, string> $descriptions Task 0.2c - rule text per language, for display.
     */
    public function __construct(
        public string $id,
        public string $ruleset,
        public string $rulesetId,
        public string $version,
        public string $pattern,
        public string $kind,
        public string $flag,
        public string $text,
        public array $fields,
        public ?string $duplicateKey = null,
        public array $descriptions = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ruleset' => $this->ruleset,
            'rulesetId' => $this->rulesetId,
            'version' => $this->version,
            'pattern' => $this->pattern,
            'kind' => $this->kind,
            'flag' => $this->flag,
            'text' => $this->text,
            'fields' => $this->fields,
            'duplicateKey' => $this->duplicateKey,
            'descriptions' => $this->descriptions,
        ];
    }
}
