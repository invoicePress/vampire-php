<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

use EinvoiceValidator\Exception\ImportException;
use EinvoiceValidator\Result\Flag;

/**
 * Tasks 5.1–5.4 — a validation profile: which rules are switched off, which are re-ranked, which
 * extra rule sets are switched on. Format and reasoning: docs/developer/profile-format.md.
 *
 * ⚠️ A profile never adds a rule of its own (that is Task 5.5, through the rule model) and never
 * silences a finding after the fact - a disabled rule is not evaluated at all, so nothing is hidden
 * from the report that the report then fails to mention.
 */
final readonly class Profile
{
    /**
     * @param list<string>                      $disable  rule ids that are not evaluated at all
     * @param array<string, Flag>               $severity rule id => severity that replaces the ruleset's
     * @param array<string, list<string>>       $include  ruleset ref => rule ids to switch on
     */
    public function __construct(
        public string $id,
        public ?string $title,
        public array $disable,
        public array $severity,
        public array $include,
        public ?string $note = null,
    ) {
    }

    public function isDisabled(?string $ruleId): bool
    {
        return $ruleId !== null && in_array($ruleId, $this->disable, true);
    }

    /**
     * The severity this profile wants for a rule, or null when it has no opinion.
     *
     * ⚠️ Asked AFTER the scenario's own customLevel, never before: the profile is the outer layer, so
     * it has the last word (docs/developer/profile-format.md).
     */
    public function severityFor(?string $ruleId): ?Flag
    {
        if ($ruleId === null) {
            return null;
        }

        return $this->severity[$ruleId] ?? null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? null;

        if (!is_string($id) || $id === '') {
            throw new ImportException('profile has no "id"');
        }

        $disable = [];

        foreach ((array) ($data['disable'] ?? []) as $ruleId) {
            if (!is_string($ruleId) || $ruleId === '') {
                throw new ImportException("profile \"$id\": \"disable\" must be a list of rule ids");
            }

            $disable[] = $ruleId;
        }

        $severity = [];

        foreach ((array) ($data['severity'] ?? []) as $ruleId => $level) {
            if (!is_string($ruleId) || !is_string($level)) {
                throw new ImportException("profile \"$id\": \"severity\" maps rule id to level");
            }

            $flag = Flag::tryFrom(strtolower($level));

            if ($flag === null) {
                throw new ImportException("profile \"$id\": unknown severity \"$level\" for $ruleId");
            }

            $severity[$ruleId] = $flag;
        }

        $include = [];

        foreach ((array) ($data['include'] ?? []) as $entry) {
            if (!is_array($entry) || !isset($entry['ruleset']) || !is_string($entry['ruleset'])) {
                throw new ImportException("profile \"$id\": each \"include\" entry needs a \"ruleset\"");
            }

            $rules = [];

            foreach ((array) ($entry['rules'] ?? []) as $ruleId) {
                if (!is_string($ruleId)) {
                    throw new ImportException("profile \"$id\": \"include.rules\" must be rule ids");
                }

                $rules[] = $ruleId;
            }

            $include[$entry['ruleset']] = $rules;
        }

        $title = $data['title'] ?? null;
        $note = $data['note'] ?? null;

        return new self(
            $id,
            is_string($title) ? $title : null,
            $disable,
            $severity,
            $include,
            is_string($note) ? $note : null,
        );
    }

    /**
     * Merges another profile ON TOP of this one (`extends`): the other profile's entries win.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            $other->id,
            $other->title ?? $this->title,
            array_values(array_unique([...$this->disable, ...$other->disable])),
            [...$this->severity, ...$other->severity],
            self::mergeIncludes($this->include, $other->include),
            $other->note ?? $this->note,
        );
    }

    /**
     * @param array<string, list<string>> $base
     * @param array<string, list<string>> $top
     * @return array<string, list<string>>
     */
    private static function mergeIncludes(array $base, array $top): array
    {
        foreach ($top as $ruleset => $rules) {
            $base[$ruleset] = array_values(array_unique([...($base[$ruleset] ?? []), ...$rules]));
        }

        return $base;
    }
}
