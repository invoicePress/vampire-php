<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Report;

use EinvoiceValidator\Result\Flag;
use EinvoiceValidator\Result\Message;
use EinvoiceValidator\Result\Result;
use EinvoiceValidator\Result\Stage;

/**
 * Task 7.2 — a printable HTML report, grouped by rule set and severity.
 *
 * ⚠️ Self-contained: the stylesheet is inline, there are no external references. A report is something
 * people mail around and print; one that needs a web server to look right is useless the moment it
 * leaves this machine.
 *
 * ⚠️ Every value from the document or from a rule text goes through `htmlspecialchars()`. Rule texts
 * carry quotes and angle brackets ("Value MUST be <= 100"), and invoice content is by definition
 * foreign input.
 */
final class HtmlReport
{
    public function render(Result $result): string
    {
        $accepted = $result->isAccepted();
        $counts = ['fatal' => 0, 'warning' => 0, 'information' => 0];

        foreach ($result->messages() as $message) {
            $counts[$message->flag->value]++;
        }

        $html = "<!DOCTYPE html>\n<html lang=\"de\">\n<head>\n<meta charset=\"utf-8\">\n";
        $html .= "<title>Prüfbericht</title>\n";
        $html .= "<style>\n" . $this->style() . "</style>\n</head>\n<body>\n";

        $html .= '<h1>Prüfbericht</h1>' . "\n";

        if (!$result->hasVerdict()) {
            // ⚠️ The scenario's rule sets are not in this package: no verdict, and never the red "Abgelehnt".
            $html .= '<p class="verdict none">Kein Urteil: Regelwerk nicht enthalten ('
                . htmlspecialchars(implode(', ', $result->missingRulesets()), ENT_QUOTES) . ")</p>\n";
        } else {
            $html .= '<p class="verdict ' . ($accepted ? 'accept' : 'reject') . '">'
                . ($accepted ? 'Angenommen' : 'Abgelehnt') . "</p>\n";
        }

        // ⚠️ Directly under the verdict: FeRD asks for a statement the user SEES, not one in a file.
        foreach ($result->notices() as $notice) {
            $html .= '<p class="notice">' . htmlspecialchars($notice, ENT_QUOTES) . "</p>\n";
        }

        $html .= "<dl class=\"meta\">\n";
        $html .= $this->definition('Beleg', $result->documentReference() ?? '–');
        $html .= $this->definition('Szenario', $result->scenarioName() ?? 'kein Szenario');
        $html .= $this->definition('Regelwerke', implode(', ', $result->rulesets()) ?: '–');
        $html .= $this->definition(
            'Befunde',
            sprintf('%d sperrend, %d Warnung, %d Hinweis', $counts['fatal'], $counts['warning'], $counts['information'])
        );
        $html .= "</dl>\n";

        $html .= $this->profiles($result);

        $html .= "<h2>Stufen</h2>\n<ul class=\"stages\">\n";

        foreach ([Stage::Wellformed, Stage::Xsd, Stage::Syntax, Stage::Business] as $stage) {
            // StageStatus is a pure enum - `->name` is the only string it carries.
            $status = strtolower($result->stageStatus($stage)->name);
            $html .= sprintf(
                "<li><span class=\"stage-name\">%s</span> <span class=\"status %s\">%s</span></li>\n",
                htmlspecialchars($stage->value, ENT_QUOTES),
                htmlspecialchars($status, ENT_QUOTES),
                htmlspecialchars($status, ENT_QUOTES)
            );
        }

        $html .= "</ul>\n";

        $grouped = [];

        foreach ($result->messages() as $message) {
            $grouped[$message->ruleset][$message->flag->value][] = $message;
        }

        if ($grouped === []) {
            $html .= "<p>Keine Befunde.</p>\n";
        }

        foreach ($grouped as $ruleset => $bySeverity) {
            $html .= '<h2>' . htmlspecialchars((string) $ruleset, ENT_QUOTES) . "</h2>\n";

            // Blocking findings first - a printed report is read from the top.
            foreach (['fatal', 'warning', 'information'] as $severity) {
                if (!isset($bySeverity[$severity])) {
                    continue;
                }

                $html .= '<h3 class="severity ' . $severity . '">' . $this->severityLabel($severity)
                    . ' (' . count($bySeverity[$severity]) . ")</h3>\n";
                $html .= "<table class=\"findings\">\n<thead><tr><th>Regel</th><th>Meldung</th><th>Ort</th></tr></thead>\n<tbody>\n";

                foreach ($bySeverity[$severity] as $message) {
                    $html .= $this->row($message);
                }

                $html .= "</tbody>\n</table>\n";
            }
        }

        return $html . $this->ruleReport($result) . "</body>\n</html>\n";
    }

