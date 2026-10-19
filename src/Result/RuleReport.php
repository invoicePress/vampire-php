<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Result;

use EinvoiceValidator\Ruleset\Assertion;
use EinvoiceValidator\Ruleset\MessageSegment;
use EinvoiceValidator\Ruleset\Ruleset;

/**
 * The positive validation report: one line per rule of every rule set the run used, each with one of four
 * results, plus the header that identifies what was checked ( rule sets with edition, time of the check,
 * SHA-256 of the checked file ).
 *
 * Switched on with `new Validator( ruleReport: true )`; off by default. With the switch off nothing here
 * is created, and `JsonReport` writes exactly the report it writes without the switch ( no `ruleReport` key
 * at all ). The twin is `RuleReport` in VAMPIRE JS; both must list the same rules with the same results
 * for the same document, which a parity test checks.
 *
 * The four results, as keys of the report ( English like every key; the German words are display texts and
 * live in the presentation ):
 *
 *   - `passed`         the rule's context matched at least one node, and every evaluation held;
 *   - `failed`         at least one evaluation fired ( a failed `assert` or a firing `report` ), whatever
 *                      the severity - a warning that fires is a violated rule, it just does not reject;
 *   - `not-applicable` the context matched no node of this document, so the rule had nothing to say;
 *   - `not-checked`    the engine did not evaluate the rule: the run stopped before the rule sets, the rule
 *                      set is not part of this package, an operator profile switched the rule off, or a
 *                      rule of a rule profile threw. The `reason` field says which.
 *
 * ⚠️ `not-checked` IS NEVER DROPPED AND NEVER FOLDED INTO `passed`. This is the one requirement of the positive
 * report without room for interpretation: a rule the engine did not execute must appear as such. Two traps
 * follow from it. (1) A rule whose counters are zero is `not-applicable` only if its rule set actually RAN; when
 * the run stopped at the schema, the same zero means "never looked", and `notRun()` forces those lines to
 * `not-checked` before anything is computed. (2) A rule set that is missing from the package has no rules
 * this code could list; it appears as a rule set entry with `ran: false` and as the step `rules`, never as
 * silence.
 *
 * ⚠️ ONE LINE PER RULE, NOT PER ASSERTION AND NOT PER LOCATION. Several rule sets carry one id on two or
 * more assertions ( CIUS-RS has 46 ids on 67 assertions, measured 09.10.2026 ), typically one per context.
 * They are one rule: the line is `failed` if any of them fired, `passed` if any of them was evaluated and
 * none fired. The same id in two DIFFERENT rule sets stays two lines, because the report says which rule
 * set in which edition was applied, and both were.
 *
 * ⚠️ ASSERTIONS WITHOUT AN ID GET A POSITIONAL KEY ( `#<pattern>.<rule>.<assertion>`, 0-based ). The
 * Factur-X rule sets have 521 of them in EXTENDED alone ( the "marked as not used" reports ). Leaving them
 * out would understate what was checked; giving them an invented id would be a claim the rule set does not
 * make. The position is stable for a given rule set edition, and VAMPIRE JS walks the same file in the same
 * order.
 */
final class RuleReport
{
    public const FORMAT_VERSION = 1;

    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const NOT_APPLICABLE = 'not-applicable';
    public const NOT_CHECKED = 'not-checked';

    /** An earlier step ended the run before this one ( not well-formed, no scenario, schema failed, a rule set missing ). */
    public const REASON_STOPPED = 'stopped';
    /** The rule set is not part of this package. */
    public const REASON_NOT_INCLUDED = 'not-included';
    /** This engine does not have the step ( VAMPIRE JS and the schema check ). */
    public const REASON_NOT_SUPPORTED = 'not-supported';
    /** Nothing was configured for the step ( a validator without scenario resolution, a scenario without schema ). */
    public const REASON_NOT_CONFIGURED = 'not-configured';
    /** Switched off by the operator profile ( `Profile::$disable` ) and therefore never evaluated. */
    public const REASON_DISABLED = 'disabled';
    /** The rule threw while it was evaluated ( today only rules of a rule profile, which may not stop the run ). */
    public const REASON_ERROR = 'error';
    /** The schema stage was configured to run and was skipped. */
    public const REASON_SKIPPED = 'skipped';

