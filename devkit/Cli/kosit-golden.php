<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Golden reports from the ORIGINAL.
 *
 *   php devkit/Cli/kosit-golden.php              dry run: says what it would do
 *   php devkit/Cli/kosit-golden.php --apply      runs the KoSIT validator, writes tests/fixtures/golden/
 *   php devkit/Cli/kosit-golden.php --apply --group=mutated      just one group
 *
 * ⚠️ **This is the one place in the project that starts a JVM**, and the project rules allow exactly
 * this: a devkit command, named `kosit-*`, started by hand, never part of `composer test` and never part
 * of the shipped library. Without this comparison there is no evidence at all that the PHP validator can
 * stand in for the original - only the claim.
 *
 * What is extracted from each report and nothing more: the matched scenario, every `rep:message` with
 * its `code` and `level`, and the verdict (`rep:accept` / `rep:reject`). The HTML the report carries
 * inside `rep:explanation` is deliberately dropped - it is a rendering, not a finding.
 *
 * ⚠️ **One output directory per group.** File names repeat across groups (`base-example.xml` exists in
 * both `peppol-bis` and `phive-peppol-billing`), and the validator names its report after the input
 * file alone. Into one directory, the second group would silently overwrite the first's reports.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// The installation of the KoSIT validator; set KOSIT_HOME to its folder.
define('KOSIT_HOME', rtrim((string) (getenv('KOSIT_HOME') ?: dirname(__DIR__, 2) . '/vendor-sources/kosit-validator'), '/\\'));

/**
 * The configurations, in the combination that was verified against the KoSIT validator.
 *
 * ⚠️ `factur-x` is taken from **this project's** `vendor-sources/facturx-config/`, not from the
 * installation named by KOSIT_HOME. Both hold the same official artefacts, but ours carries the
 * three scenarios added in Task 8.0a (MINIMUM, BASIC WL, BASIC). Without them the reference validator
 * would answer "no scenario matched" for those documents and the comparison would record a
 * disagreement that is really just a configuration the installation has not been given. The
 * installation itself is never modified.
 */
const KOSIT_CONFIGS = ['xrechnung', 'peppol'];
const OWN_FACTURX_CONFIG = 'vendor-sources/facturx-config';

/**
 * Task 8.2 — the EU-CIUS repository, likewise this project's own
 * (`vendor-sources/eu-cius/`, assembled from the same rule files the importer read).
 */
const OWN_EU_CIUS_CONFIG = 'vendor-sources/eu-cius';

/**
 * Groups that run against the EU-CIUS configuration ALONE.
 *
 * ⚠️ Not a shortcut - a necessity. The Italian AGID customization id begins with the Peppol Billing
 * id, so an Italian invoice matches BOTH the AGID scenario and the Peppol one. The KoSIT validator
 * does not resolve that as "first one wins": it reports `noScenarioMatched` and rejects the document,
 * silently, with nothing in the log (measured for the
 * same collision between factur-x and xrechnung). Handing it both configurations at once would
 * therefore produce a "difference" that is an artefact of the call, not of either implementation.
 */
const EU_CIUS_GROUPS = [
    'phive-cius-ro',
    'phive-cius-pt',
    'phive-ublbe',
    'phive-cius-rs',
    'phive-agid-it',
];

const GROUPS = [
    'phive-zugferd-minimum',
    'phive-zugferd-basicwl',
    'phive-zugferd-basic',
    'xrechnung-standard',
    'xrechnung-cius',
    'xrechnung-extension',
    'xrechnung-cvd',
    'peppol-bis',
    'zugferd-en16931',
    'zugferd-extended',
    'mutated',
    'phive-en16931-cii',
    'phive-en16931-ubl',
    'phive-peppol-billing',
    'phive-zugferd-en16931',
    'phive-zugferd-extended',
    ...EU_CIUS_GROUPS,
];

/**
 * @return array{scenario: ?string, accept: bool, messages: array<string, string>, engine: string}
 */