    /**
     * The section "Geprüfte Regeln" when the positive validation report is switched on
     * ( `new Validator( ruleReport: true )` ). Without the switch this returns '' and the page is the one it
     * was before, byte for byte.
     *
     * ⚠️ GERMAN, LIKE THE REST OF THIS PAGE, AND NOT THE SHARED REPORT VIEW. The engine carries no translation
     * and no dependency on `einvoice-report`; the views of the plugin and the apps render the same data with
     * `RuleReportSection` in three languages. This is the printable fallback of the library itself
     * ( `bin/einvoice-validator validate --format=html --rule-report` ).
     *
     * ⚠️ EVERY LINE OF THE RULE REPORT BECOMES A ROW, "nicht geprüft" INCLUDED, and a rule set that did not run
     * stands in the list with its reason. Hiding a "not checked" or showing it as passed is the one thing the
     * positive report allows no room for.
     */
    private function ruleReport(Result $result): string
    {
        $ruleReport = $result->ruleReport();

        if ($ruleReport === null) {
            return '';
        }

        $rr = $ruleReport->toArray($result);
        $labels = ['passed' => 'erfüllt', 'failed' => 'verletzt', 'not-applicable' => 'nicht anwendbar', 'not-checked' => 'nicht geprüft'];
        $reasons = [
            'stopped' => 'ein früherer Prüfschritt hat die Prüfung beendet',
            'not-included' => 'das Regelwerk ist nicht Teil dieses Pakets',
            'disabled' => 'von einem Bedienerprofil abgeschaltet',
            'error' => 'die Regel konnte nicht ausgewertet werden',
            'not-supported' => 'diese Engine kann den Schritt nicht ausführen',
        ];
        $counts = array_fill_keys(array_keys($labels), 0);

        foreach ($rr['rules'] as $rule) {
            $counts[isset($counts[$rule['result']]) ? $rule['result'] : 'not-checked']++;
        }

        $esc = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES);
        $reason = static fn (?string $r): string => $r === null ? '' : ' (' . htmlspecialchars($reasons[$r] ?? $r, ENT_QUOTES) . ')';

        $html = "<h2>Geprüfte Regeln</h2>\n";
        $html .= '<p class="evidence">Dieser Bericht ist ein technischer Nachweis einer Prüfung gegen die hier genannten '
            . "Regelwerke in ihrer Fassung. Er ist kein Testat.</p>\n";
        $html .= "<dl class=\"meta\">\n";
        $html .= $this->definition('SHA-256', (string) ($rr['document']['sha256'] ?? '') !== '' ? (string) $rr['document']['sha256'] : '–');
        $html .= $this->definition('Zeitpunkt (UTC)', (string) $rr['validatedAt']);
        $html .= $this->definition('Engine', trim($rr['engine']['name'] . ' ' . $rr['engine']['version']));
        $html .= $this->definition('Ergebnisse', sprintf(
            '%d Regeln: %d verletzt, %d nicht geprüft, %d erfüllt, %d nicht anwendbar',
            count($rr['rules']),
            $counts['failed'],
            $counts['not-checked'],
            $counts['passed'],
            $counts['not-applicable']
        ));
        $html .= "</dl>\n<ul class=\"stages\">\n";

