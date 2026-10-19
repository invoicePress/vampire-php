<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Profile;

use EinvoiceValidator\Ruleset\Ruleset;

/**
 * How rule profiles change one validation run. The twin of
 * `ProfileOverlay` in VAMPIRE JS; `Validator` calls it.
 *
 * ⚠️ AN OVERLAY ON THE RESULT, NOT A CHANGE TO THE RULE SETS. The official rule sets run complete. A
 * rule a profile switches off or replaces is still evaluated; its findings stay out of the report's
 * findings, are counted in `profiles[].disabled[].suppressed`, and still count for `officialAccepted`.
 * The Rule Studio promises that "konform zu XRechnung" remains a true statement next to "konform zu den
 * Anforderungen von Firma X" - that needs the disabled rule's answer. It is the opposite of the operator
 * profile (`Profile`, docs/developer/profile-format.md), whose `disable` really skips the rule; the two serve
 * different readers and are kept apart on purpose.
 *
 * ⚠️ A RULE IS NAMED BY ONE ID AND MEANS EVERY SPELLING OF IT: ids that share a `duplicateKey` in the
 * loaded rule sets are switched off or re-ranked together.
 */
final class RuleProfileOverlay
{
    /** The texts this overlay writes into a report. Same wording as `PROFILE_TEXTS` in the JS twin. */
    private const TEXTS = [
        'de' => [
            'notApplied' => 'Regelprofil %1$s gilt nicht für dieses Szenario; es ist für %2$s geschrieben.',
            'version' => 'Regelprofil %1$s wurde für %2$s %3$s geschrieben, geprüft wurde mit %4$s. Ob seine Regeln und Abschaltungen zu dieser Fassung passen, ist nicht geprüft.',
            'unknownRule' => 'Regelprofil %1$s nennt die Regel %2$s, die in den geprüften Regelwerken nicht vorkommt. Der Eintrag bleibt ohne Wirkung.',
            'ruleError' => 'Die Regel %2$s aus Regelprofil %1$s konnte nicht ausgewertet werden: %3$s',
        ],
        'en' => [
            'notApplied' => 'Rule profile %1$s does not apply to this scenario; it was written for %2$s.',
            'version' => 'Rule profile %1$s was written for %2$s %3$s, the invoice was checked with %4$s. Whether its rules and switches fit this edition has not been checked.',
            'unknownRule' => 'Rule profile %1$s names the rule %2$s, which does not occur in the rule sets used. The entry has no effect.',
            'ruleError' => 'The rule %2$s of rule profile %1$s could not be evaluated: %3$s',
        ],
    ];

    /** @var list<array<string, mixed>> one entry per profile for the report's `profiles` */
    public array $entries = [];

    /** @var array<string, list<string>> profile ref => notices */
    public array $notices = [];

    /** @var list<RuleProfile> */
    public array $applied = [];

    /** @var array<string, int> rule id => index into `$disabledEntries` - the first profile wins */
    private array $disabledBy = [];

    /** @var list<array{profile: int, entry: int}> */
    private array $disabledEntries = [];

    /** @var array<string, array{level: string, ref: string, explicit: bool}> */
    private array $severityBy = [];

    private string $lang;

    /**
     * @param list<RuleProfile> $profiles
     * @param list<string> $refs the resolved scenario's rule set references
     * @param array<string, Ruleset> $loaded ref => rule set, in scenario order
     */
    public function __construct(array $profiles, array $refs, array $loaded, string $lang = 'de')
    {
        $this->lang = isset(self::TEXTS[$lang]) ? $lang : 'de';

        [$idsOf, $known] = self::siblings($loaded);

        foreach ($profiles as $p => $profile) {
            $entry = self::stub($profile);
            $notices = [];

            foreach ($entry['extends'] as $i => $e) {
                $ref = null;

                foreach ($refs as $r) {
                    if (explode('@', $r)[0] === $e['ruleset']) {
                        $ref = $r;
                        break;
                    }
                }

                $entry['extends'][$i]['matched'] = $ref;
                $entry['extends'][$i]['inRange'] = $ref === null
                    ? null
                    : VersionRange::contains(implode('@', array_slice(explode('@', $ref), 1)), $e['versions']);
            }

            $matched = array_values(array_filter($entry['extends'], static fn (array $e): bool => $e['matched'] !== null));

            if ($matched === []) {
                $list = array_values(array_unique(array_map(static fn (array $e): string => $e['ruleset'] . ' ' . $e['versions'], $entry['extends'])));
                $this->notices[$profile->ref] = [$this->t('notApplied', $profile->ref, implode(', ', $list))];
                $this->entries[] = $entry;
                continue;
            }

            $entry['applied'] = true;
            $this->applied[] = $profile;

            // ⚠️ One notice per rule set, and only when no entry for it fits: two entries are an "or".
            foreach (array_values(array_unique(array_map(static fn (array $e): string => $e['ruleset'], $matched))) as $ruleset) {
                $forIt = array_values(array_filter($matched, static fn (array $e): bool => $e['ruleset'] === $ruleset));
                $fits = array_filter($forIt, static fn (array $e): bool => $e['inRange'] === true);

                if ($fits === []) {
                    $notices[] = $this->t(
                        'version',
                        $profile->ref,
                        $ruleset,
                        implode(' / ', array_map(static fn (array $e): string => $e['versions'], $forIt)),
                        (string) $forIt[0]['matched']
                    );
                }
            }

            $disables = [
                ...array_map(static fn (array $d): array => ['rule' => $d['rule'], 'reason' => $d['reason'], 'replacedBy' => null], $profile->disable),
                ...array_map(static fn (array $r): array => ['rule' => $r['rule'], 'reason' => $r['reason'], 'replacedBy' => $r['by']], $profile->replaces),
            ];

            foreach ($disables as $d) {
                $ids = $idsOf($d['rule']);
                $out = ['rule' => $d['rule'], 'reason' => $d['reason'], 'replacedBy' => $d['replacedBy'], 'rulesets' => $known($ids), 'suppressed' => 0];

                $entry['disabled'][] = $out;
                $at = count($this->disabledEntries);
                $this->disabledEntries[] = ['profile' => count($this->entries), 'entry' => count($entry['disabled']) - 1];

                if ($out['rulesets'] === []) {
                    $notices[] = $this->t('unknownRule', $profile->ref, $d['rule']);
                }

                foreach ($ids as $id) {
                    $this->disabledBy[$id] ??= $at;
                }
            }

            foreach ($profile->severity as $s) {
                $ids = $idsOf($s['rule']);
                $out = ['rule' => $s['rule'], 'level' => $s['level'], 'reason' => $s['reason'], 'rulesets' => $known($ids)];

                $entry['severity'][] = $out;

                if ($out['rulesets'] === []) {
                    $notices[] = $this->t('unknownRule', $profile->ref, $s['rule']);
                }

                // ⚠️ An explicit entry for a sibling spelling wins over the expansion.
                foreach ($ids as $id) {
                    $prev = $this->severityBy[$id] ?? null;

                    if ($prev === null || $prev['explicit'] === false || $id === $s['rule']) {
                        $this->severityBy[$id] = ['level' => $s['level'], 'ref' => $profile->ref, 'explicit' => $id === $s['rule']];
                    }
                }
            }

            $this->notices[$profile->ref] = $notices;
            $this->entries[] = $entry;
        }
    }

