<?php

// SPDX-License-Identifier: GPL-2.0-or-later

declare(strict_types=1);

namespace EinvoiceValidator\Cascade;

/**
 * The shape of a cascade result, built from JSON reports ( `JsonReport::toArray()` ).
 *
 * ⚠️ **BUILT FROM THE REPORT ARRAYS, NOT FROM `Result` OBJECTS, ON PURPOSE.** VAMPIRE JS has no `Result` class;
 * its reports are the same arrays. Building the matrix from the shared vocabulary is what lets
 * `src/Cascade.mjs` be a line-by-line twin of this file, and the parity test compare `toArray()` of both.
 *
 * ⚠️ **THE TEXTS STAND HERE AND IN `src/Cascade.mjs`, WORD FOR WORD.** The reason is part of the result, and the
 * parity comparison includes it; a reworded sentence on one side is a parity failure, not a style change.
 */
final class CascadeResult
{
    /**
     * @param array<string, mixed>|null $edition
     * @param array<string, mixed>      $report a JSON report
     * @return array<string, mixed>
     */
    public static function step(?array $edition, ?string $scenarioId, array $report, int $ms): array
    {
        $fatal = [];
        $warning = [];

        foreach ($report['findings'] as $f) {
            // ⚠️ A schema finding has no rule id; it is one key `XSD`, because the number of schema messages
            // depends on the schema checker ( libxml2 here, Xerces at KoSIT, a structural check in JS ) and the
            // matrix must not differ by tool where the invoice does not differ.
            //
            // ⚠️ A RULE WITHOUT AN ID IS KEYED BY ITS TEXT. The Schematron of 2.1 to 2.3.3 leaves many assertions
            // without an id; a key like "#business" would make every one of them the same rule, and the matrix
            // would hide that 2.1 and 2.2 fire different ones.
            $key = $f['rule'] ?? ($f['stage'] === 'xsd' ? 'XSD' : self::textKey((string) $f['text']));

            if ($f['severity'] === 'fatal') {
                $fatal[$key] = true;
            } elseif ($f['severity'] === 'warning') {
                $warning[$key] = true;
            }
        }

        $fatal = array_keys($fatal);
        $warning = array_keys($warning);
        sort($fatal, SORT_STRING);
        sort($warning, SORT_STRING);

        return [
            'version' => $edition['version'] ?? null,
            'ran' => true,
            'scenario' => $report['scenario'],
            'scenarioId' => $scenarioId,
            'accepted' => $report['accepted'],
            'verdict' => self::verdict($report),
            'schemaFailed' => $report['stages']['xsd'] === 'failed',
            'counts' => $report['counts'],
            'rules' => ['fatal' => $fatal, 'warning' => $warning],
            'differs' => null,
            'durationMs' => $ms,
            'report' => $report,
        ];
    }

    /**
     * The key of a finding without a rule id: its text, whitespace folded, at most 100 characters.
     *
     * ⚠️ ASCII WHITESPACE AND U+00A0 ONLY, AS IN RuleReport: `\s` means something else in PCRE without UCP than in
     * JavaScript, and the key is compared with the one VAMPIRE JS builds. Cut by code points, not bytes, for the
     * same reason ( `mb_substr`, `Array.from` in JS ).
     */
    public static function textKey(string $text): string
    {
        // trim() with ' ' only: JavaScript's trim() strips every Unicode space, PHP's default set is another one.
        $folded = trim((string) preg_replace('/[ \t\r\n\x{00A0}]+/u', ' ', $text), ' ');

        return '"' . mb_substr($folded, 0, 100, 'UTF-8') . '"';
    }

    /**
     * @param array<string, mixed> $edition
     * @return array<string, mixed>
     */
    public static function notRun(array $edition): array
    {
        return [
            'version' => $edition['version'],
            'ran' => false,
            'scenario' => null,
            'scenarioId' => null,
            'accepted' => null,
            'verdict' => 'not-run',
            'schemaFailed' => null,
            'counts' => null,
            'rules' => null,
            'differs' => null,
            'durationMs' => null,
            'report' => null,
        ];
    }

    /**
     * `accepted`, `warnings` ( accepted, at least one warning ), `rejected`, or `no-verdict` ( a rule set of the
     * scenario is not in this package ).
     *
     * @param array<string, mixed> $report
     */
    public static function verdict(array $report): string
    {
        if ($report['accepted'] === null) {
            return 'no-verdict';
        }

        if ($report['accepted'] === false) {
            return 'rejected';
        }

        return $report['counts']['warning'] > 0 ? 'warnings' : 'accepted';
    }

