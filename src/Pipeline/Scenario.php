<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Pipeline;

use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Ast\Expr;

/**
 * One entry from the scenario description JSON (Task 1.3/4.1, imported from the real KoSIT
 * `scenarios.xml` by `devkit/Import/ScenarioXmlImporter`). `match` is a PARSED `Expr` (Xpath\Ast),
 * evaluated via our own `Xpath\Interpreter` (Phase 2) - the real scenarios' own `match`
 * expressions use `exists(...)`, XPath 2.0 (unsupported by PHP's native XPath-1.0-only
 * `DOMXPath::evaluate()`, which a first draft used - see docs/notes/decisions.md for the measured
 * failure). Parsing happens at IMPORT time, not here: `devkit/Xpath/Parser/Parser` is
 * `autoload-dev`-only (never shipped), so `src/` - this class included - can never depend on it;
 * `match` must already be an `Expr` by the time a `Scenario` exists, exactly like
 * `Ruleset\Rule::$context`/`Assertion::$test`.
 */
final readonly class Scenario
{
    /**
     * @param array<string, string> $namespaces prefix => URI, bound into the `Xpath\Interpreter`
     *                                           that evaluates `match`
     * @param string[] $rulesetRefs `rulesets/<id>@<version>.json` ids to run, in order, e.g.
     *                               ["EN16931-UBL@1.3.16", "XRechnung-UBL@3.0.2"]
     * @param array<string, string> $customLevels ruleId => KoSIT's own severity override for this
     *                                             scenario (Flag value, e.g. "warning"), applied in
     *                                             `Validator::applyCustomLevel()`
     * @param string|null $validFrom  ISO date (inclusive) this scenario starts being the right one
     * @param string|null $validUntil ISO date (inclusive) it stops being the right one
     *
     * ⚠️ `validFrom`/`validUntil` are EMPTY for every scenario shipped today: none of the three official
     * configurations states a validity period, and inventing one would decide which rule package an
     * invoice is judged by - the last thing to guess at. The mechanism exists and is tested; the dates
     * get filled in when a source provides them. Empty means "always applicable".
     */
    public function __construct(
        public string $id,
        public string $name,
        public Expr $match,
        public array $namespaces = [],
        public ?string $xsdPath = null,
        public array $rulesetRefs = [],
        public array $customLevels = [],
        public ?string $validFrom = null,
        public ?string $validUntil = null,
    ) {
    }

    /**
     * Task 5.7 — is this scenario the right one for an invoice issued on `$issueDate`?
     *
     * A document without a date (BT-2 missing or unreadable) matches every scenario; the caller then
     * falls back to "the newest one", which is what the task asks for.
     */
    public function appliesOn(?string $issueDate): bool
    {
        if ($issueDate === null || ($this->validFrom === null && $this->validUntil === null)) {
            return true;
        }

        if ($this->validFrom !== null && $issueDate < $this->validFrom) {
            return false;
        }

        return $this->validUntil === null || $issueDate <= $this->validUntil;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'match' => $this->match->toArray(),
            'namespaces' => $this->namespaces,
            'xsdPath' => $this->xsdPath,
            'rulesetRefs' => $this->rulesetRefs,
            'customLevels' => $this->customLevels,
            'validFrom' => $this->validFrom,
            'validUntil' => $this->validUntil,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $matchData */
        $matchData = $data['match'];
        /** @var array<string, string> $namespaces */
        $namespaces = $data['namespaces'] ?? [];
        /** @var string[] $rulesetRefs */
        $rulesetRefs = $data['rulesetRefs'] ?? [];
        /** @var array<string, string> $customLevels */
        $customLevels = $data['customLevels'] ?? [];
        $xsdPath = $data['xsdPath'] ?? null;
        $validFrom = $data['validFrom'] ?? null;
        $validUntil = $data['validUntil'] ?? null;

        return new self(
            (string) $data['id'],
            (string) $data['name'],
            AstSerializer::fromArray($matchData),
            $namespaces,
            $xsdPath !== null ? (string) $xsdPath : null,
            $rulesetRefs,
            $customLevels,
            $validFrom !== null ? (string) $validFrom : null,
            $validUntil !== null ? (string) $validUntil : null,
        );
    }
}
