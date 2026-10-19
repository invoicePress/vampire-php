<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * The rule packages PER EDITION of ZUGFeRD / Factur-X for the version cascade.
 *
 *   php devkit/Cli/import-zugferd-versions.php                 dry run: lists what would be staged and imported
 *   php devkit/Cli/import-zugferd-versions.php --apply         stages, imports, writes rulesets/versions/
 *   php devkit/Cli/import-zugferd-versions.php --apply --only=2.1,2.2
 *
 * writes
 *
 *   vendor-sources/zugferd-versions/<edition>/<profile>/       the upstream files, copied unchanged
 *   rulesets/versions/ZUGFERD-<PROFILE>-CII@<edition>.json     one rule package per edition and profile
 *   rulesets/versions/codedb/zugferd-<edition>-<profile>_codedb.xml   the code database it reads
 *   rulesets/versions/xsd/zugferd-<edition>/<profile>/*.xsd    the XML schema of the same edition and profile
 *   rulesets/versions/cascade.json                             the cascade table ( families, editions, scenarios )
 *
 * Source: phive-rules-zugferd/src/main/resources/external of the phive-rules checkout ( PHIVE_RULES_DIR ), the same
 * files from which the KoSIT configurations of the comparison measurement were built. ⚠️ THE SAME
 * FILES ON PURPOSE: the cascade must reproduce that matrix, and a rule package from another copy
 * ( vendor-sources/facturx-config carries a 2.5.2 XSLT with a different size ) would answer a different
 * question.
 *
 * ⚠️ **rulesets/versions/ IS NOT PART OF THE DEFAULT PACKAGE.** `export-runtime.php` globs `rulesets/*@*.json`
 * without descending, and copies only `rulesets/codedb`. The edition packages leave the work tree only through
 * `export-runtime.php --versions` and through the add-on build of VAMPIRE JS with `--versions`; the default
 * scenarios ( rulesets/scenarios.json ) and their results stay exactly as they were.
 *
 * ⚠️ **THE CODE DATABASES ARE RENAMED, AND THE RULE PACKAGE WITH THEM.** Every edition reads its code lists
 * through `document('FACTUR-X_BASIC_codedb.xml')` - the same plain name in 2.1, 2.2, 2.4, 2.5 and 2.5.2, with
 * different contents ( MD5 measured on 09.10.2026: five different files ). `fn:document()` accepts a plain file
 * name only ( FunctionLibrary::fnDocument(), no sub-folder ), so two editions cannot share one folder under the
 * upstream name. The literal inside the rule package is rewritten to `zugferd-<edition>-<profile>_codedb.xml`,
 * and the file is copied under that name. A rule set that still pointed at the upstream name would silently read
 * the code lists of whichever edition was copied last.
 *
 * ⚠️ **THE PROFILE IS READ FROM THE FILE NAME, AND NOT MONOTONICALLY.** 2.3.2 and 2.3.3 write
 * `Factur-X_1.07.x_BASICWL.xslt`, every other edition `FACTUR-X_BASIC-WL.xslt`. BASIC-WL must be tested before
 * BASIC, or the BASIC rules would claim the BASIC WL file ( the same trap as in zugferd-configs.php ).
 *
 * ⚠️ **2.0.1 IS NOT IMPORTED.** It is the one edition that names itself in the profile id ( `urn:zugferd.de:2p0:…` ),
 * so it is no step of the cascade for an invoice with `urn:factur-x.eu:1p0:…`, and it merges BASIC WL and MINIMUM
 * in one Schematron file. It is an open point.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Import\SchematronXsltImporter;
use EinvoiceValidator\Devkit\Licence\RuleProvenance;
use EinvoiceValidator\Devkit\Xpath\Parser\Parser;
use EinvoiceValidator\Ruleset\RulesetJsonCodec;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The phive-rules checkout; set PHIVE_RULES_DIR to its folder.
define('PHIVE', rtrim((string) (getenv('PHIVE_RULES_DIR') ?: dirname(__DIR__, 2) . '/vendor-sources/phive-rules-master'), '/\\') . '/phive-rules-zugferd/src/main/resources/external');

/** The editions of the matrix of 24.09.2026, newest first - the order of the cascade. */
const EDITIONS = ['2.5.2', '2.5', '2.4', '2.3.3', '2.3.2', '2.2', '2.1'];

/**
 * Profile => [ id part, profile id in GuidelineSpecifiedDocumentContextParameter ].
 *
 * ⚠️ THE PROFILE ID IS THE SAME IN EVERY EDITION - that is the whole reason for the cascade
 * ( the comparison measurement against the KoSIT validator ). The order is the order of the KoSIT
 * configurations of the matrix ( zugferd-configs.php ), which is also the scenario order here.
 */
