<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * How much of each rule set the test bed actually exercises.
 *
 *   php devkit/Cli/coverage-tests.php            prints the numbers
 *   php devkit/Cli/coverage-tests.php --apply    writes docs/developer/coverage-tests.json and the
 *                                                "Testabdeckung" section of docs/developer/coverage.md
 *
 * Counted is what FIRED while validating every fixture (86 official instances plus the generated
 * negative cases). A rule that never fires is not necessarily untested - most rules simply do not
 * apply to these documents - so the number is reported as what it is: "von N Regeln haben M in
 * mindestens einem Testfall ausgelöst". The stricter criterion (each XRechnung rule triggered by at
 * least one case AND not triggered by at least one) needs a far larger negative corpus; the gap is
 * named here instead of being papered over.
 *
 * ⚠️ The numbers are written to a MACHINE-READABLE file next to the prose. `CoverageDocTest`
 * recomputes them and compares, so the document cannot quietly drift away from the code.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;
use EinvoiceValidator\Validator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @return array{rulesets: array<string, array{rules: int, fired: int}>, files: int}
 */
function measure_test_coverage(string $base): array
{
    $resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');
    $validator = new Validator(scenarioResolver: $resolver, baseDir: $base);

    $files = [];

    $groups = [
        'xrechnung-standard', 'xrechnung-cius', 'xrechnung-extension', 'xrechnung-cvd',
        'peppol-bis', 'zugferd-en16931', 'zugferd-extended', 'mutated',
        // Task 0.2b: the phive-rules test bench, one version per module - the version whose rule
        // material is ours (see devkit/Cli/import-phive-testfiles.php).
        'phive-en16931-cii', 'phive-en16931-ubl', 'phive-peppol-billing',
        'phive-zugferd-en16931', 'phive-zugferd-extended',
        // Task 8.0a
        'phive-zugferd-minimum', 'phive-zugferd-basicwl', 'phive-zugferd-basic',
        // Task 8.2 - EU-CIUS. Counted here even though many of these documents are rejected: what is
        // measured is which RULES fired, and a rejected document exercises rules just as well.
        'phive-cius-ro', 'phive-cius-pt', 'phive-ublbe', 'phive-cius-rs', 'phive-agid-it',
    ];

    foreach ($groups as $group) {
        // ⚠️ `*.xml` alone is NOT enough. PHP's glob() is case-sensitive even on Windows, and three
        // fixtures are named `.XML` (BIS3_Invoice_*.XML, E04_01_Betriebskostenabrechnung_NEU.XML).
        // Measured on 21.09.2026: that last one was silently missing from every coverage run with a
        // lowercase pattern and had no expectation file at all - the glob did not fail, it just
        // counted one document less.
        foreach (glob($base . "/tests/fixtures/invoices/$group/*.{xml,XML}", GLOB_BRACE) ?: [] as $file) {
            $files[] = $file;
        }
    }

    sort($files);

    /** @var array<string, array<string, true>> $firedPerRuleset */
    $firedPerRuleset = [];

    foreach ($files as $file) {
        $result = $validator->validateFile($file);

        foreach ($result->messages() as $message) {
            if ($message->ruleId !== null) {
                $firedPerRuleset[$message->ruleset][$message->ruleId] = true;
            }
        }
    }

    $out = [];

    foreach (glob($base . '/rulesets/*@*.json') ?: [] as $rulesetFile) {
        $ref = basename($rulesetFile, '.json');
        $ruleset = RulesetJsonCodec::readFile($rulesetFile);

        $ids = [];

        foreach ($ruleset->patterns as $pattern) {
            foreach ($pattern->rules as $rule) {
                foreach ($rule->assertions as $assertion) {
                    if ($assertion->id !== null) {
                        $ids[$assertion->id] = true;
                    }
                }
            }
        }

        $out[$ref] = [
            'rules' => count($ids),
            'fired' => count(array_intersect_key($firedPerRuleset[$ref] ?? [], $ids)),
        ];
    }

    ksort($out);

    return ['rulesets' => $out, 'files' => count($files)];
}

// ⚠️ Everything below runs only when this file IS the script being executed. CoverageDocTest
// `require_once`s it to reuse measure_test_coverage(), and without this guard the CLI body ran inside
// the test - which failed on $argv not existing under PHPUnit.
if (PHP_SAPI !== 'cli' || !isset($argv) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$base = dirname(__DIR__, 2);
$apply = in_array('--apply', $argv, true);
$data = measure_test_coverage($base);

printf("%d Testdateien\n\n", $data['files']);

$lines = [];

foreach ($data['rulesets'] as $ref => $numbers) {
    $percent = $numbers['rules'] > 0 ? round($numbers['fired'] / $numbers['rules'] * 100, 1) : 0.0;
    printf("%-26s %4d Regeln, %3d ausgelöst (%.1f %%)\n", $ref, $numbers['rules'], $numbers['fired'], $percent);
    $lines[] = sprintf('| `%s` | %d | %d | %.1f %% |', $ref, $numbers['rules'], $numbers['fired'], $percent);
}

if (!$apply) {
    exit(0);
}

file_put_contents(
    $base . '/docs/developer/coverage-tests.json',
    json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

$section = "\n## Testabdeckung (Aufgabe 4.5)\n\n"
    . sprintf(
        "Gemessen über %d Testdateien: offizieller XRechnung-Testbestand, die Peppol- und "
        . "ZUGFeRD-Beispiele, der phive-rules-Testbestand aus Aufgabe 0.2b und die erzeugten "
        . "Negativfälle aus Aufgabe 4.2. Gezählt wird, welche Regel-IDs beim Validieren mindestens "
        . "einmal ausgelöst haben.\n\n"
        . "⚠️ Die 92 Belege aus 0.2b haben die Quote **kaum** bewegt, und das ist kein Mangel des "
        . "Imports: es sind durchweg **gültige** Belege, und ein gültiger Beleg löst fast keine Regel "
        . "aus. Ihr Wert liegt woanders — sie fangen Rückschritte, wenn eine Änderung anfängt, "
        . "korrekte Rechnungen abzulehnen. Die Quote hebt nur ein größerer Negativbestand.\n\n",
        $data['files']
    )
    . "| Regelwerk | Regeln | mindestens einmal ausgelöst | Anteil |\n|---|---:|---:|---:|\n"
    . implode("\n", $lines) . "\n\n"
    . "⚠️ Eine Regel, die nie ausgelöst hat, ist nicht zwangsläufig ungetestet — die meisten Regeln treffen "
    . "auf diese Belege schlicht nicht zu. Das Akzeptanzkriterium der Phase 4 (jede Regel der "
    . "XRechnung-Konfiguration wird von mindestens einem Testfall ausgelöst **und** von mindestens einem "
    . "nicht) ist damit **nicht** erfüllt; dafür braucht es einen deutlich größeren Negativbestand als die "
    . "heutigen " . count(glob($base . '/tests/fixtures/invoices/mutated/*.xml') ?: []) . " Mutationen. "
    . "Die Zahlen oben sagen, wie weit es bis dahin ist.\n\n"
    . "Erzeugt von `devkit/Cli/coverage-tests.php`; `CoverageDocTest` rechnet sie nach.\n";

$doc = (string) file_get_contents($base . '/docs/developer/coverage.md');
$doc = preg_replace('/\n## Testabdeckung \(Aufgabe 4\.5\).*$/s', '', $doc) . $section;

file_put_contents($base . '/docs/developer/coverage.md', $doc);

echo "\ndocs/developer/coverage.md und docs/developer/coverage-tests.json geschrieben\n";