    /**
     * What the profiles say about one official finding.
     *
     * @return array{disabled: int|null, level: string|null, ref: string|null} `disabled` is a handle for `countSuppressed()`
     */
    public function official(?string $ruleId): array
    {
        if ($ruleId === null) {
            return ['disabled' => null, 'level' => null, 'ref' => null];
        }

        if (isset($this->disabledBy[$ruleId])) {
            return ['disabled' => $this->disabledBy[$ruleId], 'level' => null, 'ref' => null];
        }

        $s = $this->severityBy[$ruleId] ?? null;

        return $s !== null
            ? ['disabled' => null, 'level' => $s['level'], 'ref' => $s['ref']]
            : ['disabled' => null, 'level' => null, 'ref' => null];
    }

    public function countSuppressed(int $handle): void
    {
        $at = $this->disabledEntries[$handle];
        $this->entries[$at['profile']]['disabled'][$at['entry']]['suppressed']++;
    }

    public function ruleErrorText(string $ref, string $ruleId, string $message): string
    {
        return $this->t('ruleError', $ref, $ruleId, $message);
    }

    /**
     * The report entry of a profile that did not take part.
     *
     * @return array<string, mixed>
     */
    public static function stub(RuleProfile $profile): array
    {
        return [
            'ref' => $profile->ref,
            'name' => $profile->name,
            'version' => $profile->version,
            'title' => $profile->title,
            'applied' => false,
            'extends' => array_map(
                static fn (array $e): array => ['ruleset' => $e['ruleset'], 'versions' => $e['versions'], 'matched' => null, 'inRange' => null],
                $profile->extends
            ),
            'rules' => count($profile->rules),
            'disabled' => [],
            'severity' => [],
        ];
    }

    private function t(string $key, string ...$args): string
    {
        return sprintf(self::TEXTS[$this->lang][$key], ...$args);
    }

    /**
     * @param array<string, Ruleset> $loaded
     * @return array{0: \Closure(string): list<string>, 1: \Closure(list<string>): list<string>}
     */
    private static function siblings(array $loaded): array
    {
        $keyOf = [];
        $idsByKey = [];
        $setsOf = [];

        foreach ($loaded as $ref => $ruleset) {
            foreach ($ruleset->patterns as $pattern) {
                foreach ($pattern->rules as $rule) {
                    foreach ($rule->assertions as $a) {
                        if ($a->id === null) {
                            continue;
                        }

                        if (!in_array($ref, $setsOf[$a->id] ?? [], true)) {
                            $setsOf[$a->id][] = $ref;
                        }

                        if ($a->duplicateKey === null) {
                            continue;
                        }

                        $keyOf[$a->id] ??= $a->duplicateKey;

                        if (!in_array($a->id, $idsByKey[$a->duplicateKey] ?? [], true)) {
                            $idsByKey[$a->duplicateKey][] = $a->id;
                        }
                    }
                }
            }
        }

        $idsOf = static function (string $id) use ($keyOf, $idsByKey): array {
            if (!isset($keyOf[$id])) {
                return [$id];
            }

            return array_values(array_unique([$id, ...$idsByKey[$keyOf[$id]]]));
        };

        $order = array_map('strval', array_keys($loaded));
        $known = static function (array $ids) use ($order, $setsOf): array {
            return array_values(array_filter($order, static function (string $ref) use ($ids, $setsOf): bool {
                foreach ($ids as $id) {
                    if (in_array($ref, $setsOf[$id] ?? [], true)) {
                        return true;
                    }
                }

                return false;
            }));
        };

        return [$idsOf, $known];
    }
}