    private const RANK = ['information' => 1, 'warning' => 2, 'fatal' => 3];

    /** @var \SplObjectStorage<Assertion, int> assertion => index into `$lines` */
    private \SplObjectStorage $lineOf;

    /**
     * @var list<array{ruleset: string, rule: ?string, key: string, ruleSeverity: string, severity: string,
     *                 text: string, checked: int, failed: int, forced: ?string}>
     */
    private array $lines = [];

    /** @var array<string, int> "<ref>\0<key>" => index into `$lines` */
    private array $lineIndex = [];

    /**
     * @var array<string, array{ref: string, id: string, version: ?string, title: ?string, license: ?string,
     *                          sourceSha256: ?string, ran: bool, reason: ?string}>
     */
    private array $rulesets = [];

    private ?string $scenarioId = null;

    private bool $scenarioConfigured = true;

    private ?string $syntax = null;

    private ?string $specificationId = null;

    public function __construct(
        public readonly string $engineName,
        public readonly string $engineVersion,
        public readonly ?string $documentName,
        /** SHA-256 of the bytes that were validated, lower-case hex; null when the document arrived as a DOM. */
        public readonly ?string $sha256,
        public readonly ?int $bytes,
        /** ISO 8601 in UTC, e.g. 2026-10-09T15:14:18Z. */
        public readonly string $validatedAt,
    ) {
        $this->lineOf = new \SplObjectStorage();
    }

    /**
     * Syntax and specification identifier ( BT-24 ) of the document, read through the DOM.
     *
     * ⚠️ READ HERE, NOT FROM THE SCENARIO. The scenario says what the validator decided to apply; the
     * header must also say what the document claims to be, and the two differ exactly in the cases a
     * reader of the report needs to see ( an EN 16931 invoice checked without a matching scenario ).
     * The paths are fixed and short, the same as in VAMPIRE JS ( `readDocument()` there ).
     */
    public function readDocument(\DOMDocument $document): void
    {
        $root = $document->documentElement;

        if ($root === null) {
            return;
        }

        $ns = (string) $root->namespaceURI;

        if (str_starts_with($ns, 'urn:oasis:names:specification:ubl:schema:xsd:')) {
            $this->syntax = 'UBL';
            $this->specificationId = self::childText($root, 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'CustomizationID');

            return;
        }

        if ($ns === 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100') {
            $this->syntax = 'CII';
            $ram = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
            $context = self::childElement($root, $ns, 'ExchangedDocumentContext');
            $guideline = $context !== null ? self::childElement($context, $ram, 'GuidelineSpecifiedDocumentContextParameter') : null;
            $this->specificationId = $guideline !== null ? self::childText($guideline, $ram, 'ID') : null;
        }
    }

    public function setScenario(?string $id, bool $configured = true): void
    {
        $this->scenarioId = $id;
        $this->scenarioConfigured = $configured;
    }