function parse_kosit_report(string $path): array
{
    $dom = new \DOMDocument();

    if (!$dom->load($path)) {
        fwrite(STDERR, "Bericht nicht lesbar: $path\n");
        exit(1);
    }

    $xpath = new \DOMXPath($dom);
    $xpath->registerNamespace('rep', 'http://www.xoev.de/de/validator/varl/1');
    $xpath->registerNamespace('s', 'http://www.xoev.de/de/validator/framework/1/scenarios');

    $scenario = text_of($xpath, '/rep:report/rep:scenarioMatched/s:scenario/s:name');

    $messages = [];

    foreach ($xpath->query('//rep:validationStepResult/rep:message') ?: [] as $node) {
        if (!$node instanceof \DOMElement) {
            continue;
        }

        $code = $node->getAttribute('code');

        if ($code === '') {
            // XSD errors carry no rule code. Kept under a stable pseudo-code rather than dropped -
            // "the schema complained" is part of the verdict.
            $code = 'XSD';
        }

        $level = $node->getAttribute('level');

        // ⚠️ Worst level wins when the same code appears more than once: a rule can fire on several
        // nodes, and keeping the last would let an `information` hide an `error` of the same rule.
        $rank = ['information' => 0, 'warning' => 1, 'error' => 2];

        if (!isset($messages[$code]) || ($rank[$level] ?? 0) > ($rank[$messages[$code]] ?? 0)) {
            $messages[$code] = $level;
        }
    }

    ksort($messages);

    $verdict = $xpath->query('/rep:report/rep:assessment/rep:accept');

    return [
        'scenario' => $scenario,
        'accept' => $verdict !== false && $verdict->length > 0,
        'messages' => $messages,
        'engine' => trim(text_of($xpath, '/rep:report/rep:engine/rep:name') ?? '?'),
    ];
}

/**
 * The text of the first node an expression selects, or null. Wrapped because `DOMXPath::query()`
 * returns `false` on a bad expression and its items are typed as `DOMNode|DOMNameSpaceNode`, which has
 * no `textContent` - three type errors per call site if written out each time.
 */
function text_of(\DOMXPath $xpath, string $expression): ?string
{
    $nodes = $xpath->query($expression);

    if ($nodes === false || $nodes->length === 0) {
        return null;
    }

    $node = $nodes->item(0);

    return $node instanceof \DOMNode ? $node->textContent : null;
}

$base = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);
$onlyGroup = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--group=')) {
        $onlyGroup = substr($arg, 8);
    }
}

if (!is_file(KOSIT_HOME . '/bin/validator.jar')) {
    fwrite(STDERR, 'validator.jar nicht gefunden unter ' . KOSIT_HOME . "/bin/\n");
    exit(1);
}

$goldenDir = $base . '/tests/fixtures/golden';
$tmpRoot = sys_get_temp_dir() . '/kosit-golden-' . getmypid();

$configArgs = '';

foreach (KOSIT_CONFIGS as $config) {
    $configArgs .= sprintf(' -r "%s/config/%s" -s "%s/config/%s/scenarios.xml"', KOSIT_HOME, $config, KOSIT_HOME, $config);
}

$ownFacturx = $base . '/' . OWN_FACTURX_CONFIG;

if (!is_file($ownFacturx . '/scenarios.xml')) {
    fwrite(STDERR, "Eigene Factur-X-Konfiguration fehlt: $ownFacturx/scenarios.xml\n");
    exit(1);
}

$configArgs .= sprintf(' -r "%s" -s "%s/scenarios.xml"', $ownFacturx, $ownFacturx);

$ownEuCius = $base . '/' . OWN_EU_CIUS_CONFIG;

if (!is_file($ownEuCius . '/scenarios.xml')) {
    fwrite(STDERR, "Eigene EU-CIUS-Konfiguration fehlt: $ownEuCius/scenarios.xml\n");
    exit(1);
}

$euCiusArgs = sprintf(' -r "%s" -s "%s/scenarios.xml"', $ownEuCius, $ownEuCius);

$written = 0;
$missing = [];

