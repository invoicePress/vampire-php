<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Writes an expectation file per official test instance.
 *
 *   php devkit/Cli/generate-expectations.php            dry run (prints what would change)
 *   php devkit/Cli/generate-expectations.php --apply
 *
 * ⚠️ The expectations are RECORDED FROM A RUN, which only makes sense because every instance in the
 * official suite is a POSITIVE case: the suite ships no invalid documents at all (checked 21.09.2026,
 * 86 files, no "_fehler" variants as in other corpora). So "accept: true" is the documented property
 * of the source material, not something this script infers. Negative cases come from the mutation
 * generator and state their expected rule id up front - never recorded from a run, because recording
 * a negative expectation from the implementation under test proves nothing.
 *
 * `mustNotBlock` pins the four rules that the Extension/CVD scenarios downgrade: they still fire, but
 * never as fatal. If the `customLevel` handling ever regresses, they turn fatal and the expectation
 * fails loudly.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

$base = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);

$groups = [
    'xrechnung-standard',
    'xrechnung-cius',
    'xrechnung-extension',
    'xrechnung-cvd',
    // Tasks 4.1a / 4.1b. These come from the published example sets, not from a conformance suite, so
    // "valid" is what their publishers state - the same standing as the XRechnung suite above.
    'peppol-bis',
    'zugferd-en16931',
    'zugferd-extended',
    // Task 0.2b: phive-rules' own test bench. Upstream declares every one of these a GOOD case
    // (`createGoodCase`, no `createBadCase` anywhere in the project), so "accept" is again the
    // documented property of the source material.
    'phive-en16931-cii',
    'phive-en16931-ubl',
    'phive-peppol-billing',
    'phive-zugferd-en16931',
    'phive-zugferd-extended',
    // Task 8.0a
    'phive-zugferd-minimum',
    'phive-zugferd-basicwl',
    'phive-zugferd-basic',
    // Task 8.2 - EU-CIUS. See $partialGroups below.
    'phive-cius-ro',
    'phive-cius-pt',
    'phive-ublbe',
    'phive-cius-rs',
    'phive-agid-it',
];

/**
 * Task 8.2 — groups in which a rejected document is expected and gets no expectation file.
 *
 * The EU-CIUS modules ship the sample documents their own test suite pairs with a SPECIFIC rule
 * version; we run the newest rule set of each country against all of them. Measured causes, one by
 * one: the Romanian samples declare CIUS-RO 1.0.0/1.0.1 and fail rules of 1.0.9; the Portuguese and
 * Belgian ones fail EN-16931 CODE LIST rules (BR-CL-11, BR-CL-25 …) because their documents predate
 * EN16931 1.3.16; ten Italian ones carry the EAS scheme id `0205`, which the Peppol 3.0.21 code list
 * in `PEPPOL-EN16931-UBL.xslt` genuinely does not contain (read out of the rule set itself).
 *
 * ⚠️ These rejections are NOT unverified. The golden comparison (Tasks 4.4/4.6) runs every one of
 * these documents through the real KoSIT validator against the same rule material, and **all 93 get
 * the same verdict and the same rule set from both sides**. An expectation file here would be a
 * weaker repetition of what the golden report already pins down - so only the ACCEPTED ones get one.
 *
 * @var list<string>
 */
$partialGroups = [
    'phive-cius-ro',
    'phive-cius-pt',
    'phive-ublbe',
    'phive-cius-rs',
    'phive-agid-it',
];

/**
 * Documents this script must not write an expectation for, with the reason. They are NOT failures of
 * the library; writing "accept: false" would cement the wrong claim, and leaving them silently out
 * would hide them (rule A.6). See docs/notes/phive-import.md.
 */
$excluded = [
    // ⚠️ Measured, not assumed: these three carry `urn:ferd:CrossIndustryDocument:invoice:1p0:comfort`
    // as their guideline id - ZUGFeRD **1.0** Comfort, a pre-EN-16931 format. The KoSIT configuration
    // ships no scenario for it, so "kein Szenario passt" is the correct answer, not a gap. phive can
    // still run them against EN16931-CII because it picks the rule set by VESID, not by document.
    'CII-BR-CO-10-RoundingIssue.xml' => 'ZUGFeRD-1.0-Comfort-Kennung, kein EN-16931-Szenario',
    'CII_business_example_02.xml' => 'ZUGFeRD-1.0-Comfort-Kennung, kein EN-16931-Szenario',
    'CII_business_example_Z.xml' => 'ZUGFeRD-1.0-Comfort-Kennung, kein EN-16931-Szenario',
    // CustomizationID `…#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` - so the document says
    // itself that it is a Peppol invoice, our resolver runs the Peppol rules, and PEPPOL-COMMON-R049
    // fires. phive runs it against EN16931-UBL 1.3.16 ALONE, where that rule does not exist. Both
    // results are right; they answer different questions.
    'issue116.xml' => 'Peppol-Szenario, PEPPOL-COMMON-R049 - phive prueft es nur gegen EN16931-UBL',
];