        foreach ($rr['steps'] as $step) {
            $html .= '<li><span class="stage-name">' . $esc($step['step']) . '</span> <span class="status '
                . $esc($step['result']) . '">' . $esc($step['result']) . '</span>' . $reason($step['reason']) . "</li>\n";
        }

        $html .= "</ul>\n<ul class=\"rulesets\">\n";

        foreach ($rr['rulesets'] as $set) {
            $html .= '<li>' . $esc($set['ref']) . ' – ' . ($set['ran'] ? 'geprüft' : 'nicht geprüft' . $reason($set['reason']))
                . ($set['license'] !== null ? ' – ' . $esc($set['license']) : '') . "</li>\n";
        }

        $html .= "</ul>\n<table class=\"findings rules\">\n<thead><tr><th>Regel</th><th>Regelwerk</th><th>Ergebnis</th>"
            . "<th>Schwere</th><th>Stellen</th><th>Text</th></tr></thead>\n<tbody>\n";

        foreach ($rr['rules'] as $rule) {
            $result = isset($labels[$rule['result']]) ? $rule['result'] : 'not-checked';
            $html .= sprintf(
                "<tr class=\"%s\"><td class=\"rule\">%s</td><td class=\"rule\">%s</td><td>%s%s</td><td>%s</td><td>%d</td><td>%s</td></tr>\n",
                $esc($result),
                $esc($rule['key']),
                $esc($rule['ruleset']),
                $esc($labels[$result]),
                $result === 'not-checked' ? $reason($rule['reason']) : '',
                $esc($rule['severity']),
                (int) $rule['checked'],
                $esc($rule['text'])
            );
        }