    /**
     * Registers every rule of a rule set, BEFORE it runs.
     *
     * ⚠️ BEFORE, NOT AFTER. The engine reports evaluations by assertion object; an assertion that is not
     * registered yet would be counted nowhere, and a rule that passed would come out as `not-applicable`.
     *
     * @param callable(?string, Flag): string $severityOf the effective severity of a rule id, given its own
     *        flag: the scenario's re-ranking, the operator profile and a rule profile, in that order -
     *        exactly the chain a finding of that rule goes through in `Validator::runRulesets()`
     * @param list<string>|null $onlyRules a rule set switched on by the operator profile for some rules
     *        only: the other rules are not part of the check and are not listed
     * @param list<string> $disabled rule ids the operator profile switched off; never evaluated
     */
    public function addRuleset(string $ref, Ruleset $ruleset, callable $severityOf, ?array $onlyRules = null, array $disabled = [], bool $isProfile = false): void
    {
        if (!isset($this->rulesets[$ref])) {
            [$id, $version] = self::splitRef($ref);

            $this->rulesets[$ref] = [
                'ref' => $ref,
                'id' => $id,
                'version' => $version,
                // ⚠️ A rule profile is not a rule set with a source file; its per-rule wrappers carry no
                // title, licence or checksum of their own, and VAMPIRE JS builds them differently.
                'title' => $isProfile ? null : $ruleset->title,
                'license' => $isProfile ? null : $ruleset->license,
                'sourceSha256' => $isProfile ? null : $ruleset->sourceHash,
                'ran' => false,
                'reason' => null,
            ];
        }

        foreach ($ruleset->patterns as $p => $pattern) {
            foreach ($pattern->rules as $r => $rule) {
                foreach ($rule->assertions as $a => $assertion) {
                    $id = $assertion->id !== null && $assertion->id !== '' ? $assertion->id : null;

                    if ($onlyRules !== null && ($id === null || !in_array($id, $onlyRules, true))) {
                        continue;
                    }

                    $key = $id ?? '#' . $p . '.' . $r . '.' . $a;
                    $own = $assertion->flag->value;
                    $effective = $severityOf($id, $assertion->flag);
                    $at = $this->lineIndex[$ref . "\0" . $key] ?? null;

                    if ($at === null) {
                        $this->lines[] = [
                            'ruleset' => $ref,
                            'rule' => $id,
                            'key' => $key,
                            'ruleSeverity' => $own,
                            'severity' => $effective,
                            'text' => self::staticText($assertion->message),
                            'checked' => 0,
                            'failed' => 0,
                            'forced' => $id !== null && in_array($id, $disabled, true) ? self::REASON_DISABLED : null,
                        ];
                        $at = count($this->lines) - 1;
                        $this->lineIndex[$ref . "\0" . $key] = $at;
                    } else {
                        // One id on several assertions: the line carries the highest severity among them.
                        if (self::RANK[$own] > self::RANK[$this->lines[$at]['ruleSeverity']]) {
                            $this->lines[$at]['ruleSeverity'] = $own;
                        }

                        if ((self::RANK[$effective] ?? 3) > (self::RANK[$this->lines[$at]['severity']] ?? 3)) {
                            $this->lines[$at]['severity'] = $effective;
                        }
                    }

                    $this->lineOf[$assertion] = $at;
                }
            }
        }
    }

    /** A rule set of the scenario that this package does not carry: no rules to list, one entry that says so. */
    public function rulesetNotIncluded(string $ref): void
    {
        [$id, $version] = self::splitRef($ref);

        $this->rulesets[$ref] = [
            'ref' => $ref,
            'id' => $id,
            'version' => $version,
            'title' => null,
            'license' => null,
            'sourceSha256' => null,
            'ran' => false,
            'reason' => self::REASON_NOT_INCLUDED,
        ];
    }

    /** Called by `Engine` for every evaluation of an assertion on one context node. */
    public function evaluated(Assertion $assertion): void
    {
        if ($this->lineOf->contains($assertion)) {
            $this->lines[$this->lineOf[$assertion]]['checked']++;
        }
    }

    /** Called by `Engine` when that evaluation fired. */
    public function fired(Assertion $assertion): void
    {
        if ($this->lineOf->contains($assertion)) {
            $this->lines[$this->lineOf[$assertion]]['failed']++;
        }
    }

    public function markRan(string $ref): void
    {
        if (isset($this->rulesets[$ref])) {
            $this->rulesets[$ref]['ran'] = true;
        }
    }

