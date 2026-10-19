<?php

// SPDX-License-Identifier: GPL-2.0-or-later

/**
 * Puts together what a CONSUMER needs at runtime, and nothing else.
 *
 *   php devkit/Cli/export-runtime.php <zielverzeichnis>                    all rule packages
 *   php devkit/Cli/export-runtime.php <zielverzeichnis> --rulesets=a,b,c   only these
 *   php devkit/Cli/export-runtime.php <zielverzeichnis> --core             the eight core packages
 *   … --without=peppol             without the group peppol ( `separable` in rule-provenance.json )
 *   … --trial-open-licences        ⚠️ for measuring only: lets open licences and missing licence texts through
 *
 * Copied: `src/`, the chosen `rulesets/<ref>.json`, the code lists, the XSDs the scenarios with all their
 * rule sets actually reference, `LICENSE` and `composer.json`. Written: `NOTICE` and `licences/` from
 * `vampire-php/rule-provenance.json`. NOT copied: `devkit/`, `tests/`, `vendor/`,
 * `vendor-sources/`, the documentation - a consumer validates, it does not import rule sets.
 *
 * ⚠️ **EVERY SCENARIO IS KEPT, ALSO WHEN ITS RULE SET IS NOT EXPORTED.** Dropping such scenarios keeps a
 * missing file from turning into an internal error, but the price was measured on 09.10.2026: in an export
 * without Peppol, all twelve Peppol invoices of the corpus were reported as "Kein Szenario passt" and
 * rejected, nine of them valid. `Validator::runPipeline()` answers a scenario without its rule sets with
 * "Regelwerk nicht enthalten" and no verdict, as VAMPIRE JS does, and the first-match resolution stays the
 * same as in the full package. The scenarios without their rule sets are listed on stdout.
 *
 * ⚠️ **`--without=peppol` IS THE SWITCH FOR A REFUSAL BY OPENPEPPOL.** It drops every rule set whose
 * provenance entry names the group, also the CEN copy that only the Peppol scenarios use. Which rule sets
 * belong to a group stands in the table, not here.
 *
 * ⚠️ **THE LICENCE GATE RUNS BEFORE ANYTHING IS WRITTEN.** A rule set without an entry, with status 'open'
 * or with a licence text missing from `vampire-php/licences/` stops the export.
 */

declare(strict_types=1);

namespace EinvoiceValidator\Devkit\Cli;

use EinvoiceValidator\Devkit\Licence\RuleProvenance;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** The scope of a first release: DE, EU-Peppol and the two ZUGFeRD profiles. */
const CORE_RULESETS = [
    'EN16931-UBL@1.3.16',
    'EN16931-CII@1.3.16',
    'XRechnung-UBL@3.0.2',
    'XRechnung-CII@3.0.2',
    'CEN-EN16931-UBL@3.0.21',
    'PEPPOL-EN16931-UBL@3.0.21',
    'FACTURX-EN16931-CII@1.09.2',
    'FACTURX-EXTENDED-CII@1.09.2',
];

function copy_tree_into(string $from, string $to): int
{
    if (!is_dir($to) && !mkdir($to, 0777, true)) {
        fwrite(STDERR, "cannot create $to\n");
        exit(1);
    }

    $count = 0;
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $target = $to . '/' . $items->getSubPathName();

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0777, true)) {
                fwrite(STDERR, "cannot create $target\n");
                exit(1);
            }

            continue;
        }

        copy($item->getPathname(), $target);
        $count++;
    }

    return $count;
}

$base = dirname(__DIR__, 2);
// ⚠️ `$argv` exists only with `register_argc_argv` switched on - the CLI default, but not every
// configuration's. Read from `$_SERVER` it is the same list, with a type static analysis can
// follow.
$argv = array_map('strval', (array) ($_SERVER['argv'] ?? []));

$args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));

if ($args === []) {
    fwrite(STDERR, "usage: php devkit/Cli/export-runtime.php <zielverzeichnis> [--core|--rulesets=a,b]\n");
    exit(1);
}

$target = rtrim(str_replace('\\', '/', $args[0]), '/');
$wanted = null;
$without = [];
$trialAllowed = in_array('--trial-open-licences', $argv, true);

