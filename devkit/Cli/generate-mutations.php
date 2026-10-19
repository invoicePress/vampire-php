<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Builds the negative test bed: one mutated invoice per catalogue entry, plus the matching expectation
 * file.
 *
 *   php devkit/Cli/generate-mutations.php            dry run, reports what each mutation triggers
 *   php devkit/Cli/generate-mutations.php --apply
 *
 * The expected rule is declared in the catalogue and VERIFIED here. A mutation whose declared rule
 * does not fire as fatal is reported and NOTHING is written for it - neither invoice nor expectation.
 * That is the whole point: a negative test recorded from the implementation would pass by
 * construction.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use EinvoiceValidator\Devkit\Mutation\Mutator;
use EinvoiceValidator\Pipeline\ScenarioResolver;
use EinvoiceValidator\Validator;

$base = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$apply = in_array('--apply', $argv, true);

$sources = [
    'ubl' => $base . '/tests/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_ubl.xml',
    'cii' => $base . '/tests/fixtures/invoices/xrechnung-standard/01.01a-INVOICE_uncefact.xml',
];

$invoiceDir = $base . '/tests/fixtures/invoices/mutated';
$expectDir = $base . '/tests/fixtures/expectations';

foreach ([$invoiceDir, $expectDir] as $dir) {
    if ($apply && !is_dir($dir) && !mkdir($dir, 0777, true)) {
        fwrite(STDERR, "cannot create $dir\n");
        exit(1);
    }
}

$resolver = ScenarioResolver::fromJsonFile($base . '/rulesets/scenarios.json');
$validator = new Validator(scenarioResolver: $resolver, baseDir: $base);
$mutator = new Mutator();

$ok = 0;
$problems = [];

foreach (Mutator::catalogue() as $mutation) {
    // Task 4.7: a mutation may name its own source document - the Peppol checksum rules need a Peppol
    // invoice, not the XRechnung default.
    $source = $mutation->source !== null
        ? $base . '/tests/fixtures/invoices/' . $mutation->source
        : $sources[$mutation->syntax];

    if (!is_file($source)) {
        $problems[] = $mutation->id . ': Quelldatei fehlt - ' . $source;
        continue;
    }

    $document = new DOMDocument();
    $document->preserveWhiteSpace = true;
    $document->load($source);

    try {
        $mutator->apply($document, $mutation);
    } catch (RuntimeException $e) {
        $problems[] = $mutation->id . ': ' . $e->getMessage();
        continue;
    }

    $xml = (string) $document->saveXML();
    $result = $validator->validateString($xml);

    $fired = [];
    foreach ($result->messages() as $message) {
        if ($message->ruleId !== null) {
            $fired[$message->ruleId] = $message->flag->value;
        }
    }

    $hit = ($fired[$mutation->expectedRule] ?? null) === 'fatal';

    printf(
        "%-34s %-11s erwartet %-12s %s\n",
        $mutation->id,
        $result->isAccepted() ? 'AKZEPTIERT' : 'abgelehnt',
        $mutation->expectedRule,
        $hit ? 'ausgelöst' : 'NICHT ausgelöst -> ' . implode(', ', array_slice(array_keys(array_filter($fired, static fn($f) => $f === 'fatal')), 0, 5))
    );

    if (!$hit) {
        $problems[] = $mutation->id . ': ' . $mutation->expectedRule . ' feuerte nicht fatal';
        continue;
    }

    $ok++;

    if (!$apply) {
        continue;
    }

    $invoiceName = $mutation->id . '.xml';
    file_put_contents($invoiceDir . '/' . $invoiceName, $xml);

    $expectation = [
        'file' => 'invoices/mutated/' . $invoiceName,
        'scenario' => $result->scenarioName(),
        'accept' => false,
        'rules' => [
            ['id' => $mutation->expectedRule, 'flag' => 'fatal'],
        ],
        'mustNotBlock' => [],
        'note' => 'Negativtest (Aufgabe 4.2), erzeugt aus ' . basename($source) . ': ' . $mutation->why,
    ];

    file_put_contents(
        $expectDir . '/mutated__' . $mutation->id . '.json',
        json_encode($expectation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );
}

printf("\n%s: %d von %d Mutationen brauchbar\n", $apply ? 'geschrieben' : 'Trockenlauf', $ok, count(Mutator::catalogue()));

if ($problems !== []) {
    fwrite(STDERR, "\nNicht verwendet:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}
