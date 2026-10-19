<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Task 4.1 — imports the real KoSIT scenarios.xml (vendor-sources/kosit-config/) and writes
 * `rulesets/scenarios.json` (the shipped artifact `Pipeline\ScenarioResolver::fromJsonFile()`
 * reads at runtime).
 *
 * Run: php devkit/Cli/import-scenarios.php
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Import\ScenarioXmlImporter;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function main(): void
{
    $root = dirname(__DIR__, 2);

    // One scenarios.json from SEVERAL official configurations (Tasks 4.1, 4.1a, 4.1b, 8.2). Order
    // matters: the resolver takes the first matching scenario, and the XRechnung CIUS ids are the most
    // specific, so they have to be tried before the plain Peppol/EN16931 ones.
    //
    // ⚠️ The EU-CIUS file belongs BEFORE the Peppol one. The Italian AGID customization id BEGINS with
    // the Peppol Billing id, and the Peppol scenario matches with starts-with() - the other way round,
    // an Italian invoice would never reach its AGID rules and nobody would see a thing.
    $sources = [
        "$root/vendor-sources/kosit-config/scenarios.xml",
        "$root/vendor-sources/eu-cius/scenarios.xml",
        "$root/vendor-sources/peppol-config/scenarios.xml",
        "$root/vendor-sources/facturx-config/scenarios.xml",
    ];

    $scenarios = [];

    foreach ($sources as $source) {
        if (!is_readable($source)) {
            fwrite(STDERR, "missing source file: $source\n");
            exit(1);
        }

        foreach (ScenarioXmlImporter::import($source) as $scenario) {
            $scenarios[] = $scenario;
        }
    }

    $data = array_map(static fn ($s): array => $s->toArray(), $scenarios);

    $outPath = "$root/rulesets/scenarios.json";
    file_put_contents(
        $outPath,
        json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    fwrite(STDOUT, "wrote $outPath: " . count($scenarios) . " scenarios\n");
    foreach ($scenarios as $s) {
        $xsd = $s->xsdPath ?? '(none)';
        $refs = implode(', ', $s->rulesetRefs);
        fwrite(STDOUT, "  {$s->id}: xsd=$xsd rulesets=[$refs]\n");
    }
}

main();