foreach ($argv as $arg) {
    if ($arg === '--core') {
        $wanted = CORE_RULESETS;
    }

    if (str_starts_with($arg, '--rulesets=')) {
        $wanted = explode(',', substr($arg, 11));
    }

    if (str_starts_with($arg, '--without=')) {
        $without = array_values(array_filter(array_map('trim', explode(',', substr($arg, 10)))));
    }
}

$available = [];

foreach (glob($base . '/rulesets/*@*.json') ?: [] as $file) {
    $available[] = basename($file, '.json');
}

$wanted ??= $available;
$missing = array_diff($wanted, $available);

if ($missing !== []) {
    fwrite(STDERR, 'Nicht vorhanden: ' . implode(', ', $missing) . "\n");
    exit(1);
}

// --- Provenance and licence, BEFORE anything is written ---------------------------------------------

$prov = new RuleProvenance(RuleProvenance::defaultTable());
$heads = [];
$droppedRefs = [];
/** @var array<string, true> $droppedGroups */
$droppedGroups = [];

foreach ($wanted as $ref) {
    $head = RuleProvenance::readHead($base . "/rulesets/$ref.json");
    $entry = $prov->requireEntry($head);

    if ($without !== [] && in_array($entry['separable'] ?? null, $without, true)) {
        $droppedRefs[] = $ref;
        $droppedGroups[(string) $entry['separable']] = true;
        continue;
    }

    // ⚠️ A pending entry must be droppable by one switch; that is the condition of shipping it at all.
    if (($entry['status'] ?? '') === 'pending' && empty($entry['separable'])) {
        fwrite(STDERR, "Herkunft für $ref steht auf pending, nennt aber keine Gruppe ( separable ).\n");
        exit(1);
    }

    $heads[$ref] = $head;
}

$wanted = array_keys($heads);

/*
 * `--versions` takes the edition packages of the version cascade along ( rulesets/versions/, written by
 * import-zugferd-versions.php ). ⚠️ THROUGH THE SAME LICENCE GATE AND INTO THE SAME NOTICE as the default rule sets:
 * 35 rule packages on a second, unchecked path would be exactly what the licence rule forbids.
 */
$withVersions = in_array('--versions', $argv, true);
$versionHeads = [];

if ($withVersions) {
    if (!is_file($base . '/rulesets/versions/cascade.json')) {
        fwrite(STDERR, "--versions: rulesets/versions/cascade.json fehlt - erst import-zugferd-versions.php --apply.\n");
        exit(1);
    }

    foreach (glob($base . '/rulesets/versions/*@*.json') ?: [] as $file) {
        $head = RuleProvenance::readHead($file);
        $prov->requireEntry($head);
        $versionHeads[basename($file, '.json')] = $head;
    }
}

$state = $prov->check(array_merge(array_values($heads), array_values($versionHeads)));
$trial = array_merge(
    array_map(static fn (string $r): string => 'Open licence question: ' . $r . '.', $state['open']),
    array_map(static fn (string $t): string => 'Licence text missing from the build machine: ' . $t . '.', $state['missing']),
);

if ($trial !== [] && !$trialAllowed) {
    fwrite(STDERR, "Export angehalten, es wurde nichts geschrieben:\n  " . implode("\n  ", $trial)
        . "\n  Lizenztexte gehören nach " . $prov->licenceDir() . "\n");
    exit(1);
}

$dropped = array_keys($droppedGroups);

// --- src, Lizenz, Herkunft ------------------------------------------------------------------------

$files = copy_tree_into($base . '/src', $target . '/src');

copy($base . '/LICENSE', $target . '/LICENSE');
// The package description, so that a consumer's vendor/ folder holds a recognisable Composer package.
copy($base . '/composer.json', $target . '/composer.json');
$files++;

file_put_contents($target . '/NOTICE', $prov->noticeText(
    'einvoice-validator ( VAMPIRE PHP ), runtime export',
    array_merge(array_values($heads), array_values($versionHeads)),
    $dropped,
    $trial,
    (string) file_get_contents(dirname(__DIR__) . '/Licence/notice-tail.txt')
));
$files++;

if (!is_dir($target . '/licences') && !mkdir($target . '/licences', 0777, true)) {
    fwrite(STDERR, "cannot create $target/licences\n");
    exit(1);
}

