<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

use EinvoiceValidator\Exception\UnsupportedConstructException;
use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Xpath\Ast\AstSerializer;
use EinvoiceValidator\Xpath\Ast\Expr;

/**
 * One `<sch:assert>`/`<sch:report>` inside a rule (Aufgabe 3.1/3.3), compiled to
 * `<svrl:failed-assert>`/`<svrl:successful-report>` by the KoSIT/CEN build. `kind` is carried
 * faithfully even though every rule measured across all four EN16931/XRechnung rulesets (274+152
 * assert/report tests) is `'assert'` (0 `<svrl:successful-report>` found, see docs/notes/decisions.md) -
 * `Engine::evaluateAssertion()` throws a clear, located error for `'report'` instead of silently
 * mishandling its inverted polarity if one is ever imported (Regel A.6).
 *
 * @see docs/notes/decisions.md for the `id`/`flag` extraction and the BT/BG reference regex.
 */
final readonly class Assertion
{
    /**
     * @param MessageSegment[] $message
     * @param string[] $fieldRefs Affected BT/BG field references named in the message text, e.g.
     *                            ["BG-16"], ["BT-122"] - empty when the message names none.
     * @param ?string $duplicateKey Task 0.2c/5.8 - hash over the normalised context+test, taken from
     *                              the suite catalogue's `DUPLICATES.json`. Two rules with the SAME
     *                              key are the same rule, even in different rule sets and even under
     *                              different ids (`UBL-CR-400` vs `ubl-CR-400`). `null` where the
     *                              catalogue knows no counterpart - never guessed here.
     * @param array<string, string> $descriptions Task 0.2c - the rule text per language ("de", "fr",
     *                              …) from the suite catalogue, for display. ⚠️ NOT for the report:
     *                              a finding quotes the rule set's own original wording (rule A.9),
     *                              otherwise the output stops being comparable with the reference
     *                              validator.
     */
    public function __construct(
        public ?string $id,
        public Flag $flag,
        // Not typed as the union 'assert'|'report' in the doc: PHPStan would then treat the
        // runtime guard below as dead code and refuse to compile, but the guard is genuinely
        // needed since the caller's own value is a plain string (Regel A.6).
        public string $kind,
        public Expr $test,
        public array $message,
        public array $fieldRefs,
        public ?string $duplicateKey = null,
        public array $descriptions = [],
    ) {
        if ($kind !== 'assert' && $kind !== 'report') {
            throw new UnsupportedConstructException("unknown assertion kind \"$kind\"", 'kind', 0);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'flag' => $this->flag->value,
            'kind' => $this->kind,
            'test' => $this->test->toArray(),
            'message' => array_map(static fn (MessageSegment $m): array => $m->toArray(), $this->message),
            'fieldRefs' => $this->fieldRefs,
        ];

        // Written only when there is something to write. A rule set imported before the catalogue
        // cross-check (Task 0.2c) stays byte-identical, so a re-import shows a real diff and not a
        // field full of nulls.
        if ($this->duplicateKey !== null) {
            $data['duplicateKey'] = $this->duplicateKey;
        }

        if ($this->descriptions !== []) {
            $data['descriptions'] = $this->descriptions;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<int, array<string, mixed>> $messageData */
        $messageData = $data['message'];
        /** @var array<int, string> $fieldRefs */
        $fieldRefs = $data['fieldRefs'] ?? [];
        /** @var array<string, string> $descriptions */
        $descriptions = $data['descriptions'] ?? [];

        return new self(
            $data['id'] !== null ? (string) $data['id'] : null,
            Flag::from((string) $data['flag']),
            (string) $data['kind'],
            AstSerializer::fromArray($data['test']),
            array_map(MessageSegment::fromArray(...), $messageData),
            $fieldRefs,
            isset($data['duplicateKey']) ? (string) $data['duplicateKey'] : null,
            $descriptions,
        );
    }
}