    /** Every rule of this rule set object could not be evaluated ( a rule of a rule profile threw ). */
    public function failedToRun(Ruleset $ruleset): void
    {
        foreach ($ruleset->patterns as $pattern) {
            foreach ($pattern->rules as $rule) {
                foreach ($rule->assertions as $assertion) {
                    if ($this->lineOf->contains($assertion)) {
                        $this->lines[$this->lineOf[$assertion]]['forced'] = self::REASON_ERROR;
                    }
                }
            }
        }
    }

    /**
     * The run stopped before the registered rule sets ran: every one of their rules is `not-checked`.
     *
     * ⚠️ Without this call the zero counters would read as `not-applicable` - "this rule had nothing to
     * check in your invoice" about a rule nobody evaluated.
     */
    public function notRun(string $reason): void
    {
        foreach ($this->rulesets as $ref => $entry) {
            if (!$entry['ran'] && $entry['reason'] === null) {
                $this->rulesets[$ref]['reason'] = $reason;
            }
        }

        foreach ($this->lines as $i => $line) {
            if (!$this->rulesets[$line['ruleset']]['ran'] && $line['forced'] === null) {
                $this->lines[$i]['forced'] = $reason;
            }
        }
    }

    /**
     * The `ruleReport` section of the JSON report.
     *
     * @return array<string, mixed>
     */
    public function toArray(Result $result): array
    {
        $rules = [];
        $counts = [self::PASSED => 0, self::FAILED => 0, self::NOT_APPLICABLE => 0, self::NOT_CHECKED => 0];
        $rulesPerSet = [];

        foreach ($this->lines as $line) {
            $outcome = match (true) {
                $line['forced'] !== null => self::NOT_CHECKED,
                $line['failed'] > 0 => self::FAILED,
                $line['checked'] > 0 => self::PASSED,
                default => self::NOT_APPLICABLE,
            };

            $counts[$outcome]++;
            $rulesPerSet[$line['ruleset']] = ($rulesPerSet[$line['ruleset']] ?? 0) + 1;

            $rules[] = [
                'ruleset' => $line['ruleset'],
                'rule' => $line['rule'],
                'key' => $line['key'],
                'result' => $outcome,
                'severity' => $line['severity'],
                'ruleSeverity' => $line['ruleSeverity'],
                'text' => $line['text'],
                'checked' => $line['checked'],
                'failed' => $line['failed'],
                'reason' => $outcome === self::NOT_CHECKED ? $line['forced'] : null,
            ];
        }

        $rulesets = [];

        foreach ($this->rulesets as $ref => $entry) {
            $entry['rules'] = $entry['reason'] === self::REASON_NOT_INCLUDED ? null : ($rulesPerSet[$ref] ?? 0);
            $rulesets[] = $entry;
        }

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'validatedAt' => $this->validatedAt,
            'engine' => ['name' => $this->engineName, 'version' => $this->engineVersion],
            'document' => [
                'name' => $this->documentName,
                'sha256' => $this->sha256,
                'bytes' => $this->bytes,
                'syntax' => $this->syntax,
                'specificationId' => $this->specificationId,
            ],
            'scenario' => ['id' => $this->scenarioId, 'name' => $result->scenarioName()],
            'steps' => $this->steps($result),
            'rulesets' => $rulesets,
            'counts' => $counts,
            'rules' => $rules,
        ];
    }

    /**
     * The cascade with "ran or not" per step.
     *
     * ⚠️ THE ORDER IS THE ORDER OF THE RUN: scenario resolution comes BEFORE the schema, because the
     * scenario picks the schema. A report that listed the schema first would describe a run that never
     * happened that way.
     *
     * @return list<array{step: string, result: string, reason: ?string}>
     */
    private function steps(Result $result): array
    {
        $wellformed = $result->stageStatus(Stage::Wellformed);
        $anyRan = false;
        $anyMissing = false;

        foreach ($this->rulesets as $entry) {
            $anyRan = $anyRan || $entry['ran'];
            $anyMissing = $anyMissing || $entry['reason'] === self::REASON_NOT_INCLUDED;
        }

        $steps = [];
        $steps[] = $wellformed === StageStatus::Passed
            ? self::step('wellformed', self::PASSED)
            : ($wellformed === StageStatus::Failed ? self::step('wellformed', self::FAILED) : self::step('wellformed', self::NOT_CHECKED, self::REASON_STOPPED));

        $stoppedEarly = $wellformed !== StageStatus::Passed;

        if ($stoppedEarly) {
            $steps[] = self::step('scenario', self::NOT_CHECKED, self::REASON_STOPPED);
        } elseif (!$this->scenarioConfigured) {
            $steps[] = self::step('scenario', self::NOT_CHECKED, self::REASON_NOT_CONFIGURED);
        } elseif ($result->scenarioName() !== null) {
            $steps[] = self::step('scenario', self::PASSED);
        } else {
            $steps[] = self::step('scenario', self::FAILED);
            $stoppedEarly = true;
        }

        $stoppedEarly = $stoppedEarly || $anyMissing;

        $steps[] = match ($result->stageStatus(Stage::Xsd)) {
            StageStatus::Passed => self::step('schema', self::PASSED),
            StageStatus::Failed => self::step('schema', self::FAILED),
            StageStatus::Skipped => self::step('schema', self::NOT_CHECKED, self::REASON_SKIPPED),
            StageStatus::NotRun => self::step('schema', self::NOT_CHECKED, $stoppedEarly ? self::REASON_STOPPED : self::REASON_NOT_CONFIGURED),
        };

        if ($anyRan) {
            $failed = $result->stageStatus(Stage::Syntax) === StageStatus::Failed
                || $result->stageStatus(Stage::Business) === StageStatus::Failed;
            $steps[] = self::step('rules', $failed ? self::FAILED : self::PASSED);
        } else {
            $steps[] = self::step('rules', self::NOT_CHECKED, $anyMissing ? self::REASON_NOT_INCLUDED : self::REASON_STOPPED);
        }

        return $steps;
    }

    /** @return array{step: string, result: string, reason: ?string} */
    private static function step(string $step, string $result, ?string $reason = null): array
    {
        return ['step' => $step, 'result' => $result, 'reason' => $reason];
    }

    /**
     * The rule's message without the values of a particular document: literal parts as written, every
     * computed part as "…", runs of white space collapsed to one space.
     *
     * ⚠️ AN EXPLICIT CLASS, AND THE SAME ONE IN VAMPIRE JS: space, tab, CR, LF and the no-break space U+00A0.
     * JavaScript's `\s` and `trim()` take every Unicode space, PCRE's `\s` without the UCP flag only ASCII;
     * with `\s` on both sides a rule text would differ between the engines and break the parity for nothing.
     * U+00A0 is in the class because the EN 16931 rule sets really carry it: BR-01 ends in ". \u{A0} \u{A0}",
     * measured on 09.10.2026, and without it the text kept three invisible characters at its end.
     *
     * @param MessageSegment[] $segments
     */
    private static function staticText(array $segments): string
    {
        $text = '';

        foreach ($segments as $segment) {
            $text .= $segment->isLiteral() ? $segment->literal : '…';
        }

        return trim((string) preg_replace('/[ \t\r\n\x{00A0}]+/u', ' ', $text), ' ');
    }

    /** @return array{0: string, 1: ?string} id and edition of a reference like `EN16931-UBL@1.3.16` */
    private static function splitRef(string $ref): array
    {
        $at = strrpos($ref, '@');

        return $at === false ? [$ref, null] : [substr($ref, 0, $at), substr($ref, $at + 1)];
    }

    private static function childElement(\DOMElement $parent, string $ns, string $local): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->namespaceURI === $ns && $child->localName === $local) {
                return $child;
            }
        }

        return null;
    }

    private static function childText(\DOMElement $parent, string $ns, string $local): ?string
    {
        $child = self::childElement($parent, $ns, $local);

        return $child !== null ? trim($child->textContent, " \t\r\n") : null;
    }
}
