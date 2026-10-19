<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Result;

/**
 * Accumulates messages and per-stage status as the pipeline (Task 1.x, see src/Pipeline/) runs.
 * Mutable by design - each stage appends to the same Result as validation proceeds; `isAccepted()`
 * is a pure read over the current state, safe to call at any point, not only at the end.
 */
final class Result
{
    /** @var Message[] */
    private array $messages = [];

    /** @var array<value-of<Stage>, StageStatus> */
    private array $stageStatus;

    /**
     * Name of the scenario the resolver picked, or null when none was resolved (no resolver
     * configured, or no scenario matched). Task 4.3's expectation files pin this down: the same
     * document under the wrong scenario can still be "accepted" and mean nothing.
     */
    private ?string $scenarioName = null;

    /**
     * The rule sets that actually ran, in order. Needed by the KoSIT-shaped report (Task 7.3), which
     * lists one validation step per rule set - including the ones that found nothing. Deriving the
     * list from the messages would silently drop exactly those.
     *
     * @var list<string>
     */
    private array $rulesets = [];

    /** Path or name of the validated document, for the report's documentReference. */
    private ?string $documentReference = null;

    /**
     * One entry per rule profile: applied or not, against which rule set edition, and which official
     * rules it switched off, replaced or re-ranked (with how often they would have fired).
     *
     * @var list<array<string, mixed>>
     */
    private array $profiles = [];

    /**
     * The verdict of the official rule sets alone, with the scenario's own severities and without
     * anything a rule profile changed. Null when no rule profile took part.
     */
    private ?bool $officialAccepted = null;

    /**
     * The rule sets the resolved scenario needs and this package does not carry. Not empty means: there
     * is NO verdict ( `hasVerdict()` ), and the report says "rule set not included".
     *
     * ⚠️ This is what the Peppol switch ( `export-runtime.php --without=peppol` ) relies on. Dropping such
     * scenarios from the export instead reports a valid Peppol invoice in a package without Peppol as
     * "rejected, no scenario". VAMPIRE JS says "no verdict" with the same field name.
     *
     * @var list<string>
     */
    private array $missingRulesets = [];

    /**
     * Sentences the user must see in every report of a check that ran a given rule set ( `notice` of the
     * rule set, FeRD's condition for the ZUGFeRD Schematron ), in the language of the run.
     *
     * @var list<string>
     */
    private array $notices = [];

    /**
     * The per-rule results of the positive validation report, or null when the switch is off
     * ( `Validator`, `ruleReport: true` ). Null keeps the JSON report exactly as it is without the switch.
     */
    private ?RuleReport $ruleReport = null;

    public function __construct()
    {
        $this->stageStatus = [
            Stage::Wellformed->value => StageStatus::NotRun,
            Stage::Xsd->value => StageStatus::NotRun,
            Stage::Syntax->value => StageStatus::NotRun,
            Stage::Business->value => StageStatus::NotRun,
        ];
    }

    public function addMessage(Message $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * Task 5.8a — replaces an already-recorded message in place, keeping its position. Used when a
     * later rule set fires the SAME rule on the SAME node: the existing finding gains one more
     * origin instead of a second finding appearing.
     *
     * ⚠️ Position matters. Appending the merged message at the end would move a finding from the
     * first rule set behind the findings of later ones, and the report's "in Auffindungsreihenfolge"
     * promise would quietly stop being true.
     */
    public function replaceMessage(int $index, Message $message): void
    {
        if (!isset($this->messages[$index])) {
            throw new \OutOfRangeException("no message at index $index");
        }

        $this->messages[$index] = $message;
    }

    public function setStageStatus(Stage $stage, StageStatus $status): void
    {
        $this->stageStatus[$stage->value] = $status;
    }

    public function stageStatus(Stage $stage): StageStatus
    {
        return $this->stageStatus[$stage->value];
    }

    public function setScenarioName(?string $name): void
    {
        $this->scenarioName = $name;
    }

    public function scenarioName(): ?string
    {
        return $this->scenarioName;
    }

    /**
     * @param list<string> $refs
     */
    public function setRulesets(array $refs): void
    {
        $this->rulesets = $refs;
    }

    /** @return list<string> */
    public function rulesets(): array
    {
        return $this->rulesets;
    }

    public function setDocumentReference(?string $reference): void
    {
        $this->documentReference = $reference;
    }

    public function documentReference(): ?string
    {
        return $this->documentReference;
    }

    /**
     * @param list<array<string, mixed>> $profiles
     */
    public function setProfiles(array $profiles): void
    {
        $this->profiles = $profiles;
    }

    /** @return list<array<string, mixed>> */
    public function profiles(): array
    {
        return $this->profiles;
    }

    public function setOfficialAccepted(?bool $accepted): void
    {
        $this->officialAccepted = $accepted;
    }

    public function officialAccepted(): ?bool
    {
        return $this->officialAccepted;
    }

    /**
     * @param list<string> $refs
     */
    public function setMissingRulesets(array $refs): void
    {
        $this->missingRulesets = $refs;
    }

    /** @return list<string> */
    public function missingRulesets(): array
    {
        return $this->missingRulesets;
    }

    /**
     * Whether the run produced a verdict at all. False when the scenario's rule sets are not part of this
     * package; `isAccepted()` is then false as well, and the JSON report writes `accepted: null`.
     */
    public function hasVerdict(): bool
    {
        return $this->missingRulesets === [];
    }

    public function addNotice(string $notice): void
    {
        if ($notice !== '' && !in_array($notice, $this->notices, true)) {
            $this->notices[] = $notice;
        }
    }

    /** @return list<string> */
    public function notices(): array
    {
        return $this->notices;
    }

    public function setRuleReport(?RuleReport $ruleReport): void
    {
        $this->ruleReport = $ruleReport;
    }

    public function ruleReport(): ?RuleReport
    {
        return $this->ruleReport;
    }

    /** @return Message[] */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return Message[] */
    public function messagesForStage(Stage $stage): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (Message $m): bool => $m->stage === $stage
        ));
    }

    /**
     * No Fatal-flag message anywhere, and no stage explicitly Failed. A Skipped or NotRun stage
     * does not by itself block acceptance - that is the caller's (Validator/pipeline) decision via
     * its configured XSD-error mode (`stop` vs `continue`), reflected in stage status.
     */
    public function isAccepted(): bool
    {
        // ⚠️ No verdict is not acceptance. The method stays bool for its many callers ( plugins, the API );
        // a caller that must tell "rejected" from "not checked" asks `hasVerdict()` first.
        if (!$this->hasVerdict()) {
            return false;
        }

        foreach ($this->messages as $message) {
            if ($message->flag->blocksAcceptance()) {
                return false;
            }
        }

        foreach ($this->stageStatus as $status) {
            if ($status === StageStatus::Failed) {
                return false;
            }
        }

        return true;
    }
}