    /**
     * @param array<string, mixed>|null       $family
     * @param array{version: string, valid: ?string}|null $chosen
     * @param list<array<string, mixed>>      $steps
     * @param array<string, mixed>|null       $report the report of the chosen edition
     * @return array<string, mixed>
     */
    public static function build(?array $family, ?string $issueDate, ?array $chosen, string $code, array $steps, ?array $report, bool $full, string $lang): array
    {
        $steps = self::withDiffers($steps);

        $chosenStep = null;
        $index = null;

        foreach ($steps as $i => $s) {
            if ($chosen !== null && $s['ran'] && $s['version'] === $chosen['version']) {
                $chosenStep = $s;
                $index = $i;
                break;
            }
        }

        $newer = [];

        if ($index !== null) {
            for ($i = 0; $i < $index; $i++) {
                $newer[] = (string) $steps[$i]['version'];
            }
        }

        return [
            'cascadeVersion' => Cascade::VERSION,
            'mode' => $full ? 'matrix' : 'cascade',
            'family' => $family === null ? null : ['id' => $family['id'], 'title' => $family['title'], 'order' => $family['order']],
            'issueDate' => $issueDate,
            'chosen' => [
                'version' => $chosen['version'] ?? null,
                'scenarioId' => $chosenStep['scenarioId'] ?? ($steps[0]['scenarioId'] ?? null),
                'reasonCode' => $code,
                'reason' => self::reason($code, $lang, [
                    'version' => $chosen['version'] ?? null,
                    'valid' => $chosen['valid'] ?? null,
                    'date' => $issueDate,
                    'newer' => $newer,
                ]),
                'validVersion' => $chosen['valid'] ?? null,
            ],
            'accepted' => $report['accepted'] ?? null,
            'steps' => $steps,
            'report' => $report,
        ];
    }

    /**
     * The rules in which one edition differs from the others that ran.
     *
     * ⚠️ ONLY EDITIONS WITH A VERDICT TAKE PART. An edition without one checked nothing; counting its empty
     * list would make every rule of the other editions look like a difference.
     *
     * @param list<array<string, mixed>> $steps
     * @return list<array<string, mixed>>
     */
    private static function withDiffers(array $steps): array
    {
        $sets = [];

        foreach ($steps as $i => $s) {
            if ($s['ran'] && $s['accepted'] !== null) {
                $sets[$i] = array_values(array_unique(array_merge($s['rules']['fatal'], $s['rules']['warning'])));
            }
        }

        if (count($sets) < 2) {
            return $steps;
        }

        $union = array_values(array_unique(array_merge(...array_values($sets))));
        $common = array_values(array_intersect(...array_values($sets)));

        foreach ($sets as $i => $keys) {
            $fired = array_values(array_diff($keys, $common));
            $notFired = array_values(array_diff($union, $keys));
            sort($fired, SORT_STRING);
            sort($notFired, SORT_STRING);
            $steps[$i]['differs'] = ['fired' => $fired, 'notFired' => $notFired];
        }

        return $steps;
    }

    /**
     * @param array{version: ?string, valid: ?string, date: ?string, newer: list<string>} $c
     */
    public static function reason(string $code, string $lang, array $c): string
    {
        $de = $lang !== 'en';
        $v = (string) $c['version'];
        $d = (string) $c['date'];

        return match ($code) {
            'first-accepting' => $c['newer'] === []
                ? ($de ? "Die neueste Fassung $v nimmt die Rechnung an." : "The newest edition $v accepts the invoice.")
                : ($de
                    ? 'Die neueren Fassungen ' . implode(', ', $c['newer']) . " nehmen die Rechnung nicht an; $v ist die neueste Fassung, die sie annimmt."
                    : 'The newer editions ' . implode(', ', $c['newer']) . " do not accept the invoice; $v is the newest edition that accepts it."),
            'none-accepting' => $de
                ? "Keine der geprüften Fassungen nimmt die Rechnung an; es gilt der Bericht der neuesten Fassung $v."
                : "None of the checked editions accepts the invoice; the report of the newest edition $v applies.",
            'validity' => $de
                ? "Das Rechnungsdatum $d liegt im Gültigkeitszeitraum der Fassung $v."
                : "The invoice date $d lies in the validity period of edition $v.",
            'validity-not-carried' => $de
                ? "Das Rechnungsdatum $d liegt im Gültigkeitszeitraum der Fassung {$c['valid']}. Ihr Regelpaket ist nicht enthalten; geprüft wurde mit $v, der nächsten enthaltenen Fassung."
                : "The invoice date $d lies in the validity period of edition {$c['valid']}. Its rule package is not included; the invoice was checked with $v, the nearest included edition.",
            'outside-validity' => $de
                ? "Das Rechnungsdatum $d liegt in keinem bekannten Gültigkeitszeitraum; geprüft wurde mit der Fassung $v."
                : "The invoice date $d lies in no known validity period; the invoice was checked with edition $v.",
            'no-date' => $de
                ? "Die Rechnung nennt kein lesbares Rechnungsdatum; geprüft wurde mit der neuesten enthaltenen Fassung $v."
                : "The invoice states no readable invoice date; it was checked with the newest included edition $v.",
            'no-family' => $de
                ? 'Für dieses Format gibt es keine Kaskade über Fassungen; geprüft wurde wie bei einer einfachen Prüfung.'
                : 'There is no edition cascade for this format; the invoice was checked as in a single check.',
            'no-carried' => $de
                ? 'Dieses Paket enthält für dieses Format keine Regelpakete je Fassung; geprüft wurde wie bei einer einfachen Prüfung.'
                : 'This package contains no rule packages per edition for this format; the invoice was checked as in a single check.',
            'not-wellformed' => $de
                ? 'Die Datei ist kein wohlgeformtes XML; darum gibt es keine Kaskade.'
                : 'The file is not well-formed XML, so there is no cascade.',
            default => throw new \RuntimeException("unknown reason code $code"),
        };
    }
}