const PROFILES = [
    'minimum' => ['MINIMUM', 'urn:factur-x.eu:1p0:minimum'],
    'basicwl' => ['BASICWL', 'urn:factur-x.eu:1p0:basicwl'],
    'basic' => ['BASIC', 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic'],
    'en16931' => ['EN16931', 'urn:cen.eu:en16931:2017'],
    'extended' => ['EXTENDED', 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended'],
];

/**
 * XRechnung: the validity periods of the editions, as published by KoSIT on
 * https://xeinkauf.de/xrechnung/versionen-und-bundles/ ( read on 09.10.2026 ).
 *
 * ⚠️ KoSIT WRITES "AB DEM" FOR 3.0.0, 3.0.1 AND 3.0.2 AND NO END. A bug-fix edition replaces its
 * predecessor, so the end of one edition is the day before the next one starts; that is the reading
 * this table encodes, and it is what makes "the invoice date picks exactly one edition" true inside one
 * customisation id. Across customisation ids the periods overlap ( 2.3.1 runs to 31.01.2024, 3.0.0 starts
 * on 01.08.2023 ), which is why the family is chosen by the customisation id FIRST and the date second.
 *
 * ⚠️ ONLY 3.0.2 IS CARRIED. The rule packages of 3.0.0 and 3.0.1 would need their own EN 16931 base
 * edition and KoSIT configuration, and nothing of that is measured yet. An invoice dated inside the period
 * of 3.0.0 or 3.0.1 is therefore checked with 3.0.2, and the result says so in its reason
 * ( `carried: false`, see Cascade::chooseByValidity() ).
 */
const XRECHNUNG_30 = [
    ['version' => '3.0.2', 'validFrom' => '2024-06-20', 'validUntil' => null, 'carried' => true],
    ['version' => '3.0.1', 'validFrom' => '2024-02-01', 'validUntil' => '2024-06-19', 'carried' => false],
    ['version' => '3.0.0', 'validFrom' => '2023-08-01', 'validUntil' => '2024-01-31', 'carried' => false],
];

/** XRechnung 3.0 in both syntaxes, Extension included; the customisation id is the family ( 2.x reads `xoev-de` ). */
const XR_MATCH = "exists(/*[(local-name() = 'Invoice' or local-name() = 'CreditNote') and contains(cbc:CustomizationID, 'xeinkauf.de:kosit:') and contains(cbc:CustomizationID, 'xrechnung_3.0')])"
    . " or exists(/rsm:CrossIndustryInvoice/rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID[contains(., 'xeinkauf.de:kosit:') and contains(., 'xrechnung_3.0')])";

/**
 * ZUGFeRD / Factur-X: the five profile ids that every edition from 2.1 on shares.
 *
 * ⚠️ `urn:cen.eu:en16931:2017` ALONE IS THE PROFILE EN 16931 ( COMFORT ) - and the id of every plain CEN
 * invoice in CII. Such an invoice takes the cascade as well: formally it is a ZUGFeRD EN 16931 invoice, and the
 * matrix of 24.09.2026 checked exactly these documents ( E01 to E25 ) against the seven editions.
 */
const ZF_MATCH = "exists(/rsm:CrossIndustryInvoice[normalize-space(rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID) = ('urn:factur-x.eu:1p0:minimum', 'urn:factur-x.eu:1p0:basicwl', 'urn:cen.eu:en16931:2017#compliant#urn:factur-x.eu:1p0:basic', 'urn:cen.eu:en16931:2017', 'urn:cen.eu:en16931:2017#conformant#urn:factur-x.eu:1p0:extended')])";

function profileOf(string $file): ?string
{
    $stem = strtoupper((string) preg_replace('/\.xslt$/i', '', basename($file)));

    // ⚠️ BASIC-WL before BASIC, see the head of this file.
    return match (true) {
        preg_match('/BASIC-?WL/', $stem) === 1 => 'basicwl',
        str_contains($stem, 'MINIMUM') => 'minimum',
        str_contains($stem, 'BASIC') => 'basic',
        str_contains($stem, 'EN16931') => 'en16931',
        str_contains($stem, 'EXTENDED') => 'extended',
        default => null,
    };
}

/**
 * @return array<string, array{xslt: string, codedb: string, xsd: list<string>, root: string}>
 */
function sourcesOf(string $edition): array
{
    $out = [];

    foreach (glob(PHIVE . "/schematron/$edition/*.xslt") ?: [] as $xslt) {
        $profile = profileOf($xslt);

        if ($profile === null) {
            throw new \RuntimeException("unknown profile in $xslt");
        }

        if (isset($out[$profile])) {
            throw new \RuntimeException("two files for $edition/$profile: {$out[$profile]['xslt']} and $xslt");
        }

        $codedb = (string) preg_replace('/\.xslt$/', '_codedb.xml', $xslt);

        if (!is_file($codedb)) {
            throw new \RuntimeException("no code database next to $xslt");
        }

        $xsd = glob(PHIVE . "/schemas/$edition/$profile/*.xsd") ?: [];
        // The root schema is the one without `_urn_` in its name; the others are its relative imports.
        $roots = array_values(array_filter($xsd, static fn (string $f): bool => !str_contains(basename($f), '_urn_')));

        if (count($roots) !== 1) {
            throw new \RuntimeException("expected one root XSD in schemas/$edition/$profile, found " . count($roots));
        }

        $out[$profile] = ['xslt' => $xslt, 'codedb' => $codedb, 'xsd' => $xsd, 'root' => basename($roots[0])];
    }

    return $out;
}

function copyInto(string $from, string $dir): string
{
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        throw new \RuntimeException("cannot create $dir");
    }

    $to = $dir . '/' . basename($from);

    if (!copy($from, $to)) {
        throw new \RuntimeException("cannot copy $from");
    }

    return $to;
}

/**
 * The scenario of one edition and profile, in the shape of `rulesets/scenarios.json`.
 *
 * ⚠️ ONE RULE SET PER SCENARIO, LIKE THE KoSIT CONFIGURATIONS OF THE MATRIX. The Factur-X Schematron of a
 * profile already contains the EN 16931 rules in the form that edition used; adding `EN16931-CII@1.3.16`
 * would check a 2.1 invoice against a CEN release that is newer than the edition, and the matrix would no
 * longer be the one of 24.09.2026.
 *
 * @return array<string, mixed>
 */
function scenario(string $edition, string $profile, string $root): array
{
    [$part, $urn] = PROFILES[$profile];

    return [
        'id' => "zugferd-$edition-$profile-cii",
        'name' => "ZUGFeRD $edition $part (CII)",
        // ⚠️ COMPILED HERE, AS IN rulesets/scenarios.json: VAMPIRE JS has no XPath parser ( it evaluates
        // compiled trees only ), and the PHP parser lives in devkit/ and is never shipped.
        'match' => Parser::parse("exists(/rsm:CrossIndustryInvoice[rsm:ExchangedDocumentContext/ram:GuidelineSpecifiedDocumentContextParameter/ram:ID/text() = '$urn'])")->toArray(),
        'namespaces' => [
            'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
            'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
        ],
        'rulesetRefs' => ["ZUGFERD-$part-CII@$edition"],
        'xsdPath' => "rulesets/versions/xsd/zugferd-$edition/$profile/$root",
        // ⚠️ The structure model of VAMPIRE JS for the same XSD ( build-vampire-addon.mjs --versions compiles it ).
        'schema' => "zugferd-$edition-$profile",
        'schemaEntry' => null,
        'validFrom' => null,
        'validUntil' => null,
        'customLevels' => new \stdClass(),
    ];
}

function main(): int
{
    $argv = $GLOBALS['argv'] ?? [];
    $apply = in_array('--apply', $argv, true);
    $only = null;

    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--only=')) {
            $only = explode(',', substr($arg, 7));
        }

        // Writes cascade.json only; the rule packages stay as they are ( the import of 2.1 and 2.2 takes minutes ).
        if ($arg === '--table-only') {
            $only = [];
        }
    }

    $base = dirname(__DIR__, 2);
    $stage = "$base/vendor-sources/zugferd-versions";
    $out = "$base/rulesets/versions";
    $provenance = new RuleProvenance(RuleProvenance::defaultTable());

    $editions = [];

    foreach (EDITIONS as $edition) {
        $sources = sourcesOf($edition);
        $sorted = [];

        foreach (array_keys(PROFILES) as $p) {
            if (!isset($sources[$p])) {
                throw new \RuntimeException("edition $edition has no profile $p");
            }

            $sorted[$p] = $sources[$p];
        }

        $editions[$edition] = $sorted;
        printf("%-6s %s\n", $edition, implode(', ', array_map(static fn ($s) => basename($s['xslt']), $sorted)));
    }

    if (!$apply) {
        echo "\nTrockenlauf. Mit --apply bauen.\n";
        return 0;
    }

    foreach (['', '/codedb', '/xsd'] as $sub) {
        if (!is_dir($out . $sub) && !mkdir($out . $sub, 0777, true)) {
            throw new \RuntimeException("cannot create $out$sub");
        }
    }

    $family = [];

    foreach ($editions as $edition => $profiles) {
        $scenarios = [];

        foreach ($profiles as $profile => $src) {
            [$part] = PROFILES[$profile];
            $scenarios[] = scenario($edition, $profile, $src['root']);

            if ($only !== null && !in_array($edition, $only, true)) {
                continue;
            }

            $dir = "$stage/$edition/$profile";
            $xslt = copyInto($src['xslt'], $dir);
            $codedb = copyInto($src['codedb'], $dir);

            foreach ($src['xsd'] as $xsd) {
                copyInto($xsd, $dir);
                copyInto($xsd, "$out/xsd/zugferd-$edition/$profile");
            }

            $id = "ZUGFERD-$part-CII";
            $started = microtime(true);
            $ruleset = $provenance->stamp(SchematronXsltImporter::import($xslt, $id, $edition));

            // ⚠️ The rule logic stays as upstream wrote it, the node comparison `is` of 2.4 and 2.5 EXTENDED
            // included ( `not($other is .)` ): both interpreters evaluate it. The only rewrite is the file
            // name passed to fn:document() just below, and the NOTICE names exactly that one
            // ( rule-provenance.json, entry `vendor-sources/zugferd-versions/` ).
            $json = RulesetJsonCodec::encode($ruleset);

            $newName = "zugferd-$edition-{$profile}_codedb.xml";
            $oldName = basename($src['codedb']);
            $count = 0;

            // ⚠️ Only the literal argument of fn:document() is rewritten, recognised by its position in
            // the tree, never a message text that happens to contain the file name.
            $json = (string) preg_replace_callback(
                '/("local":"document"\},"args":\[\{"node":"Literal","pos":\d+,"kind":"string","value":")([^"]+)(")/',
                static function (array $m) use ($oldName, $newName, &$count): string {
                    if ($m[2] !== $oldName) {
                        throw new \RuntimeException("fn:document('{$m[2]}') does not name the code database $oldName");
                    }

                    $count++;

                    return $m[1] . $newName . $m[3];
                },
                $json
            );

            // Read back once: what the engine loads must decode.
            RulesetJsonCodec::decode($json);

            file_put_contents("$out/$id@$edition.json", $json);
            copy($codedb, "$out/codedb/$newName");

            $rules = 0;
            $asserts = 0;

            foreach ($ruleset->patterns as $pattern) {
                $rules += count($pattern->rules);

                foreach ($pattern->rules as $rule) {
                    $asserts += count($rule->assertions);
                }
            }

            printf(
                "  %s@%s: %d rules, %d assertions, %d document() rewritten, %.1f MB, %.1f s%s\n",
                $id,
                $edition,
                $rules,
                $asserts,
                $count,
                strlen($json) / 1048576,
                microtime(true) - $started,
                $ruleset->license === null ? '  ⚠️ no provenance entry' : ''
            );
        }

        $family[] = ['version' => $edition, 'carried' => true, 'scenarios' => $scenarios];
    }

    $cascade = [
        '_comment' => [
            'The cascade table of VAMPIRE PHP and VAMPIRE JS. Written by devkit/Cli/import-zugferd-versions.php - do not edit by hand.',
            'families are tried in this order; the first whose `match` holds takes the invoice. `order` is `newest-first` ( try every carried edition from the top, the first that accepts is the result, otherwise the newest ) or `validity` ( the invoice date picks exactly one edition ).',
            'An edition with `carried: false` exists for the resolution only: its period is known, its rule package is not part of this package.',
            'scenarios of an edition have the shape of rulesets/scenarios.json; a family without own scenarios ( `scenarios: null` ) uses those of rulesets/scenarios.json.',
        ],
        'cascadeVersion' => 1,
        'families' => [
            [
                'id' => 'xrechnung-3.0',
                'title' => 'XRechnung 3.0',
                'order' => 'validity',
                // ⚠️ The customisation id names the edition family ( 2.x xoev, 3.x xeinkauf, versions data package ).
                // ⚠️ `contains`, not `starts-with`: the Extension writes `#conformant#…:kosit:extension:xrechnung_3.0`.
                'matchText' => XR_MATCH,
                'match' => Parser::parse(XR_MATCH)->toArray(),
                'namespaces' => [
                    'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
                    'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
                    'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
                ],
                'source' => 'https://xeinkauf.de/xrechnung/versionen-und-bundles/ ( read 2026-10-09 )',
                'versions' => array_map(static fn (array $v): array => $v + ['scenarios' => null], XRECHNUNG_30),
            ],
            [
                'id' => 'zugferd',
                'title' => 'ZUGFeRD / Factur-X',
                'order' => 'newest-first',
                'matchText' => ZF_MATCH,
                'match' => Parser::parse(ZF_MATCH)->toArray(),
                'namespaces' => [
                    'rsm' => 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100',
                    'ram' => 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100',
                ],
                'source' => 'measured against the KoSIT validator on 24.09.2026; rule packages from phive-rules-zugferd',
                'versions' => $family,
            ],
        ],
    ];

    file_put_contents("$out/cascade.json", json_encode($cascade, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    echo "\nwrote $out/cascade.json\n";

    return 0;
}

exit(main());
