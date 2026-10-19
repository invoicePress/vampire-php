<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Ruleset;

/**
 * Task 5.6 — one machine-readable list of every rule this installation knows, across all shipped rule
 * sets. Basis for a rule explorer, for a rule designer, and for the report to explain where a finding
 * comes from.
 *
 * ⚠️ A rule id is NOT unique across rule sets: BR-02 exists in EN16931-UBL, EN16931-CII, the Peppol
 * copy and both Factur-X profiles, with different contexts and sometimes different severities. The
 * catalogue therefore keys entries by ruleset AND id, and the lookup by id returns a LIST. Collapsing
 * them into one entry would quietly hide, for instance, that the Peppol distribution ships its own
 * version of a CEN rule.
 */
final class RuleCatalog
{
    /** @var list<CatalogEntry> */
    private array $entries = [];

    /**
     * @param list<string> $rulesetFiles paths of `rulesets/<id>@<version>.json` files
     */
    public static function fromFiles(array $rulesetFiles): self
    {
        $catalog = new self();

        foreach ($rulesetFiles as $file) {
            $ruleset = RulesetJsonCodec::readFile($file);
            $ref = $ruleset->id . '@' . $ruleset->version;

            foreach ($ruleset->patterns as $pattern) {
                foreach ($pattern->rules as $rule) {
                    foreach ($rule->assertions as $assertion) {
                        if ($assertion->id === null) {
                            continue;
                        }

                        $catalog->entries[] = new CatalogEntry(
                            $assertion->id,
                            $ref,
                            $ruleset->id,
                            $ruleset->version,
                            $pattern->name,
                            $assertion->kind,
                            $assertion->flag->value,
                            self::literalText($assertion),
                            array_values($assertion->fieldRefs),
                            $assertion->duplicateKey,
                            $assertion->descriptions,
                        );
                    }
                }
            }
        }

        return $catalog;
    }

    /** @return list<CatalogEntry> */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return list<CatalogEntry> every rule set that defines this id */
    public function byId(string $ruleId): array
    {
        return array_values(array_filter($this->entries, static fn (CatalogEntry $e): bool => $e->id === $ruleId));
    }

    /** @return list<CatalogEntry> */
    public function byRuleset(string $ref): array
    {
        return array_values(array_filter($this->entries, static fn (CatalogEntry $e): bool => $e->ruleset === $ref));
    }

    /**
     * Task 5.8d — every entry that IS this rule, whatever it is called and whichever rule set it sits
     * in. This is the lookup a rule explorer needs: show the rule once, list its origins underneath.
     *
     * @return list<CatalogEntry>
     */
    public function byDuplicateKey(string $duplicateKey): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (CatalogEntry $e): bool => $e->duplicateKey === $duplicateKey
        ));
    }

    /**
     * Task 5.8d — the rule sets a rule appears in, found through its `duplicateKey` and therefore
     * across differently-spelled ids as well.
     *
     * ⚠️ Falls back to the plain id when the rule has no key. Returning an empty list instead would
     * read as "this rule exists nowhere", which is the opposite of the truth for every rule set
     * imported before the catalogue cross-check.
     *
     * @return list<string> ruleset references, in catalogue order, without repetition
     */
    public function rulesetsOf(string $ruleId): array
    {
        $entries = array_values(array_filter($this->entries, static fn (CatalogEntry $e): bool => $e->id === $ruleId));

        if ($entries === []) {
            return [];
        }

        $key = null;

        foreach ($entries as $entry) {
            if ($entry->duplicateKey !== null) {
                $key = $entry->duplicateKey;
                break;
            }
        }

        if ($key !== null) {
            $entries = $this->byDuplicateKey($key);
        }

        $refs = [];

        foreach ($entries as $entry) {
            if (!in_array($entry->ruleset, $refs, true)) {
                $refs[] = $entry->ruleset;
            }
        }

        return $refs;
    }

    /** @return list<CatalogEntry> rules whose message names this BT/BG */
    public function byField(string $field): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (CatalogEntry $e): bool => in_array($field, $e->fields, true)
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (CatalogEntry $e): array => $e->toArray(), $this->entries);
    }

    /**
     * The fixed part of the message. The value-of segments are left out on purpose: they only have a
     * meaning against a concrete document, and a catalogue entry that reads "… is 0.00" would be
     * about one invoice rather than about the rule.
     */
    private static function literalText(Assertion $assertion): string
    {
        $text = '';

        foreach ($assertion->message as $segment) {
            $text .= $segment->literal ?? '';
        }

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