foreach (GROUPS as $group) {
    if ($onlyGroup !== null && $group !== $onlyGroup) {
        continue;
    }

    $files = glob($base . "/tests/fixtures/invoices/$group/*.{xml,XML}", GLOB_BRACE) ?: [];
    sort($files);

    if ($files === []) {
        continue;
    }

    printf("%-24s %3d Belege\n", $group, count($files));

    if (!$apply) {
        continue;
    }

    $outDir = $tmpRoot . '/' . $group;

    if (!is_dir($outDir) && !mkdir($outDir, 0777, true)) {
        fwrite(STDERR, "cannot create $outDir\n");
        exit(1);
    }

    // Several files per call: the JVM needs about two seconds to start and load the scenarios, which
    // per file would dominate the whole run.
    //
    // ⚠️ But NOT all of them in one call. Windows caps a command line at 8191 characters, and the
    // 66 files of `xrechnung-standard` with their full paths run past it. The first run lost 67 of
    // 208 reports to this, quietly: the validator processed the beginning of the list and the rest
    // simply never happened. 20 per call keeps a comfortable margin.
    $exitCode = 0;

    foreach (array_chunk($files, 20) as $chunk) {
        $fileArgs = '';

        foreach ($chunk as $file) {
            $fileArgs .= ' "' . str_replace('/', '\\', $file) . '"';
        }

        $command = sprintf(
            'java -jar "%s/bin/validator.jar"%s -o "%s"%s',
            KOSIT_HOME,
            in_array($group, EU_CIUS_GROUPS, true) ? $euCiusArgs : $configArgs,
            $outDir,
            $fileArgs
        );

        $output = [];
        exec($command . ' 2>&1', $output, $chunkExit);
        $exitCode = max($exitCode, $chunkExit);
    }

    // ⚠️ The exit code is NOT an error signal here: the validator returns non-zero as soon as one
    // document is rejected, and rejected documents are exactly what the negative cases are for.
    // What counts is whether a report was written for every input.
    foreach ($files as $file) {
        $stem = pathinfo($file, PATHINFO_FILENAME);
        $reportPath = $outDir . '/' . $stem . '-report.xml';

        // ⚠️ The validator derives the report name from the document URI, so characters that are
        // illegal in a URI path come back percent-encoded: "FT G2G_TD01 con Allegato, Bonifico….xml"
        // becomes "FT%20G2G_TD01%20con%20Allegato,%20Bonifico…-report.xml" - spaces escaped, the
        // comma left alone. Looking only for the plain name lost exactly that one file out of 208,
        // and it would have been easy to write off as "the validator failed on it".
        //
        // Which characters those are is not guessed here: every report in the directory is decoded
        // and compared. `rawurlencode()` would have escaped the comma too and still missed the file.
        if (!is_file($reportPath)) {
            foreach (glob($outDir . '/*-report.xml') ?: [] as $candidate) {
                if (rawurldecode(basename($candidate)) === $stem . '-report.xml') {
                    $reportPath = $candidate;
                    break;
                }
            }
        }

        if (!is_file($reportPath)) {
            $missing[] = "$group/" . basename($file) . ' (Exit ' . $exitCode . ')';
            continue;
        }

        $parsed = parse_kosit_report($reportPath);
        $parsed = ['file' => "invoices/$group/" . basename($file)] + $parsed;

        if (!is_dir($goldenDir) && !mkdir($goldenDir, 0777, true)) {
            fwrite(STDERR, "cannot create $goldenDir\n");
            exit(1);
        }

        file_put_contents(
            $goldenDir . '/' . $group . '__' . pathinfo($file, PATHINFO_FILENAME) . '.json',
            json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
        );

        $written++;
    }
}

if (!$apply) {
    echo "\nTrockenlauf - nichts geschrieben. Mit --apply laufen lassen (startet Java).\n";
    exit(0);
}

printf("\n%d goldene Berichte geschrieben nach tests/fixtures/golden/\n", $written);

if ($missing !== []) {
    fwrite(STDERR, "\nOhne Bericht geblieben:\n  " . implode("\n  ", $missing) . "\n");
    exit(1);
}