// Apache-2.0 also for the Factur-X code lists and the KoSIT scenarios ( notice-tail.txt, items A and B ).
foreach (array_unique(array_merge(array_diff($state['needed'], $state['missing']), is_file($prov->licenceDir() . '/Apache-2.0.txt') ? ['Apache-2.0.txt'] : [])) as $text) {
    copy($prov->licenceDir() . '/' . $text, $target . '/licences/' . $text);
    $files++;
}

// --- Regelpakete ----------------------------------------------------------------------------------

if (!is_dir($target . '/rulesets') && !mkdir($target . '/rulesets', 0777, true)) {
    fwrite(STDERR, "cannot create $target/rulesets\n");
    exit(1);
}

/**
 * ⚠️ THE AT SIGN IS DROPPED IN THE DELIVERY. `EN16931-UBL@1.3.16.json` becomes
 * `EN16931-UBL-1.3.16.json`.
 *
 * Reason: the delivery lands in a WordPress plugin, and WordPress' Plugin Check rejects special
 * characters in file names (`badly_named_files`, measured eight times on 22.09.2026); some hosts and
 * security scanners dislike an `@` in a path just as much.
 *
 * ⚠️ `scenarios.json` is NOT rewritten. The reference stays `EN16931-UBL@1.3.16` everywhere - it is the
 * identifier of the rule package, not a file name. `Validator::resolveRulesetFile()` resolves it and
 * knows both spellings. Changing the reference here as well would drag profiles, reports and the parity
 * table along.
 */
$collisions = [];

foreach ($wanted as $ref) {
    $name = str_replace('@', '-', $ref);

    if (isset($collisions[$name])) {
        fwrite(STDERR, "Namenskollision nach dem Ersetzen: $ref und {$collisions[$name]} ergeben beide $name.json\n");
        exit(1);
    }

    $collisions[$name] = $ref;

    /*
     * ⚠️ WRITE COMPACTLY, DO NOT COPY. The delivery is meant as compact JSON ( "compact instead of
     * indented, 118.5 → 18.6 MB" ), and copying the source file only works as long as the source itself
     * is compact. A re-import on 07.10.2026 wrote the packages under `rulesets/` indented, and an export
     * of the eight core packages that copied them grew from about 11 to 61.4 MB - measured on a trial
     * export. In content both versions are the same ( `json_decode()` of both files gives the same
     * array, measured on all eight packages ).
     *
     * ⚠️ `JSON_PRESERVE_ZERO_FRACTION` keeps a number like `1.0` as `1.0`; without the flag a float would
     * silently become an integer.
     */
    $decoded = json_decode((string) file_get_contents($base . "/rulesets/$ref.json"), true, 512, JSON_THROW_ON_ERROR);

    /*
     * ⚠️ LICENCE AND VISIBLE NOTICE FROM THE TABLE, WRITTEN INTO THE HEAD. The rule set in the work tree may
     * carry an older statement, or none if it was imported without a table entry; the table is the one
     * source. The two keys go right after `title`, where `Ruleset::toArray()` writes them and where
     * `build-release.php` reads the head.
     */
    $entry = $prov->requireEntry($heads[$ref]);
    $stamped = [];

    foreach ($decoded as $key => $value) {
        if ($key === 'license' || $key === 'notice') {
            continue;
        }

        $stamped[$key] = $value;

        if ($key === 'title') {
            $stamped['license'] = (string) ($entry['packageLicence'] ?? 'NOASSERTION');

            if (isset($entry['visibleNotice']) && is_array($entry['visibleNotice'])) {
                $stamped['notice'] = $entry['visibleNotice'];
            }
        }
    }

    $decoded = $stamped;

    file_put_contents(
        $target . "/rulesets/$name.json",
        json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
    );
    $files++;
}

$files += copy_tree_into($base . '/rulesets/codedb', $target . '/rulesets/codedb');

