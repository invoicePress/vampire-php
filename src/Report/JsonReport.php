<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Report;

use EinvoiceValidator\Result\Message;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;

/**
 * Task 7.1 — the JSON report. Schema and stability promise: docs/developer/report-formats.md.
 *
 * ⚠️ The shape is flat on purpose: one list of findings, each carrying its own stage, rule set and
 * severity, rather than a tree grouped by stage. Consumers group differently (by severity, by business
 * term, by rule set), and a tree forces every one of them to flatten it again first.
 */
final class JsonReport
{
    /**
     * 1.1 (Task 5.8a): a finding carries `rulesets` — a LIST of origins — where 1.0 had the single
     * `ruleset`. The same rule lives in up to six rule sets; reporting it six times said "six
     * problems" where there is one.
     *
     * ⚠️ `ruleset` is gone, not kept alongside. A reader that ignored the version and kept reading
     * `ruleset` would silently see a rule set for every finding and never learn about the others,
     * which is exactly the misunderstanding the version bump exists to prevent.
     */
    public const SCHEMA_VERSION = '1.1';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Result $result): array
    {
        $report = [
            'schemaVersion' => self::SCHEMA_VERSION,
            // ⚠️ null = no verdict, because the scenario's rule sets are not in this package
            // ( `missingRulesets` ). Same value as VAMPIRE JS. Never false: "rejected" would be a claim
            // about an invoice nobody checked.
            'accepted' => $result->hasVerdict() ? $result->isAccepted() : null,
            'scenario' => $result->scenarioName(),
            'rulesets' => $result->rulesets(),
            'document' => $result->documentReference(),
            // ⚠️ StageStatus is a pure enum without a backing value; `->name` is the only string it
            // has. Lower-cased here so the report keeps its own stable vocabulary
            // ("notrun"/"passed"/"failed"/"skipped") rather than exposing PHP case spelling.
            'stages' => [
                'wellformed' => strtolower($result->stageStatus(Stage::Wellformed)->name),
                'xsd' => strtolower($result->stageStatus(Stage::Xsd)->name),
                'syntax' => strtolower($result->stageStatus(Stage::Syntax)->name),
                'business' => strtolower($result->stageStatus(Stage::Business)->name),
            ],
            'counts' => $this->counts($result),
            'findings' => array_map($this->finding(...), $result->messages()),
            // New fields within 1.1 (the stability promise allows additions). ⚠️ Both are always present,
            // `profiles` as an empty list: "no profile" must be distinguishable from "a report written
            // before rule profiles existed".
            'profiles' => $result->profiles(),
            'officialAccepted' => $result->officialAccepted(),
            // Two more additions within 1.1, both always present.
            'missingRulesets' => $result->missingRulesets(),
            // ⚠️ A licence condition ( FeRD ): whoever shows this report must show these sentences too.
            'notices' => $result->notices(),
        ];

        // The positive validation report, only when the switch was on.
        //
        // ⚠️ ABSENT, NOT NULL, WITH THE SWITCH OFF. The report without the switch must stay as it was; a new
        // key with `null` would already be a change for a reader that compares reports byte for byte
        // ( the golden fixtures, the parity recordings of VAMPIRE JS ).
        $ruleReport = $result->ruleReport();

        if ($ruleReport !== null) {
            $report['ruleReport'] = $ruleReport->toArray($result);
        }

        return $report;
    }

    public function toJson(Result $result, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($this->toArray($result), $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }

    /**
     * @return array<string, int>
     */
    private function counts(Result $result): array
    {
        $counts = ['fatal' => 0, 'warning' => 0, 'information' => 0];

        foreach ($result->messages() as $message) {
            $counts[$message->flag->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(Message $message): array
    {
        return [
            'stage' => $message->stage->value,
            'rule' => $message->ruleId,
            'rulesets' => $message->origins(),
            'severity' => $message->flag->value,
            'ruleSeverity' => $message->nativeFlag()->value,
            'text' => $message->text,
            'location' => $message->location,
            'line' => $message->line,
            'fields' => $message->bt,
            'origin' => $message->origin(),
            'profile' => $message->profile,
            'severityBy' => $message->severityBy,
        ];
    }
}