/** Rules the KoSIT scenarios downgrade; they must never block acceptance in those groups. */
$downgraded = [
    'xrechnung-extension' => ['BR-CL-10', 'BR-CL-21', 'BR-CO-16', 'UBL-CR-470'],
    'xrechnung-cvd' => ['BR-CL-13'],
];

$resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');
$validator = new Validator(scenarioResolver: $resolver, baseDir: $base);

$outDir = $base . '/tests/fixtures/expectations';

if ($apply && !is_dir($outDir) && !mkdir($outDir, 0777, true)) {
    fwrite(STDERR, "cannot create $outDir\n");
    exit(1);
}

$written = 0;
$rejected = [];
$skipped = [];
/** @var array<string, array<string, list<string>>> $partial group => file => blocking rule ids */
$partial = [];

foreach ($groups as $group) {
    // ⚠️ `*.xml` misses `.XML`; glob() is case-sensitive on Windows too. See coverage-tests.php.
    $files = glob($base . "/tests/fixtures/invoices/$group/*.{xml,XML}", GLOB_BRACE) ?: [];
    sort($files);

    foreach ($files as $file) {
        if (isset($excluded[basename($file)])) {
            $skipped[] = basename($file) . ' - ' . $excluded[basename($file)];
            continue;
        }

        $result = $validator->validateFile($file);

        if (!$result->isAccepted()) {
            if (in_array($group, $partialGroups, true)) {
                $blocking = [];

                foreach ($result->messages() as $message) {
                    if ($message->flag->value === 'fatal' && $message->ruleId !== null) {
                        $blocking[$message->ruleId] = true;
                    }
                }

                $partial[$group][basename($file)] = array_keys($blocking);
                continue;
            }

            // Recording "accept: false" for a document the suite publishes as valid would cement a
            // bug as the expected behaviour. Report it and write nothing.
            $rejected[] = basename($file);
            continue;
        }

        $note = str_starts_with($group, 'phive-')
            ? "phive-rules-Testbestand, Gruppe $group - upstream als GoodCase gefuehrt, muss angenommen werden."
            : "Offizielle XRechnung-3.0.2-Testsuite, Gruppe $group - gueltiger Beleg, muss angenommen werden.";

        $expectation = [
            'file' => "invoices/$group/" . basename($file),
            'scenario' => $result->scenarioName(),
            'accept' => true,
            'rules' => [],
            'mustNotBlock' => $downgraded[$group] ?? [],
            'note' => $note,
        ];

        $target = $outDir . '/' . $group . '__' . basename($file, '.xml') . '.json';
        $json = json_encode($expectation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if (!$apply) {
            $written++;
            continue;
        }

        file_put_contents($target, $json);
        $written++;
    }
}

printf("%s: %d Erwartungsdateien\n", $apply ? 'geschrieben' : 'Trockenlauf', $written);

if ($skipped !== []) {
    echo "\nMit Begruendung ausgenommen (docs/notes/phive-import.md):\n  " . implode("\n  ", $skipped) . "\n";
}

if ($partial !== []) {
    echo "\nEU-CIUS (Aufgabe 8.2) - abgelehnt, deshalb ohne Erwartung (docs/developer/eu-cius.md):\n";

    foreach ($partial as $group => $files) {
        printf("  %-18s %2d Belege\n", $group, count($files));

        $rules = [];

        foreach ($files as $blocking) {
            foreach ($blocking as $ruleId) {
                $rules[$ruleId] = ($rules[$ruleId] ?? 0) + 1;
            }
        }

        arsort($rules);
        $top = array_slice($rules, 0, 6, true);
        $parts = [];

        foreach ($top as $ruleId => $count) {
            $parts[] = "$ruleId ($count)";
        }

        echo '                     haeufigste Regeln: ' . implode(', ', $parts) . "\n";
    }
}

if ($rejected !== []) {
    fwrite(STDERR, "\nABGELEHNT, obwohl offiziell gueltig - nichts geschrieben:\n  " . implode("\n  ", $rejected) . "\n");
    exit(1);
}