if ($withVersions) {
    // ⚠️ The same spelling rule as above: `@` becomes `-` in the file name, the reference in cascade.json stays.
    foreach ($versionHeads as $ref => $head) {
        $entry = $prov->requireEntry($head);
        $decoded = json_decode((string) file_get_contents($base . "/rulesets/versions/$ref.json"), true, 512, JSON_THROW_ON_ERROR);
        $decoded['license'] = (string) ($entry['packageLicence'] ?? 'NOASSERTION');

        if (!is_dir($target . '/rulesets/versions') && !mkdir($target . '/rulesets/versions', 0777, true)) {
            fwrite(STDERR, "cannot create $target/rulesets/versions\n");
            exit(1);
        }

        file_put_contents($target . '/rulesets/versions/' . str_replace('@', '-', $ref) . '.json', json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $files++;
    }

    $files += copy_tree_into($base . '/rulesets/versions/codedb', $target . '/rulesets/versions/codedb');
    $files += copy_tree_into($base . '/rulesets/versions/xsd', $target . '/rulesets/versions/xsd');
    copy($base . '/rulesets/versions/cascade.json', $target . '/rulesets/versions/cascade.json');
    $files++;

    fwrite(STDOUT, sprintf("  --versions: %d Regelpakete je Fassung mit Codelisten, Schemata und cascade.json\n", count($versionHeads)));
}

// --- Szenarien filtern ----------------------------------------------------------------------------

$scenarios = json_decode((string) file_get_contents($base . '/rulesets/scenarios.json'), true);

if (!is_array($scenarios)) {
    fwrite(STDERR, "scenarios.json nicht lesbar\n");
    exit(1);
}

$kept = [];
$neededXsd = [];

$runnable = 0;
$notIncluded = [];

foreach ($scenarios as $scenario) {
    /** @var array{name?: string, rulesetRefs?: list<string>, xsdPath?: ?string} $scenario */
    $refs = $scenario['rulesetRefs'] ?? [];
    $absent = array_diff($refs, $wanted);

    // ⚠️ Kept all the same ( see the head of this file ); only its XSD is not needed, because it never runs.
    $kept[] = $scenario;

    if ($absent !== []) {
        $notIncluded[(string) ($scenario['name'] ?? '?')] = implode(', ', $absent);
        continue;
    }

    $runnable++;

    if (isset($scenario['xsdPath'])) {
        $neededXsd[$scenario['xsdPath']] = true;
    }
}

if ($runnable === 0) {
    fwrite(STDERR, "Kein Szenario lauffähig - die gewählten Regelpakete ergeben keine brauchbare Konfiguration.\n");
    exit(1);
}

file_put_contents(
    $target . '/rulesets/scenarios.json',
    json_encode($kept, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);
$files++;

// --- XSDs -----------------------------------------------------------------------------------------
//
// ⚠️ Whole XSD directories, not just the files a scenario names: an XSD pulls in its own imports
// (UBL's maindoc schema alone imports a dozen common modules), and copying only the entry point
// produces a package that fails on the first validation with a libxml "failed to load external
// entity" - after the download, on the customer's machine.

$xsdRoots = [];

foreach (array_keys($neededXsd) as $path) {
    $parts = explode('/', trim($path, '/'));

    // rulesets/xsd/<familie>/... -> die Familie als Ganzes
    if (count($parts) >= 3 && $parts[0] === 'rulesets' && $parts[1] === 'xsd') {
        $xsdRoots[$parts[2]] = true;
    }
}

foreach (array_keys($xsdRoots) as $family) {
    $files += copy_tree_into($base . '/rulesets/xsd/' . $family, $target . '/rulesets/xsd/' . $family);
}

// --- Bericht ---------------------------------------------------------------------------------------

$size = 0;

foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($target)) as $file) {
    if ($file->isFile()) {
        $size += $file->getSize();
    }
}

printf(
    "%d Dateien, %.1f MB nach %s\n  %d Regelpakete, %d Szenarien ( %d lauffähig ), XSD-Familien: %s\n",
    $files,
    $size / 1048576,
    $target,
    count($wanted),
    count($kept),
    $runnable,
    implode(', ', array_keys($xsdRoots))
);

if ($droppedRefs !== []) {
    echo "\nOhne die Gruppe(n) " . implode(', ', $dropped) . ': ' . implode(', ', $droppedRefs) . "\n";
}

if ($notIncluded !== []) {
    echo "\nSzenarien ohne ihr Regelpaket ( melden \"Regelwerk nicht enthalten\", kein Urteil ):\n";

    foreach ($notIncluded as $name => $refs) {
        printf("  %-42s braucht %s\n", $name, $refs);
    }
}

if ($trial !== []) {
    echo "\nPROBE, NICHT WEITERGEBEN: " . implode(' ', $trial) . "\n";
}
