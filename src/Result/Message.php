<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Result;

/**
 * One rule violation / notice, from any stage (wellformed, XSD, syntax rules, business rules).
 *
 * @see docs/notes/decisions.md for why `text` keeps the rule's original wording (Regel A.9: "Regel-
 *      Meldungen im Originaltext des Regelwerks") including any embedded "[BR-DE-1]"-style prefix
 *      the rule author already put there - this class does not strip or rewrite it.
 */
final readonly class Message
{
    /**
     * @param string[] $bt Affected BT/BG field references, e.g. ["BG-16", "BT-122"]. Empty when
     *                      the rule text does not name any (see field_resolution in the imported
     *                      ruleset, not every rule carries this).
     * @param string[] $rulesets EVERY rule set this one finding came out of, in the order the rule sets
     *                      ran. The same rule lives in several of them (UBL-CR-400 in six), and
     *                      reporting it six times would say "six problems" where there is one. Empty
     *                      means "just `$ruleset`"; `origins()` hides that distinction.
     * @param ?Flag $ruleFlag The severity the RULE SET itself gives this rule, where a scenario's
     *                      `customLevel` or a profile changed it. `null` means "same as `$flag`". See
     *                      `nativeFlag()` for why this distinction exists.
     * @param ?string $profile `name@version` of the rule profile this finding comes from (its own rule or
     *                      one of its notices); null for everything else.
     * @param ?string $severityBy `name@version` of the rule profile that re-ranked this OFFICIAL finding.
     *                      ⚠️ A report whose strictness a customer changed must say so at the finding,
     *                      not only in a list at the end.
     */
    public function __construct(
        public Stage $stage,
        public ?string $ruleId,
        public Flag $flag,
        public string $text,
        public ?string $location,
        public ?int $line,
        public string $ruleset,
        public array $bt = [],
        public array $rulesets = [],
        public ?Flag $ruleFlag = null,
        public ?string $profile = null,
        public ?string $severityBy = null,
    ) {
    }

    /**
     * Where a finding comes from: `official` (a rule set), `profile` (a rule profile) or `validator`
     * (the pipeline itself: well-formedness, schema, scenario, the operator profile's refusals).
     *
     * ⚠️ Derived, not stored, so that the dozen places that build a `Message` cannot get it wrong. The
     * pipeline's own pseudo rule sets are a closed list; everything else that carries a rule set name
     * is an official rule set.
     */
    public function origin(): string
    {
        if ($this->profile !== null) {
            return 'profile';
        }

        return in_array($this->ruleset, ['wellformed', 'xsd', 'scenario', 'profile'], true) ? 'validator' : 'official';
    }

    /**
     * The severity the rule set gives this rule, before a scenario's `customLevel` or a profile had
     * its say. `$flag` is the one that counts for acceptance.
     *
     * ⚠️ **Measured against 208 reports of the real KoSIT validator (Task 4.6): its `level` attribute
     * is this one, not the effective severity.** A CVD document shows `BR-CL-13` as `error` and is
     * accepted all the same, because the scenario downgrades the rule for the VERDICT while the
     * message keeps the rule's own severity. Looks contradictory in the report and is exactly what
     * the reference implementation does - so the KoSIT-shaped report does it too, or it would not be
     * the same report.
     */
    public function nativeFlag(): Flag
    {
        return $this->ruleFlag ?? $this->flag;
    }

    /**
     * Every rule set this finding came from - one entry for an ordinary finding, several for a merged
     * one.
     *
     * ⚠️ `$ruleset` stays the FIRST origin. Readers that know only that field keep working and see the
     * rule set that reported first, which is the one the scenario runs earliest.
     *
     * @return string[]
     */
    public function origins(): array
    {
        return $this->rulesets !== [] ? $this->rulesets : [$this->ruleset];
    }

    /**
     * The same finding, with one more rule set credited. `Message` is readonly, so this returns a new
     * instance instead of mutating.
     */
    public function withOrigin(string $ruleset): self
    {
        $origins = $this->origins();

        if (in_array($ruleset, $origins, true)) {
            return $this;
        }

        $origins[] = $ruleset;

        return new self(
            $this->stage,
            $this->ruleId,
            $this->flag,
            $this->text,
            $this->location,
            $this->line,
            $this->ruleset,
            $this->bt,
            $origins,
            $this->ruleFlag,
            $this->profile,
            $this->severityBy,
        );
    }
}
