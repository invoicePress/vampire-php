<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Task 3.3 — runs SchematronXsltImporter (Task 3.1) over all four real, locally available
 * compiled EN16931/XRechnung rulesets (docs/developer/sources.md) and writes each as
 * `rulesets/<id>@<version>.json` (the shipped artifact `src/Ruleset/RulesetJsonCodec` reads back
 * at runtime).
 *
 * Run: php devkit/Cli/import-ruleset.php
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Import\SchematronXsltImporter;
use EinvoiceValidator\Devkit\Licence\RuleProvenance;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * @return array<int, array{id: string, version: string, file: string}>
 */
function sources(): array
{
    $root = dirname(__DIR__, 2) . '/vendor-sources';

    $en16931 = "$root/en16931-schematron/1.3.16";
    $xrechnung = "$root/xrechnung-schematron/3.0.2";
    $peppol = "$root/peppol-config/resources/peppol/billing-bis/3.0.21";
    $facturx = "$root/facturx-config/resources";
    $euCius = "$root/eu-cius";

    return [
        ['id' => 'EN16931-UBL', 'version' => '1.3.16', 'file' => "$en16931/ubl/EN16931-UBL-validation.xslt"],
        ['id' => 'EN16931-CII', 'version' => '1.3.16', 'file' => "$en16931/cii/EN16931-CII-validation.xslt"],
        ['id' => 'XRechnung-UBL', 'version' => '3.0.2', 'file' => "$xrechnung/XRechnung-UBL-validation.xslt"],
        ['id' => 'XRechnung-CII', 'version' => '3.0.2', 'file' => "$xrechnung/XRechnung-CII-validation.xslt"],
        // Task 4.1a - Peppol BIS Billing 3.0.21, both halves its own configuration runs.
        ['id' => 'CEN-EN16931-UBL', 'version' => '3.0.21', 'file' => "$peppol/CEN-EN16931-UBL.xslt"],
        ['id' => 'PEPPOL-EN16931-UBL', 'version' => '3.0.21', 'file' => "$peppol/PEPPOL-EN16931-UBL.xslt"],
        // Task 4.1b - Factur-X / ZUGFeRD 2.5.2 (CII), profiles EN16931 and EXTENDED.
        ['id' => 'FACTURX-EN16931-CII', 'version' => '1.09.2', 'file' => "$facturx/en16931/FACTUR-X_EN16931.xslt"],
        ['id' => 'FACTURX-EXTENDED-CII', 'version' => '1.09.2', 'file' => "$facturx/extended/FACTUR-X_EXTENDED.xslt"],
        // Task 8.0a - the remaining three profiles of the same package.
        ['id' => 'FACTURX-MINIMUM-CII', 'version' => '1.09.2', 'file' => "$facturx/minimum/FACTUR-X_MINIMUM.xslt"],
        ['id' => 'FACTURX-BASICWL-CII', 'version' => '1.09.2', 'file' => "$facturx/basicwl/FACTUR-X_BASIC-WL.xslt"],
        ['id' => 'FACTURX-BASIC-CII', 'version' => '1.09.2', 'file' => "$facturx/basic/FACTUR-X_BASIC.xslt"],
        // Task 8.2 - EU-CIUS. Source: the phive-rules checkout ( PHIVE_RULES_DIR ), one version per country (the newest the
        // working copy carries), staged under vendor-sources/eu-cius/. All of them are UBL.
        ['id' => 'CIUS-RO-UBL', 'version' => '1.0.9', 'file' => "$euCius/cius-ro/EN16931-CIUS_RO-UBL-validation.xslt"],
        ['id' => 'CIUS-PT-UBL', 'version' => '2.1.1', 'file' => "$euCius/cius-pt/urn_feap.gov.pt_CIUS-PT_2.1.1.xslt"],
        ['id' => 'UBLBE-UBL', 'version' => '1.31', 'file' => "$euCius/ublbe/GLOBALUBL.BE.xslt"],
        ['id' => 'CIUS-RS-UBL', 'version' => '1.0.0', 'file' => "$euCius/serbia/EN16931-UBL-srbdt-validation.xslt"],
        // 1.0.4 since the phive update of 07.10.2026; 1.0.3 before.
        ['id' => 'CIUS-HR-UBL', 'version' => '1.0.4', 'file' => "$euCius/eracun/HR-CIUS-EXT-EN16931-UBL.xslt"],
        ['id' => 'AGID-EN16931-UBL', 'version' => '3.0.2', 'file' => "$euCius/peppol-italy/AGID-EN16931-UBL.xslt"],
        ['id' => 'UBL-TR', 'version' => '1.2.1', 'file' => "$euCius/turkey/UBL-TR_Main_Schematron.xslt"],
    ];
}

function main(): void
{
    $outDir = dirname(__DIR__, 2) . '/rulesets';
    if (!is_dir($outDir)) {
        mkdir($outDir, 0777, true);
    }

    // ⚠️ `--only=<id>` is not convenience, it is damage control: a fresh import OVERWRITES a rule set
    // and throws away the catalogue metadata that Task 0.2c wrote into it (`duplicateKey`,
    // `descriptions`, `catalogStandard`). Importing everything is still correct - but then
    // `devkit/Cli/crosscheck-shared-data.php --apply` has to run afterwards to put it back.
    $only = null;

    /** @var list<string> $argv */
    $argv = $GLOBALS['argv'] ?? [];

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--only=')) {
            $only = explode(',', substr($arg, 7));
        }
    }

    $provenance = new RuleProvenance(RuleProvenance::defaultTable());

    foreach (sources() as $source) {
        if ($only !== null && !in_array($source['id'], $only, true)) {
            continue;
        }

        if (!is_readable($source['file'])) {
            fwrite(STDERR, "missing source file: {$source['file']}\n");
            exit(1);
        }

        $ruleset = SchematronXsltImporter::import($source['file'], $source['id'], $source['version']);

        // ⚠️ Since 09.10.2026 every rule set carries in its head the licence it is passed on under
        // ( `license` ) and FeRD's visible notice where one applies ( `notice` ), both from
        // vampire-php/rule-provenance.json. A source without an entry is imported
        // without them; export-runtime.php then refuses to ship it.
        $ruleset = $provenance->stamp($ruleset);

        $ruleCount = 0;
        $assertionCount = 0;
        foreach ($ruleset->patterns as $pattern) {
            $ruleCount += count($pattern->rules);
            foreach ($pattern->rules as $rule) {
                $assertionCount += count($rule->assertions);
            }
        }

        $outPath = "$outDir/{$source['id']}@{$source['version']}.json";
        RulesetJsonCodec::writeFile($outPath, $ruleset);

        $message = "wrote $outPath: " . count($ruleset->patterns) . ' patterns, '
            . "$ruleCount rules, $assertionCount assertions, " . count($ruleset->globalLets) . " global lets\n";
        fwrite(STDOUT, $message);
    }
}

main();