        return $html . "</tbody>\n</table>\n";
    }

    private function row(Message $message): string
    {
        $fields = $message->bt === [] ? '' : ' <span class="fields">' . htmlspecialchars(implode(', ', $message->bt), ENT_QUOTES) . '</span>';

        // The finding is listed once, under the rule set that reported it first. The other rule sets that
        // contain the very same rule are named here - leaving them out would look like only one rule set
        // had anything to say about this node.
        $origins = $message->origins();
        $also = count($origins) < 2
            ? ''
            : ' <span class="origins">auch in: '
                . htmlspecialchars(implode(', ', array_slice($origins, 1)), ENT_QUOTES) . '</span>';

        // An official finding a rule profile re-ranked says so where it stands.
        $reranked = $message->severityBy === null
            ? ''
            : ' <span class="origins">umgestuft von Regelprofil ' . htmlspecialchars($message->severityBy, ENT_QUOTES)
                . ' (im Regelwerk: ' . htmlspecialchars($message->nativeFlag()->value, ENT_QUOTES) . ')</span>';

        return sprintf(
            "<tr><td class=\"rule\">%s</td><td>%s%s%s%s</td><td class=\"where\">%s</td></tr>\n",
            htmlspecialchars($message->ruleId ?? '–', ENT_QUOTES),
            htmlspecialchars($message->text, ENT_QUOTES),
            $fields,
            $also,
            $reranked,
            htmlspecialchars($message->location ?? '–', ENT_QUOTES)
        );
    }

    /**
     * The rule profiles, BEFORE the findings: whoever reads the verdict must already know that a customer
     * profile changed what counts. Every official rule a profile switched off or replaced is listed with its
     * reason and how often it would have fired; it is never just absent.
     */
    private function profiles(Result $result): string
    {
        if ($result->profiles() === []) {
            return '';
        }

        $html = "<h2>Regelprofile</h2>\n<ul class=\"profiles\">\n";

        foreach ($result->profiles() as $p) {
            $html .= '<li><strong>' . htmlspecialchars((string) $p['ref'], ENT_QUOTES) . '</strong> ';
            $html .= $p['applied']
                ? htmlspecialchars(sprintf('%d eigene Regeln', $p['rules']), ENT_QUOTES)
                : 'gilt nicht für dieses Szenario';

            if ($p['disabled'] !== [] || $p['severity'] !== []) {
                $html .= "\n<ul>\n";

                foreach ($p['disabled'] as $d) {
                    $html .= '<li>' . htmlspecialchars(sprintf(
                        '%s %s – %s; hätte %d-mal angeschlagen',
                        $d['rule'],
                        $d['replacedBy'] !== null ? 'ersetzt durch ' . $d['replacedBy'] : 'abgeschaltet',
                        (string) $d['reason'],
                        $d['suppressed']
                    ), ENT_QUOTES) . "</li>\n";
                }

                foreach ($p['severity'] as $s) {
                    $html .= '<li>' . htmlspecialchars(sprintf(
                        '%s umgestuft auf %s%s',
                        $s['rule'],
                        $s['level'],
                        $s['reason'] !== null ? ' – ' . $s['reason'] : ''
                    ), ENT_QUOTES) . "</li>\n";
                }

                $html .= "</ul>\n";
            }

            $html .= "</li>\n";
        }

        $html .= "</ul>\n";

        if ($result->officialAccepted() !== null) {
            $html .= '<p>Amtliche Regelwerke allein: ' . ($result->officialAccepted() ? 'angenommen' : 'abgelehnt') . "</p>\n";
        }

        return $html;
    }

    private function definition(string $term, string $value): string
    {
        return '<dt>' . htmlspecialchars($term, ENT_QUOTES) . '</dt><dd>'
            . htmlspecialchars($value, ENT_QUOTES) . "</dd>\n";
    }

    private function severityLabel(string $severity): string
    {
        return match ($severity) {
            'fatal' => 'Sperrende Befunde',
            'warning' => 'Warnungen',
            default => 'Hinweise',
        };
    }

    private function style(): string
    {
        return <<<'CSS'
            body { font-family: system-ui, sans-serif; margin: 2rem; color: #111; }
            h1 { font-size: 1.6rem; margin-bottom: .2rem; }
            h2 { font-size: 1.1rem; margin-top: 2rem; border-bottom: 1px solid #ddd; padding-bottom: .2rem; }
            h3 { font-size: 1rem; margin: 1.2rem 0 .4rem; }
            .verdict { display: inline-block; padding: .3rem .8rem; border-radius: .2rem; font-weight: 600; }
            .verdict.accept { background: #e6f4ea; color: #14532d; }
            .verdict.reject { background: #fdeaea; color: #7f1d1d; }
            .verdict.none { background: #eee; color: #333; }
            p.notice { color: #444; font-size: .9rem; }
            dl.meta dt { float: left; width: 12rem; font-weight: 600; }
            dl.meta dd { margin: 0 0 .3rem 12rem; }
            ul.stages { list-style: none; padding: 0; }
            ul.stages li { display: inline-block; margin-right: 1.5rem; }
            .status { padding: .1rem .5rem; border-radius: .2rem; background: #eee; }
            .status.failed { background: #fdeaea; color: #7f1d1d; }
            .status.passed { background: #e6f4ea; color: #14532d; }
            table.findings { border-collapse: collapse; width: 100%; font-size: .9rem; }
            table.findings th, table.findings td { border: 1px solid #ddd; padding: .35rem .5rem; text-align: left; vertical-align: top; }
            table.findings th { background: #f6f6f6; }
            td.rule { white-space: nowrap; font-family: ui-monospace, monospace; }
            td.where { font-family: ui-monospace, monospace; font-size: .8rem; word-break: break-all; }
            .fields { color: #555; font-size: .85em; }
            .origins { display: block; color: #666; font-size: .8em; margin-top: .15rem; }
            h3.severity.fatal { color: #7f1d1d; }
            p.evidence { border-left: 3px solid #175cd3; background: #eff8ff; padding: .4rem .8rem; }
            .status.not-checked, table.rules tr.not-checked td { background: #fffaeb; color: #7a2e0e; }
            table.rules tr.failed td { background: #fdeaea; }
            ul.rulesets { padding-left: 1.2rem; font-size: .9rem; }
            @media print { body { margin: 0; } h2 { page-break-after: avoid; } table.findings { page-break-inside: auto; } }
            CSS;
    }
}
